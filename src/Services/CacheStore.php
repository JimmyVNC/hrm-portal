<?php

declare(strict_types=1);

namespace App\Services;

use App\Config;

class CacheStore {
    public static function get(string $key, int $ttlSeconds): ?string {
        $path = self::path($key);
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $payload = json_decode($raw, true);
        if (!is_array($payload) || !isset($payload['ts']) || !isset($payload['value'])) {
            return null;
        }
        if ((time() - (int) $payload['ts']) > $ttlSeconds) {
            return null;
        }
        return is_string($payload['value']) ? $payload['value'] : null;
    }

    public static function put(string $key, string $value): void {
        $dir = self::dir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $payload = ['ts' => time(), 'value' => $value];
        @file_put_contents(self::path($key), json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    private static function dir(): string {
        $custom = Config::getEnvValue('RUNTIME_CACHE_DIR');
        if (is_string($custom) && $custom !== '') {
            return rtrim($custom, DIRECTORY_SEPARATOR);
        }
        return __DIR__ . '/../../runtime/cache';
    }

    private static function path(string $key): string {
        return self::dir() . '/' . hash('sha256', $key) . '.json';
    }

    /**
     * Prune old cache files and enforce a total size budget (oldest mtime removed first).
     *
     * @return array{deleted_files: int, freed_bytes: int, total_bytes_after: int}
     */
    public static function prune(int $maxFileAgeSeconds, int $maxTotalBytes): array {
        $dir = self::dir();
        if (!is_dir($dir)) {
            return ['deleted_files' => 0, 'freed_bytes' => 0, 'total_bytes_after' => 0];
        }

        $deleted = 0;
        $freed = 0;
        $now = time();

        $paths = glob($dir . DIRECTORY_SEPARATOR . '*.json') ?: [];
        foreach ($paths as $path) {
            if (!is_string($path) || !is_file($path)) {
                continue;
            }
            $mtime = (int) (@filemtime($path) ?: 0);
            if ($maxFileAgeSeconds > 0 && $mtime > 0 && ($now - $mtime) > $maxFileAgeSeconds) {
                $sz = (int) (@filesize($path) ?: 0);
                if (@unlink($path)) {
                    $deleted++;
                    $freed += $sz;
                }
            }
        }

        $paths = glob($dir . DIRECTORY_SEPARATOR . '*.json') ?: [];
        $items = [];
        $total = 0;
        foreach ($paths as $path) {
            if (!is_file($path)) {
                continue;
            }
            $sz = (int) (@filesize($path) ?: 0);
            $mt = (int) (@filemtime($path) ?: 0);
            $items[] = ['path' => $path, 'size' => $sz, 'mtime' => $mt];
            $total += $sz;
        }

        usort($items, static function (array $a, array $b): int {
            return $a['mtime'] <=> $b['mtime'];
        });

        while ($maxTotalBytes > 0 && $total > $maxTotalBytes && $items !== []) {
            $old = array_shift($items);
            if ($old === null) {
                break;
            }
            if (@unlink($old['path'])) {
                $deleted++;
                $freed += $old['size'];
                $total -= $old['size'];
            }
        }

        return [
            'deleted_files' => $deleted,
            'freed_bytes' => $freed,
            'total_bytes_after' => $total,
        ];
    }
}
