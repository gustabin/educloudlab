<?php

declare(strict_types=1);

namespace EduCloud\Modules\Datasets;

use EduCloud\Core\Exceptions\ApiException;
use EduCloud\Core\Exceptions\PayloadTooLargeException;
use EduCloud\Core\Exceptions\ValidationException;
use EduCloud\Core\UploadedFile;

/**
 * First line of defence for uploads (plan §14, spec §20). Structural CSV validation happens later in the runner.
 *  - genuine upload, size within limits, .csv extension
 *  - content sniffing: finfo MIME allowlist, no known binary signatures, no NUL bytes, valid UTF-8
 * The stored file is never executed, never served by the web server and lives outside the web root.
 */
final class CsvUploadValidator
{
    private const ALLOWED_MIME = ['text/plain', 'text/csv', 'application/csv', 'text/x-csv', 'application/vnd.ms-excel'];

    /** Magic numbers of formats that must never be accepted as "CSV" (archives, executables, documents, images). */
    private const SIGNATURES = [
        "PK\x03\x04" => 'zip/xlsx', "%PDF" => 'pdf', "MZ" => 'exe', "\x7FELF" => 'elf', "\x89PNG" => 'png',
        "GIF8" => 'gif', "\xFF\xD8\xFF" => 'jpeg', "\xD0\xCF\x11\xE0" => 'ole', "Rar!" => 'rar', "\x1F\x8B" => 'gzip',
        "7z\xBC\xAF" => '7z', "PAR1" => 'parquet', "SQLite format" => 'sqlite',
    ];

    public function __construct(private readonly int $maxBytes)
    {
    }

    public function validate(?UploadedFile $file): void
    {
        if ($file === null || $file->error === UPLOAD_ERR_NO_FILE) {
            throw self::invalid('required', 'Selecciona un archivo CSV.');
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
        if (strtolower(pathinfo($file->safeClientName(), PATHINFO_EXTENSION)) !== 'csv') {
            throw self::invalid('extension', 'Solo se admiten archivos .csv.');
        }

        $head = (string) file_get_contents($file->tmpPath, false, null, 0, 65536);
        foreach (self::SIGNATURES as $magic => $_format) {
            if (str_starts_with($head, (string) $magic)) {
                throw self::invalid('content', 'El contenido no es un CSV de texto (parece un archivo binario).');
            }
        }
        $this->assertTextFile($file->tmpPath);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file->tmpPath) ?: '';
        if (!in_array($mime, self::ALLOWED_MIME, true)) {
            throw self::invalid('content', 'El contenido no es un CSV de texto.');
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
