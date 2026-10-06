<?php

declare(strict_types=1);

namespace EduCloud\Tests\Unit\Core;

use EduCloud\Core\Exceptions\PayloadTooLargeException;
use EduCloud\Core\Exceptions\ValidationException;
use EduCloud\Core\Request;
use EduCloud\Core\Response;
use PHPUnit\Framework\TestCase;

final class RequestResponseTest extends TestCase
{
    private function req(string $body, string $type = 'application/json'): Request
    {
        return new Request('POST', '/api/v1/x', headers: ['content-type' => $type], body: $body);
    }

    public function testParsesJsonObject(): void
    {
        self::assertSame(['name' => 'ws'], $this->req('{"name":"ws"}')->json());
        self::assertSame([], $this->req('')->json());
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidBodies(): iterable
    {
        yield 'malformed' => ['{bad', 'invalid_json'];
        yield 'array root' => ['[1,2]', 'invalid_json'];
        yield 'scalar root' => ['"x"', 'invalid_json'];
        yield 'too deep' => [str_repeat('{"a":', 40) . '1' . str_repeat('}', 40), 'invalid_json'];
    }

    /** @dataProvider invalidBodies */
    public function testRejectsInvalidJson(string $body, string $code): void
    {
        try {
            $this->req($body)->json();
            self::fail('Expected ValidationException');
        } catch (ValidationException $e) {
            self::assertSame(422, $e->status);
            self::assertSame($code, $e->details[0]['code']);
        }
    }

    public function testRejectsWrongContentType(): void
    {
        $this->expectException(ValidationException::class);
        $this->req('{"a":1}', 'text/plain')->json();
    }

    public function testRejectsOversizedBody(): void
    {
        $this->expectException(PayloadTooLargeException::class);
        $this->req('{"a":"' . str_repeat('x', 2000) . '"}')->json(1000);
    }

    public function testSuccessEnvelope(): void
    {
        $r = Response::json(['id' => 'X'], 201, 'Creado.', ['request_id' => 'R']);
        self::assertSame(201, $r->status);
        self::assertSame(
            ['success' => true, 'data' => ['id' => 'X'], 'message' => 'Creado.', 'meta' => ['request_id' => 'R']],
            $r->decoded()
        );
        self::assertSame('no-store', $r->headers['Cache-Control']);
    }

    public function testErrorEnvelope(): void
    {
        $r = Response::error(422, 'VALIDATION_ERROR', 'Datos no válidos.', [['field' => 'name', 'code' => 'required', 'message' => 'x']]);
        $d = $r->decoded();
        self::assertFalse($d['success']);
        self::assertSame('VALIDATION_ERROR', $d['error']['code']);
        self::assertSame('name', $d['error']['details'][0]['field']);
    }

    public function testJsonOutputEscapesHtmlSensitiveCharacters(): void
    {
        $body = Response::json(['v' => '</script><b>&'])->body;
        self::assertStringNotContainsString('</script>', $body);
        self::assertStringNotContainsString('<b>', $body);
    }

    public function testAttributesAreImmutableCopies(): void
    {
        $a = new Request('GET', '/');
        $b = $a->withAttribute('route_params', ['id' => '1']);
        self::assertSame('', $a->param('id'));
        self::assertSame('1', $b->param('id'));
    }
}
