<?php

declare(strict_types=1);

namespace EduCloud\Tests;

use EduCloud\Core\App;
use EduCloud\Core\Config;
use EduCloud\Core\Kernel;
use EduCloud\Core\Request;
use EduCloud\Core\Response;
use EduCloud\Core\Ulid;
use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * Base test case. Integration tests use the educloud_test database (never the real one)
 * and a throwaway storage directory under the system temp dir.
 */
abstract class TestCase extends BaseTestCase
{
    private static ?Config $baseConfig = null;
    protected ?App $app = null;
    protected string $storagePath = '';

    protected function tearDown(): void
    {
        if ($this->app !== null) {
            $this->app->db()->close();
        }
        if ($this->storagePath !== '' && is_dir($this->storagePath)) {
            $this->removeDir($this->storagePath);
        }
        parent::tearDown();
    }

    protected function config(): Config
    {
        self::$baseConfig ??= Config::load(dirname(__DIR__));
        return self::$baseConfig;
    }

    protected function app(): App
    {
        if ($this->app === null) {
            $this->storagePath = str_replace('\\', '/', sys_get_temp_dir()) . '/educloud-test-' . Ulid::generate();
            mkdir($this->storagePath, 0700, true);
            $config = $this->config()
                ->with('app.storage_path', $this->storagePath)
                ->with('app.debug', false)
                ->with('app.env', 'testing');
            $this->app = App::create($config, testDatabase: true);
        }
        return $this->app;
    }

    /**
     * Dispatches a request through the full kernel (routing, middleware, error handling) in-process.
     *
     * @param array<string, mixed>  $json
     * @param array<string, string> $headers
     */
    protected function request(string $method, string $path, ?array $json = null, array $headers = []): Response
    {
        $body = '';
        if ($json !== null) {
            $body = (string) json_encode($json);
            $headers += ['content-type' => 'application/json'];
        }
        $query = [];
        if (str_contains($path, '?')) {
            [$path, $qs] = explode('?', $path, 2);
            parse_str($qs, $query);
        }
        $request = new Request(
            method: $method,
            path: $path,
            query: $query,
            headers: array_change_key_case($headers, CASE_LOWER),
            cookies: $this->cookieJar,
            body: $body,
            ip: $this->clientIp,
            requestId: Ulid::generate(),
        );
        $response = (new Kernel($this->app()))->handle($request);

        // Browser-like cookie jar: apply Set-Cookie (deletion via Max-Age=0).
        foreach ($response->cookies as $raw) {
            [$pair] = explode(';', $raw, 2);
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            if (str_contains($raw, 'Max-Age=0')) {
                unset($this->cookieJar[rawurldecode($name)]);
            } else {
                $this->cookieJar[rawurldecode($name)] = rawurldecode($value);
            }
        }
        return $response;
    }

    /** @var array<string, string> */
    protected array $cookieJar = [];
    protected string $clientIp = '127.0.0.1';

    /** Loads an HTML page and returns its CSRF meta token (also stores the anonymous CSRF cookie). */
    protected function csrfFromPage(string $path = '/login'): string
    {
        $html = $this->request('GET', $path)->body;
        self::assertSame(1, preg_match('/<meta name="csrf-token" content="([^"]*)"/', $html, $m), 'No CSRF meta tag on ' . $path);
        return html_entity_decode($m[1]);
    }

    /**
     * Empties every application table of educloud_test (refuses to run on any other database).
     */
    protected function resetDatabase(): void
    {
        $db = $this->app()->db();
        self::assertSame('educloud_test', $db->scalar('SELECT DATABASE()'), 'resetDatabase() only runs on educloud_test');
        $tables = $db->select(
            "SELECT table_name AS t FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' AND table_name <> 'schema_migrations'"
        );
        $db->execute('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($tables as $row) {
            $db->execute('TRUNCATE TABLE `' . str_replace('`', '', (string) $row['t']) . '`');
        }
        $db->execute('SET FOREIGN_KEY_CHECKS = 1');
    }

    /** @return list<string> lines of the test run's log */
    protected function logLines(): array
    {
        $lines = [];
        foreach (glob($this->storagePath . '/logs/*.log') ?: [] as $file) {
            $lines = array_merge($lines, file($file, FILE_IGNORE_NEW_LINES) ?: []);
        }
        return $lines;
    }

    private function removeDir(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
