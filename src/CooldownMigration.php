<?php

namespace Innobrain\SoakTime;

use Composer\Config\JsonConfigSource;

final class CooldownMigration
{
    /**
     * @param  array{period: string, ignore?: list<string>}  $cooldown
     * @param  list<string>  $removedKeys
     * @param  list<string>  $skippedPatterns
     */
    private function __construct(
        public readonly array $cooldown,
        public readonly array $removedKeys,
        public readonly array $skippedPatterns,
    ) {}

    /**
     * @param  array<string, mixed>  $extra
     *
     * @throws \InvalidArgumentException
     */
    public static function fromExtra(array $extra): self
    {
        $hours = array_key_exists('soak-time-hours', $extra)
            ? SoakTimeConfig::parseHours($extra['soak-time-hours'])
            : Plugin::DEFAULT_MIN_HOURS;

        if ($hours === null) {
            throw new \InvalidArgumentException('"soak-time-hours" is not a non-negative integer. Fix it before migrating.');
        }

        if ($hours === 0) {
            throw new \InvalidArgumentException('"soak-time-hours" is 0, so the soak filter is off and there is nothing to migrate.');
        }

        $whitelist = [];
        $skippedPatterns = [];
        $entries = $extra['soak-time-whitelist'] ?? [];

        if (! is_array($entries)) {
            $skippedPatterns[] = get_debug_type($entries);
            $entries = [];
        }

        foreach ($entries as $entry) {
            if (is_string($entry) && PackageFilter::isValidWhitelistPattern($entry)) {
                $whitelist[] = $entry;
            } else {
                $skippedPatterns[] = is_string($entry) ? $entry : get_debug_type($entry);
            }
        }

        $cooldown = ['period' => NativeCooldown::formatPeriod($hours * 3600)];

        if ($whitelist !== []) {
            $cooldown['ignore'] = $whitelist;
        }

        return new self(
            $cooldown,
            array_values(array_intersect(['soak-time-hours', 'soak-time-whitelist'], array_keys($extra))),
            $skippedPatterns,
        );
    }

    public function applyTo(JsonConfigSource $source): void
    {
        $source->addConfigSetting('policy.cooldown', $this->cooldown);

        foreach ($this->removedKeys as $key) {
            $source->removeProperty('extra.'.$key);
        }
    }
}
