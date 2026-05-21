<?php

namespace Innobrain\SoakTime;

final class SoakTimeConfig
{
    /**
     * @param  list<string>  $whitelist
     */
    public function __construct(
        public readonly int $minHours,
        public readonly array $whitelist,
        public readonly bool $bypass,
    ) {}

    /**
     * Parse a raw "soak time hours" value into a non-negative integer.
     *
     * Returns null when the value is anything other than a non-negative
     * integer, so the caller can warn and fall back instead of silently
     * disabling protection (for example when an env var holds a typo).
     */
    public static function parseHours(mixed $raw): ?int
    {
        if (is_int($raw)) {
            return $raw >= 0 ? $raw : null;
        }

        if (is_string($raw) && preg_match('/^\s*\d+\s*$/', $raw) === 1) {
            return (int) trim($raw);
        }

        return null;
    }
}
