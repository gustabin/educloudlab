<?php

declare(strict_types=1);

namespace EduCloud\Core;

use EduCloud\Core\Exceptions\MethodNotAllowedException;
use EduCloud\Core\Exceptions\NotFoundException;
use InvalidArgumentException;

/**
 * Minimal router with a route registry (ADR-011).
 * The registry is introspected by tests (e.g. the tenant-isolation route matrix and the OpenAPI coverage check).
 *
 * Route options:
 *   auth       'none' | 'session' | 'jwt' | 'any'   (default 'none')
 *   permission string|null                           (RBAC, config/permissions.php)
 *   rate       list<string>|null                     (IP rate-limit policies, config/security.php)
 *   csrf       bool (default true; false only for credential-exchange endpoints that never read cookies)
 *   public     bool  - indexable public page (no X-Robots-Tag noindex)
 *   name       string|null
 */
final class Router
{
    /** @var list<array{method: string, pattern: string, regex: string, handler: callable, options: array<string, mixed>}> */
    private array $routes = [];

    /** @param array<string, mixed> $options */
    public function add(string $method, string $pattern, callable $handler, array $options = []): void
    {
        $method = strtoupper($method);
        if (!in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            throw new InvalidArgumentException("Unsupported method $method");
        }
        $pattern = '/' . trim($pattern, '/');

        $this->routes[] = [
            'method' => $method,
            'pattern' => $pattern,
            'regex' => $this->compile($pattern),
            'handler' => $handler,
            'options' => $options + ['auth' => 'none', 'permission' => null, 'rate' => null, 'csrf' => true, 'public' => false, 'name' => null],
        ];
    }

    /** @param array<string, mixed> $options */
    public function get(string $pattern, callable $handler, array $options = []): void
    {
        $this->add('GET', $pattern, $handler, $options);
    }

    /** @param array<string, mixed> $options */
    public function post(string $pattern, callable $handler, array $options = []): void
    {
        $this->add('POST', $pattern, $handler, $options);
    }

    /** @param array<string, mixed> $options */
    public function patch(string $pattern, callable $handler, array $options = []): void
    {
        $this->add('PATCH', $pattern, $handler, $options);
    }

    /** @param array<string, mixed> $options */
    public function delete(string $pattern, callable $handler, array $options = []): void
    {
        $this->add('DELETE', $pattern, $handler, $options);
    }

    /**
     * @return array{
     *     route: array{method: string, pattern: string, regex: string, handler: callable, options: array<string, mixed>},
     *     params: array<string, string>
     * }
     */
    public function match(string $method, string $path): array
    {
        $method = strtoupper($method);
        $lookup = $method === 'HEAD' ? 'GET' : $method;
        $allowed = [];

        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $path, $m) !== 1) {
                continue;
            }
            if ($route['method'] !== $lookup) {
                $allowed[] = $route['method'];
                continue;
            }
            $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
            return ['route' => $route, 'params' => array_map('strval', $params)];
        }

        if ($allowed !== []) {
            throw new MethodNotAllowedException(array_values(array_unique($allowed)));
        }
        throw new NotFoundException();
    }

    /** @return list<array{method: string, pattern: string, options: array<string, mixed>}> */
    public function routes(): array
    {
        return array_map(
            static fn (array $r): array => ['method' => $r['method'], 'pattern' => $r['pattern'], 'options' => $r['options']],
            $this->routes
        );
    }

    private function compile(string $pattern): string
    {
        $parts = preg_split('/(\{[a-z_][a-z0-9_]*\})/', $pattern, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $regex = '';
        foreach ($parts as $part) {
            if (preg_match('/^\{([a-z_][a-z0-9_]*)\}$/D', $part, $m) === 1) {
                $regex .= '(?P<' . $m[1] . '>[^/]+)';
            } else {
                $regex .= preg_quote($part, '#');
            }
        }
        return '#^' . $regex . '$#D';
    }
}
