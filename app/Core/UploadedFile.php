<?php

declare(strict_types=1);

namespace EduCloud\Core;

/**
 * One uploaded file. In production instances come from $_FILES and moveTo() uses move_uploaded_file(), so only
 * genuine HTTP uploads can be moved. Tests build instances with $trusted = true (plain rename of a temp copy).
 * The client-supplied name is display-only; never use it to build a path.
 */
final class UploadedFile
{
    public function __construct(
        public readonly string $tmpPath,
        public readonly string $clientName,
        public readonly int $size,
        public readonly int $error,
        private readonly bool $trusted = false,
    ) {
    }

    /**
     * Normalises $_FILES into field => UploadedFile (single files only; arrays of files are ignored).
     *
     * @param array<string, mixed> $files
     * @return array<string, self>
     */
    public static function fromGlobals(array $files): array
    {
        $out = [];
        foreach ($files as $field => $info) {
            if (!is_array($info) || !is_string($info['tmp_name'] ?? null) || !is_string($info['name'] ?? null)) {
                continue;
            }
            $out[(string) $field] = new self(
                (string) $info['tmp_name'],
                (string) $info['name'],
                (int) ($info['size'] ?? 0),
                (int) ($info['error'] ?? UPLOAD_ERR_NO_FILE)
            );
        }
        return $out;
    }

    /** Display-safe file name: base name only, no control characters, at most 255 characters. */
    public function safeClientName(): string
    {
        $name = basename(str_replace('\\', '/', $this->clientName));
        $name = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', $name);
        $name = mb_substr(trim($name), 0, 255);
        return $name === '' ? 'archivo.csv' : $name;
    }

    public function moveTo(string $target): bool
    {
        if ($this->trusted) {
            return @rename($this->tmpPath, $target);
        }
        return is_uploaded_file($this->tmpPath) && @move_uploaded_file($this->tmpPath, $target);
    }
}
