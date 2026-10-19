<?php

declare(strict_types=1);

namespace App\Infrastructure\Export;

/** Creates small, uncompressed ZIP archives directly in memory; no export file is persisted. */
final class StreamingZipArchive
{
    /** @param array<string, string> $entries */
    public function create(array $entries): string
    {
        $body = '';
        $directory = '';
        $offset = 0;

        foreach ($entries as $name => $contents) {
            if ('' === $name || str_contains($name, '..') || str_starts_with($name, '/')) {
                throw new \InvalidArgumentException('Nom de fichier d’archive invalide.');
            }

            $nameLength = strlen($name);
            $size = strlen($contents);
            $crc = crc32($contents);
            if ($crc < 0) {
                $crc += 4294967296;
            }
            $local = pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, 0, 0, $crc, $size, $size, $nameLength, 0).$name.$contents;
            $body .= $local;
            $directory .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, 0, 0, $crc, $size, $size, $nameLength, 0, 0, 0, 0, 0, $offset).$name;
            $offset += strlen($local);
        }

        return $body.$directory.pack('VvvvvVVv', 0x06054b50, 0, 0, count($entries), count($entries), strlen($directory), strlen($body), 0);
    }
}
