<?php

/**
 * Release smoke test (M12), run against an INSTALLED release archive served at $base. It walks the real flows of a
 * fresh install through HTTP only, plus the CLI workers an operator would run:
 *   health → public pages → register (browser CSRF) → email (file driver) → verify → API token →
 *   workspace + lakehouse → upload CSV → profile → ingest → SQL query → result; then admin health and /metrics.
 * Usage: php ci/smoke-release.php <base_url> <release_dir> <metrics_token>
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli' || $argc !== 4) {
    fwrite(STDERR, "usage: php ci/smoke-release.php <base_url> <release_dir> <metrics_token>\n");
    exit(2);
}
[, $base, $dir, $metricsToken] = $argv;
$base = rtrim($base, '/');
$jar = tempnam(sys_get_temp_dir(), 'jar');
$step = 0;

function check(bool $ok, string $what): void
{
    global $step;
    $step++;
    echo ($ok ? 'ok  ' : 'FAIL') . " $step. $what\n";
    if (!$ok) {
        exit(1);
    }
}

/** @return array{status: int, body: string, json: mixed} */
function http(string $method, string $path, mixed $body = null, array $headers = [], bool $cookies = false): array
{
    global $base, $jar;
    $ch = curl_init($base . $path);
    $h = ['Accept: application/json'];
    foreach ($headers as $k => $v) {
        $h[] = "$k: $v";
    }
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30]);
    if (is_array($body) && isset($body['__multipart'])) {
        unset($body['__multipart']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    } elseif ($body !== null) {
        $h[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    if ($cookies) {
        curl_setopt_array($ch, [CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]);
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $h);
    $raw = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return ['status' => $status, 'body' => $raw, 'json' => json_decode($raw, true)];
}

function csrfFrom(string $path): string
{
    $page = http('GET', $path, null, ['Accept' => 'text/html'], true);
    preg_match('/name="csrf-token" content="([^"]+)"/', $page['body'], $m);
    return $m[1] ?? '';
}

function cli(string ...$args): void
{
    global $dir;
    $proc = proc_open(array_merge([PHP_BINARY], $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $dir);
    stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    proc_close($proc);
}

$health = http('GET', '/api/v1/health');
check($health['status'] === 200 && ($health['json']['data']['status'] ?? '') === 'ok', 'GET /api/v1/health → 200 ok');
check(http('GET', '/', null, ['Accept' => 'text/html'])['status'] === 200, 'home page renders');
$labs = http('GET', '/labs', null, ['Accept' => 'text/html']);
check($labs['status'] === 200 && str_contains($labs['body'], 'Proyecto final'), 'public lab catalog lists the imported labs (LAB-010)');
check(http('GET', '/.env')['status'] === 404, '/.env is not served');

$email = 'smoke-' . bin2hex(random_bytes(4)) . '@test.example';
$password = 'una frase larga para el smoke test';
$reg = http('POST', '/api/v1/auth/register', ['email' => $email, 'password' => $password, 'display_name' => 'Smoke'], ['X-CSRF-Token' => csrfFrom('/register')], true);
check($reg['status'] === 202, 'register (browser flow with CSRF) → 202');

cli('scripts/mailer.php', '--once');
$token = '';
foreach (glob(rtrim((string) getenv('CI_STORAGE'), '/') . '/mail/*.eml') ?: [] as $file) {
    $raw = quoted_printable_decode((string) file_get_contents($file));
    if (str_contains($raw, $email) && preg_match('/verify-email\?token=([A-Za-z0-9_-]+)/', $raw, $m) === 1) {
        $token = $m[1];
    }
}
check($token !== '', 'verification email delivered by the mailer (file driver)');
$verify = http('POST', '/api/v1/auth/verify-email', ['token' => $token, 'password' => $password], ['X-CSRF-Token' => csrfFrom('/verify-email?token=' . $token)], true);
check($verify['status'] === 200, 'email verified');

$tokens = http('POST', '/api/v1/auth/tokens', ['email' => $email, 'password' => $password]);
$access = (string) ($tokens['json']['data']['access_token'] ?? '');
check($tokens['status'] === 201 && $access !== '', 'API token issued');
$auth = ['Authorization' => 'Bearer ' . $access];

$ws = http('POST', '/api/v1/workspaces', ['name' => 'Smoke'], $auth);
$wsId = (string) ($ws['json']['data']['id'] ?? '');
check($ws['status'] === 201 && $wsId !== '', 'workspace created');
check(http('POST', "/api/v1/workspaces/$wsId/resources", ['type' => 'lakehouse', 'name' => 'lago'], $auth)['status'] === 201, 'lakehouse resource created');

$csv = tempnam(sys_get_temp_dir(), 'csv');
file_put_contents($csv, "region,ventas\nNorte,10\nSur,32\n");
$up = http('POST', "/api/v1/workspaces/$wsId/datasets", ['__multipart' => true, 'name' => 'ventas', 'file' => new CURLFile($csv, 'text/csv', 'ventas.csv')], $auth);
$rawId = (string) ($up['json']['data']['dataset']['id'] ?? '');
check($up['status'] === 202 && $rawId !== '', 'CSV uploaded (202, profile job queued)');
cli('scripts/dispatcher.php', '--once');
check(http('POST', "/api/v1/datasets/$rawId/ingest", ['table_name' => 'ventas'], $auth)['status'] === 202, 'ingest to bronze queued');
cli('scripts/dispatcher.php', '--once');

$q = http('POST', "/api/v1/workspaces/$wsId/queries", ['sql' => 'SELECT sum(ventas) AS total FROM bronze.ventas'], $auth);
$qid = (string) ($q['json']['data']['id'] ?? '');
check($q['status'] === 202 && $qid !== '', 'SQL query queued');
cli('scripts/dispatcher.php', '--once');
$result = http('GET', "/api/v1/queries/$qid", null, $auth);
$rows = $result['json']['data']['result']['rows'] ?? null;
check(($result['json']['data']['status'] ?? '') === 'succeeded' && $rows === [[42]], 'query ran in the Python/DuckDB runner: total = 42');

cli('scripts/scheduler.php');
cli('scripts/admin.php', 'grant', $email);
$tokens = http('POST', '/api/v1/auth/tokens', ['email' => $email, 'password' => $password]);
$admin = ['Authorization' => 'Bearer ' . (string) ($tokens['json']['data']['access_token'] ?? '')];
$adminHealth = http('GET', '/api/v1/admin/health', null, $admin);
$components = array_column($adminHealth['json']['data']['components'] ?? [], 'status', 'name');
check($adminHealth['status'] === 200 && ($components['database'] ?? '') === 'ok' && ($components['runner'] ?? '') === 'ok'
    && ($components['scheduler'] ?? '') === 'ok', 'admin health: database, runner and scheduler ok '
    . json_encode(['status' => $adminHealth['status'], 'components' => $components, 'error' => $adminHealth['json']['error']['code'] ?? null]));

check(http('GET', '/metrics')['status'] === 401, '/metrics requires its token');
$metrics = http('GET', '/metrics', null, ['Authorization' => 'Bearer ' . $metricsToken]);
check($metrics['status'] === 200 && str_contains($metrics['body'], 'educloud_component_up{component="database"} 1'), '/metrics with the token → Prometheus gauges');

echo "SMOKE OK\n";
