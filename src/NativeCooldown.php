<?php

namespace Innobrain\SoakTime;

use Composer\Config;

final class NativeCooldown
{
    private const SOURCE = 'innobrain/soak-time';

    private const UNITS = ['second' => 1, 'minute' => 60, 'hour' => 3600, 'day' => 86400, 'week' => 604800];

    public function __construct(private readonly Config $config) {}

    public static function isAvailable(): bool
    {
        return class_exists(\Composer\Policy\CooldownPolicyConfig::class);
    }

    public function state(): CooldownState
    {
        return self::resolveState(
            $this->config->get('policy'),
            getenv('COMPOSER_POLICY_COOLDOWN_PERIOD'),
            getenv('COMPOSER_POLICY_COOLDOWN_BLOCK'),
        );
    }

    public function periodSource(): string
    {
        $envPeriod = getenv('COMPOSER_POLICY_COOLDOWN_PERIOD');

        return $envPeriod !== false && $envPeriod !== '' ? 'COMPOSER_POLICY_COOLDOWN_PERIOD' : 'policy.cooldown';
    }

    public function periodSeconds(): ?int
    {
        $envPeriod = getenv('COMPOSER_POLICY_COOLDOWN_PERIOD');

        if ($envPeriod !== false && $envPeriod !== '') {
            return self::parseSeconds($envPeriod);
        }

        return self::parseSeconds(self::cooldownConfig($this->config->get('policy'))['period'] ?? null);
    }

    public function supplyPeriod(int $hours): void
    {
        $this->merge(['period' => self::formatPeriod($hours * 3600)]);
    }

    /**
     * @param  array<string, list<array{constraint?: string, reason: string}>>  $rules
     */
    public function exempt(array $rules): void
    {
        if ($rules === []) {
            return;
        }

        $existing = self::cooldownConfig($this->config->get('policy'))['ignore'] ?? [];

        $this->merge(['ignore' => self::combine(is_array($existing) ? $existing : [], $rules)]);
    }

    public static function resolveState(mixed $policy, string|false $envPeriod, string|false $envBlock): CooldownState
    {
        if ($policy === false) {
            return CooldownState::Disabled;
        }

        $cooldown = is_array($policy) ? ($policy['cooldown'] ?? []) : [];
        $envPeriodSet = $envPeriod !== false && $envPeriod !== '';

        if ($cooldown === false && ! $envPeriodSet) {
            return CooldownState::Disabled;
        }

        $cooldown = is_array($cooldown) ? $cooldown : [];

        $block = $envBlock !== false && $envBlock !== ''
            ? in_array($envBlock, ['1', 'true', 'on'], true)
            : (bool) ($cooldown['block'] ?? true);

        if (! $block) {
            return CooldownState::Disabled;
        }

        $period = $envPeriodSet ? $envPeriod : ($cooldown['period'] ?? null);

        if ($period === null || $period === '') {
            return CooldownState::Unconfigured;
        }

        return self::parseSeconds($period) > 0 ? CooldownState::Configured : CooldownState::Disabled;
    }

    public static function parseSeconds(mixed $period): ?int
    {
        if (is_int($period)) {
            return $period >= 0 ? $period : null;
        }

        if (! is_string($period)) {
            return null;
        }

        if (preg_match('/^\d+$/D', $period) === 1) {
            return (int) $period;
        }

        if (preg_match('/^(\d+)\s*(second|minute|hour|day|week)s?$/D', $period, $matches) === 1) {
            return (int) $matches[1] * self::UNITS[$matches[2]];
        }

        return null;
    }

    public static function formatPeriod(int $seconds): string
    {
        foreach (['day' => 86400, 'hour' => 3600, 'minute' => 60] as $unit => $size) {
            if ($seconds >= $size && $seconds % $size === 0) {
                $count = intdiv($seconds, $size);

                return $count.' '.$unit.($count === 1 ? '' : 's');
            }
        }

        return $seconds.($seconds === 1 ? ' second' : ' seconds');
    }

    /**
     * Composer merges ignore maps key by key, so a rule for a package the
     * user already ignores would replace theirs. Carry their rules along.
     *
     * @param  array<int|string, mixed>  $existing
     * @param  array<string, list<array{constraint?: string, reason: string}>>  $rules
     * @return array<string, list<array<string, mixed>>>
     */
    public static function combine(array $existing, array $rules): array
    {
        $combined = [];

        foreach ($rules as $name => $additions) {
            $current = array_key_exists($name, $existing) ? self::ruleList($existing[$name]) : [];
            $combined[$name] = [...$current, ...$additions];
        }

        return $combined;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function ruleList(mixed $value): array
    {
        return match (true) {
            $value === null, $value === [] => [[]],
            is_string($value) => [['reason' => $value]],
            is_array($value) && array_is_list($value) => $value,
            is_array($value) => [$value],
            default => [],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function cooldownConfig(mixed $policy): array
    {
        $cooldown = is_array($policy) ? ($policy['cooldown'] ?? []) : [];

        return is_array($cooldown) ? $cooldown : [];
    }

    /**
     * @param  array<string, mixed>  $cooldown
     */
    private function merge(array $cooldown): void
    {
        $this->config->merge(['config' => ['policy' => ['cooldown' => $cooldown]]], self::SOURCE);
    }
}
