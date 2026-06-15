<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Keeps JSON logs bounded and safe for centralized log pipelines.
 */
final class LogFormatter {
    private function __construct() {}

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public static function sanitizeContext(array $context, int $maxStringLength = 4000, int $maxDepth = 5, int $depth = 0): array {
        if ($depth >= $maxDepth) {
            return ['_truncated' => 'max_depth'];
        }
        $out = [];
        foreach ($context as $k => $v) {
            if (!is_string($k) || $k === '' || strlen($k) > 128) {
                continue;
            }
            if (is_array($v)) {
                $out[$k] = self::sanitizeContext($v, $maxStringLength, $maxDepth, $depth + 1);
            } elseif (is_string($v)) {
                $len = strlen($v);
                $out[$k] = $len > $maxStringLength
                    ? substr($v, 0, $maxStringLength) . '…[truncated]'
                    : $v;
            } elseif (is_scalar($v) || $v === null) {
                $out[$k] = $v;
            } else {
                $out[$k] = '[non-scalar:' . get_debug_type($v) . ']';
            }
        }
        return $out;
    }
}
