<?php

/**
 * Platform administrators (they see every tenant in the admin monitor and pass every permission check).
 *
 *   php scripts/admin.php grant user@example.com
 *   php scripts/admin.php revoke user@example.com
 *   php scripts/admin.php list
 *
 * The user must exist, be active and have a verified email. Grants and revocations are audited and end the
 * user's sessions, so the new privileges apply from the next login.
 */

declare(strict_types=1);

use EduCloud\Core\Request;
use EduCloud\Core\Ulid;
use EduCloud\Modules\Auth\SessionRepository;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/** @var \EduCloud\Core\App $app */
$app = require dirname(__DIR__) . '/app/bootstrap.php';
$db = $app->db();
$args = array_slice($argv, 1);
$cli = new Request(method: 'CLI', path: '/scripts/admin.php', ip: '127.0.0.1', requestId: Ulid::generate());

$fail = static function (string $message): never {
    fwrite(STDERR, $message . "\n");
    exit(1);
};

$command = $args[0] ?? '';
if ($command === 'list') {
    foreach ($db->select('SELECT email, display_name, status FROM users WHERE is_platform_admin = 1 ORDER BY email') as $row) {
        echo $row['email'] . '  ' . $row['status'] . '  ' . $row['display_name'] . "\n";
    }
    exit(0);
}
if (!in_array($command, ['grant', 'revoke'], true) || count($args) !== 2) {
    $fail("usage:\n  php scripts/admin.php grant|revoke <email>\n  php scripts/admin.php list");
}
$user = $db->selectOne(
    "SELECT id, public_id FROM users WHERE email = ? AND status = 'active' AND email_verified_at IS NOT NULL",
    [strtolower(trim($args[1]))]
);
if ($user === null) {
    $fail('No active, verified user with that email');
}
$db->execute('UPDATE users SET is_platform_admin = ? WHERE id = ?', [$command === 'grant' ? 1 : 0, (int) $user['id']]);
(new SessionRepository($db))->deleteAllForUser((int) $user['id']);
$app->audit()->record($cli, 'admin.' . $command, 'success', null, (int) $user['id'], 'user', (string) $user['public_id'], ['via' => 'cli']);
echo ($command === 'grant' ? 'granted' : 'revoked') . " platform admin: {$args[1]}\n";
