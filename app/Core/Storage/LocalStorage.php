<?php

declare(strict_types=1);

namespace EduCloud\Core\Storage;

use EduCloud\Core\Ulid;
use RuntimeException;

/**
 * Local filesystem storage below STORAGE_PATH (outside the web root).
 *
 * Layout (every component is a server-generated ULID, never user input):
 *   t/{tenant}/w/{workspace}/raw/{storage_key}.csv        uploaded raw files
 *   t/{tenant}/w/{workspace}/meta/{version}.preview.json  dataset previews
 *   t/{tenant}/w/{workspace}/lakehouse.duckdb             workspace lakehouse (bronze/silver/gold)
 *   jobs/{job}.request.json | .response.json | .log       runner I/O (deleted after each job)
 *   tmp/                                                  staging for uploads
 *
 * Every resolved path is checked to stay inside the root (defence in depth against traversal).
 * An S3-compatible driver can replace this class later without changing callers.
 */
final class LocalStorage
{
    private string $root;

    public function __construct(string $root)
    {
        $real = realpath($root);
        if ($root === '' || $real === false || !is_dir($real)) {
            throw new RuntimeException('Storage root does not exist');
        }
        $this->root = rtrim(str_replace('\\', '/', $real), '/');
    }

    public function root(): string
    {
        return $this->root;
    }

    public function workspaceDir(string $tenantPublicId, string $workspacePublicId): string
    {
        return $this->path('t', self::id($tenantPublicId), 'w', self::id($workspacePublicId));
    }

    public function rawFile(string $tenantPublicId, string $workspacePublicId, string $storageKey): string
    {
        return $this->workspaceDir($tenantPublicId, $workspacePublicId) . '/raw/' . self::id($storageKey) . '.csv';
    }

    public function previewFile(string $tenantPublicId, string $workspacePublicId, string $versionPublicId): string
    {
        return $this->workspaceDir($tenantPublicId, $workspacePublicId) . '/meta/' . self::id($versionPublicId) . '.preview.json';
    }

    public function lakehouseFile(string $tenantPublicId, string $workspacePublicId): string
    {
        return $this->workspaceDir($tenantPublicId, $workspacePublicId) . '/lakehouse.duckdb';
    }

    public function jobFile(string $jobPublicId, string $suffix): string
    {
        if (!in_array($suffix, ['request.json', 'response.json', 'log'], true)) {
            throw new RuntimeException('Invalid job file suffix');
        }
        return $this->path('jobs') . '/' . self::id($jobPublicId) . '.' . $suffix;
    }

    public function tmpDir(): string
    {
        return $this->path('tmp');
    }

    /** Moves a staged file into place atomically (rename within the same volume). */
    public function moveInto(string $source, string $target): void
    {
        $this->assertInside($target);
        $this->ensureDir(dirname($target));
        if (!@rename($source, $target)) {
            throw new RuntimeException('Could not store file');
        }
    }

    public function delete(string $path): void
    {
        $this->assertInside($path);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /** Recursively deletes a directory below the root (workspace cleanup). Refuses the root itself. */
    public function deleteTree(string $dir): void
    {
        $this->assertInside($dir);
        $dir = rtrim(str_replace('\\', '/', $dir), '/');
        if ($dir === $this->root || !is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            if ($item->isLink() || $item->isFile()) {
                @unlink($item->getPathname());
            } else {
                @rmdir($item->getPathname());
            }
        }
        @rmdir($dir);
    }

    public function ensureDir(string $dir): void
    {
        $this->assertInside($dir);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create storage directory');
        }
    }

    /** Throws unless $path (existing or not) lies strictly inside the storage root. */
    public function assertInside(string $path): void
    {
        $normalised = self::normalise($path);
        if (!str_starts_with($normalised, $this->root . '/')) {
            throw new RuntimeException('Path escapes the storage root');
        }
        $real = realpath($path);
        if ($real !== false && !str_starts_with(self::normalise($real), $this->root . '/')) {
            throw new RuntimeException('Path escapes the storage root');
        }
    }

    private function path(string ...$parts): string
    {
        $path = $this->root . '/' . implode('/', $parts);
        $this->assertInside($path);
        return $path;
    }

    private static function id(string $value): string
    {
        if (!Ulid::isValid($value)) {
            throw new RuntimeException('Invalid storage identifier');
        }
        return $value;
    }

    /** Lexically resolves "." and ".." so traversal cannot pass the prefix check. */
    private static function normalise(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $prefix = '';
        if (preg_match('#^([A-Za-z]:)?/#', $path, $m) === 1) {
            $prefix = $m[0];
            $path = substr($path, strlen($prefix));
        }
        $out = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($out);
                continue;
            }
            $out[] = $segment;
        }
        return rtrim($prefix, '/') . '/' . implode('/', $out);
    }
}
