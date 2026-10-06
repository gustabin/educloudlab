<?php

declare(strict_types=1);

namespace EduCloud\Tests\Security;

use EduCloud\Tests\TestCase;
use RuntimeException;

/**
 * Internal failure details must never reach clients (spec §10, §12), but must reach the log.
 */
final class ErrorDisclosureTest extends TestCase
{
    private const SECRET = "SQLSTATE[42S02] Table 'educloud.secret' C:\\xampp\\htdocs\\EduCloud Lab\\app\\x.php password=hunter2";

    protected function setUp(): void
    {
        parent::setUp();
        $fail = static function (): never {
            throw new RuntimeException(self::SECRET);
        };
        $this->app()->router->get('/api/v1/__boom', $fail);
        $this->app()->router->get('/__boom', $fail);
    }

    public function testApiExceptionIsGenericAndLogged(): void
    {
        $r = $this->request('GET', '/api/v1/__boom');
        self::assertSame(500, $r->status);
        $d = $r->decoded();
        self::assertSame('INTERNAL_ERROR', $d['error']['code']);
        foreach (['SQLSTATE', 'xampp', 'hunter2', 'RuntimeException', '.php', 'Stack'] as $leak) {
            self::assertStringNotContainsString($leak, $r->body);
        }
        self::assertNotEmpty($d['meta']['request_id']);

        $log = implode("\n", $this->logLines());
        self::assertStringContainsString('unhandled_exception', $log);
        self::assertStringContainsString($d['meta']['request_id'], $log);
        self::assertStringContainsString('RuntimeException', $log);
    }

    public function testHtmlErrorPageIsGeneric(): void
    {
        $r = $this->request('GET', '/__boom');
        self::assertSame(500, $r->status);
        self::assertStringStartsWith('text/html', $r->headers['Content-Type']);
        foreach (['SQLSTATE', 'xampp', 'hunter2', 'RuntimeException'] as $leak) {
            self::assertStringNotContainsString($leak, $r->body);
        }
    }

    public function testReflectedPathIsNotRenderedUnescaped(): void
    {
        $r = $this->request('GET', '/<script>alert(1)</script>');
        self::assertSame(404, $r->status);
        self::assertStringNotContainsString('<script>alert(1)</script>', $r->body);
    }
}
