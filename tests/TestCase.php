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
        $request = new Request(
            method: $method,
            path: $path,
            headers: array_change_key_case($headers, CASE_LOWER),
            body: $body,
            requestId: Ulid::generate(),
        );
        return (new Kernel($this->app()))->handle($request);
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
