<?php

declare(strict_types=1);

namespace EduCloud\Modules\Datasets;

use EduCloud\Core\Exceptions\ApiException;
use EduCloud\Core\Exceptions\PayloadTooLargeException;
use EduCloud\Core\Exceptions\ValidationException;
use EduCloud\Core\UploadedFile;

/**
 * First line of defence for dataset uploads (plan §14, spec §20): CSV, JSON (array or NDJSON) and Parquet (M7).
 * Structural validation happens later in the runner (DuckDB readers under the sandbox and row/column caps).
 *  - genuine upload, size within limits, allowlisted extension → format
 *  - content sniffing per format:
 *      csv/json: no binary signatures, no NUL bytes, valid UTF-8 everywhere (+ finfo MIME allowlist);
 *                JSON must start with '[' or '{';
 *      parquet:  'PAR1' magic at the start AND the end of the file.
 * The stored file is never executed, never served by the web server and lives outside the web root.
 */
final class DataUploadValidator
{
    /** extension => format (datasets) */
    public const EXTENSIONS = ['csv' => 'csv', 'json' => 'json', 'jsonl' => 'json', 'ndjson' => 'json', 'parquet' => 'parquet'];

    /** extension => format (object storage, M7: also plain text and Markdown) */
    public const OBJECT_EXTENSIONS = self::EXTENSIONS + ['txt' => 'text', 'md' => 'text'];

    /** format => Content-Type assigned by the server (never taken from the client) */
    public const CONTENT_TYPES = [
        'csv' => 'text/csv', 'json' => 'application/json', 'parquet' => 'application/vnd.apache.parquet', 'text' => 'text/plain',
    ];

    private const TEXT_MIME = [
        'csv' => ['text/plain', 'text/csv', 'application/csv', 'text/x-csv', 'application/vnd.ms-excel'],
        // 'application/x-ndjason' (sic): libmagic 5.44 (Debian 12) misspells the NDJSON type; found by the Linux CI (M12).
        'json' => ['text/plain', 'application/json', 'application/x-ndjson', 'application/x-ndjason', 'text/x-json'],
        'text' => ['text/plain', 'text/markdown', 'text/x-markdown', 'text/csv', 'application/json'],
    ];

    /** Magic numbers of formats that must never be accepted as text (archives, executables, documents, images). */
    private const SIGNATURES = [
        "PK\x03\x04" => 'zip/xlsx', "%PDF" => 'pdf', "MZ" => 'exe', "\x7FELF" => 'elf', "\x89PNG" => 'png',
        "GIF8" => 'gif', "\xFF\xD8\xFF" => 'jpeg', "\xD0\xCF\x11\xE0" => 'ole', "Rar!" => 'rar', "\x1F\x8B" => 'gzip',
        "7z\xBC\xAF" => '7z', "PAR1" => 'parquet', "SQLite format" => 'sqlite',
    ];

    /** @param array<string, string> $extensions extension => format allowlist */
    public function __construct(private readonly int $maxBytes, private readonly array $extensions = self::EXTENSIONS)
    {
    }

    /** @return string the detected format: csv | json | parquet */
    public function validate(?UploadedFile $file): string
    {
        if ($file === null || $file->error === UPLOAD_ERR_NO_FILE) {
            throw self::invalid('required', 'Selecciona un archivo (.csv, .json, .jsonl o .parquet).');
        }
        if (in_array($file->error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            throw new PayloadTooLargeException($this->tooLargeMessage());
        }
        if ($file->error !== UPLOAD_ERR_OK) {
            throw new ApiException(400, 'UPLOAD_FAILED', 'La subida del archivo no se completó. Inténtalo de nuevo.');
        }
        $size = is_file($file->tmpPath) ? (int) filesize($file->tmpPath) : 0;
        if ($size === 0) {
            throw self::invalid('empty', 'El archivo está vacío.');
        }
        if ($size > $this->maxBytes) {
            throw new PayloadTooLargeException($this->tooLargeMessage());
        }
        $format = $this->extensions[strtolower(pathinfo($file->safeClientName(), PATHINFO_EXTENSION))] ?? null;
        if ($format === null) {
            $list = implode(', ', array_map(static fn (string $e): string => '.' . $e, array_keys($this->extensions)));
            throw self::invalid('extension', "Formatos admitidos: $list.");
        }

        if ($format === 'parquet') {
            $this->assertParquet($file->tmpPath, $size);
            return $format;
        }
        $head = (string) file_get_contents($file->tmpPath, false, null, 0, 65536);
        foreach (self::SIGNATURES as $magic => $_kind) {
            if (str_starts_with($head, (string) $magic)) {
                throw self::invalid('content', 'El contenido no es un archivo de texto (parece un archivo binario).');
            }
        }
        $this->assertTextFile($file->tmpPath);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file->tmpPath) ?: '';
        if (!in_array($mime, self::TEXT_MIME[$format], true)) {
            throw self::invalid('content', match ($format) {
                'csv' => 'El contenido no es un CSV de texto.',
                'json' => 'El contenido no es un JSON de texto.',
                default => 'El contenido no es un archivo de texto.',
            });
        }
        if ($format === 'json') {
            $start = ltrim(str_starts_with($head, "\xEF\xBB\xBF") ? substr($head, 3) : $head);
            if ($start === '' || ($start[0] !== '[' && $start[0] !== '{')) {
                throw self::invalid('content', 'El JSON debe ser una lista de objetos ([…]) o un objeto por línea (NDJSON).');
            }
        }
        return $format;
    }

    private function assertParquet(string $path, int $size): void
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new ApiException(400, 'UPLOAD_FAILED', 'No se pudo leer el archivo subido.');
        }
        try {
            $head = (string) fread($handle, 4);
            fseek($handle, -4, SEEK_END);
            $tail = (string) fread($handle, 4);
        } finally {
            fclose($handle);
        }
        if ($size < 12 || $head !== 'PAR1' || $tail !== 'PAR1') {
            throw self::invalid('content', 'El archivo no es un Parquet válido.');
        }
    }

    /** Streams the whole file: no NUL bytes and valid UTF-8 everywhere (a leading BOM is allowed). */
    private function assertTextFile(string $path): void
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new ApiException(400, 'UPLOAD_FAILED', 'No se pudo leer el archivo subido.');
        }
        try {
            $carry = '';
            $first = true;
            while (!feof($handle)) {
                $chunk = (string) fread($handle, 1048576);
                if ($first && str_starts_with($chunk, "\xEF\xBB\xBF")) {
                    $chunk = substr($chunk, 3);
                }
                $first = false;
                if (str_contains($chunk, "\0")) {
                    throw self::invalid('content', 'El archivo contiene datos binarios.');
                }
                $text = $carry . $chunk;
                // Keep an incomplete trailing UTF-8 sequence for the next chunk.
                $carry = '';
                if (!feof($handle) && preg_match('/[\xC0-\xFF][\x80-\xBF]{0,2}$/', $text, $m) === 1) {
                    $carry = $m[0];
                    $text = substr($text, 0, -strlen($carry));
                }
                if (!mb_check_encoding($text, 'UTF-8')) {
                    throw self::invalid('encoding', 'El archivo debe estar codificado en UTF-8.');
                }
            }
        } finally {
            fclose($handle);
        }
    }

    private function tooLargeMessage(): string
    {
        return 'El archivo supera el tamaño máximo de ' . (int) round($this->maxBytes / 1048576) . ' MB.';
    }

    private static function invalid(string $code, string $message): ValidationException
    {
        return new ValidationException([['field' => 'file', 'code' => $code, 'message' => $message]]);
    }
}
