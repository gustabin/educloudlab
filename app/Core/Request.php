<?php

declare(strict_types=1);

namespace EduCloud\Core;

use EduCloud\Core\Exceptions\PayloadTooLargeException;
use EduCloud\Core\Exceptions\ValidationException;

/**
 * Immutable-ish HTTP request. Built from globals in production and constructed directly in tests.
 * Route params and middleware results (user, tenant) are stored as attributes.
 */
final class Request
{
    /** @var array<string, mixed> */
    private array $attributes = [];

    /** @var array<string, mixed>|null */
    private ?array $jsonCache = null;

    /**
     * @param array<string, mixed>  $query
     * @param array<string, mixed>  $post
     * @param array<string, string> $headers lower-cased header names
     * @param array<string, string> $cookies
     * @param array<string, mixed>  $files
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $post = [],
        public readonly array $headers = [],
        public readonly array $cookies = [],
        public readonly array $files = [],
        public readonly string $body = '',
        public readonly string $ip = '127.0.0.1',
        public readonly bool $secure = false,
        public readonly string $requestId = '',
    ) {
    }

    /** @param string $basePath decoded deployment base path (e.g. "/EduCloud Lab"), stripped from the request path */
    public static function fromGlobals(string $basePath = ''): self
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = (string) $value;
            }
        }
        foreach (['CONTENT_TYPE' => 'content-type', 'CONTENT_LENGTH' => 'content-length'] as $key => $name) {
            if (isset($_SERVER[$key])) {
                $headers[$name] = (string) $_SERVER[$key];
            }
        }

        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $path = Url::stripBasePath(is_string($path) ? rawurldecode($path) : '/', $basePath);

        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $body = in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)
            ? (string) file_get_contents('php://input', false, null, 0, 2 * 1_048_576 + 1)
            : '';

        return new self(
            method: $method,
            path: $path,
            query: $_GET,
            post: $_POST,
            headers: $headers,
            cookies: array_map('strval', $_COOKIE),
            files: $_FILES,
            body: $body,
            ip: (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'),
            secure: (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
            requestId: Ulid::generate(),
        );
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function isApi(): bool
    {
        return $this->path === '/api' || str_starts_with($this->path, '/api/');
    }

    /**
     * Decoded JSON object body. Rejects oversized, malformed or non-object bodies.
     *
     * @return array<string, mixed>
     */
    public function json(int $maxBytes = 1_048_576): array
    {
        if ($this->jsonCache !== null) {
            return $this->jsonCache;
        }
        if (strlen($this->body) > $maxBytes) {
            throw new PayloadTooLargeException();
        }
        if (trim($this->body) === '') {
            return $this->jsonCache = [];
        }
        $contentType = strtolower((string) $this->header('content-type', ''));
        if (!str_starts_with($contentType, 'application/json')) {
            throw new ValidationException([['code' => 'unsupported_media_type', 'message' => 'Se esperaba Content-Type: application/json.']]);
        }
        try {
            $data = json_decode($this->body, true, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException) {
            throw new ValidationException([['code' => 'invalid_json', 'message' => 'El cuerpo no es JSON válido.']]);
        }
        if (!is_array($data) || array_is_list($data) && $data !== []) {
            throw new ValidationException([['code' => 'invalid_json', 'message' => 'Se esperaba un objeto JSON.']]);
        }
        return $this->jsonCache = $data;
    }

    public function withAttribute(string $name, mixed $value): self
    {
        $clone = clone $this;
        $clone->attributes[$name] = $value;
        return $clone;
    }

    public function attribute(string $name, mixed $default = null): mixed
    {
        return $this->attributes[$name] ?? $default;
    }

    public function param(string $name): string
    {
        $params = $this->attribute('route_params', []);
        return is_array($params) && isset($params[$name]) ? (string) $params[$name] : '';
    }
}
