<?php

namespace App\Services\Integration;

use RuntimeException;

/**
 * Binabasa ang CSV sa database/seeders/data/<pangalan>.csv (Bahagi C).
 */
class CsvData
{
    /**
     * @return array<int, array<string, string>>
     */
    public static function read(string $name): array
    {
        $path = database_path("seeders/data/{$name}.csv");
        if (!is_file($path)) {
            throw new RuntimeException("Missing file: database/seeders/data/{$name}.csv");
        }

        $handle = fopen($path, 'r');
        $header = array_map(fn ($h) => trim(preg_replace('/^\xEF\xBB\xBF/', '', $h)), fgetcsv($handle, escape: '\\') ?: []);
        $rows = [];

        while (($line = fgetcsv($handle, escape: '\\')) !== false) {
            if ($line === [null] || count(array_filter($line, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue; // walang laman na linya
            }
            $rows[] = array_combine($header, array_map(fn ($v) => trim((string) $v), array_pad($line, count($header), '')));
        }
        fclose($handle);

        return $rows;
    }
}
