<?php

namespace Innobrain\SoakTime;

final class SoakTimeConfig
{
    /**
     * @param  list<string>  $whitelist
     * @param  bool  $skipAllSoak  Emergency switch (SOAK_TIME_SKIP=1) — disables soak filtering only.
     * @param  list<string>  $devBranches  Package-name patterns whose dev versions are treated as mutable.
     * @param  list<string>  $integrityIgnore  Package-name patterns exempted from integrity checks entirely.
     */
    public function __construct(
        public readonly int $minHours,
        public readonly array $whitelist,
        public readonly bool $skipAllSoak,
        public readonly bool $integrity = true,
        public readonly string $integrityLockPath = 'composer-integrity.lock',
        public readonly array $devBranches = [],
        public readonly array $integrityIgnore = [],
    ) {}

    /**
     * Parse a raw soak-time-hours value into a non-negative integer. Returns
     * null on anything else (e.g. an env var with a typo) so the caller can
     * warn and fall back instead of silently disabling protection.
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
