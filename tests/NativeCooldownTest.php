<?php

namespace Innobrain\SoakTime\Tests;

use Innobrain\SoakTime\CooldownState;
use Innobrain\SoakTime\NativeCooldown;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NativeCooldownTest extends TestCase
{
    #[Test]
    #[DataProvider('states')]
    public function it_mirrors_how_composer_resolves_the_cooldown(mixed $policy, string|false $envPeriod, string|false $envBlock, CooldownState $expected): void
    {
        $this->assertSame($expected, NativeCooldown::resolveState($policy, $envPeriod, $envBlock));
    }

    /**
     * @return array<string, array{mixed, string|false, string|false, CooldownState}>
     */
    public static function states(): array
    {
        return [
            'no policy config' => [null, false, false, CooldownState::Unconfigured],
            'policy defaults' => [true, false, false, CooldownState::Unconfigured],
            'policy without cooldown' => [['malware' => ['block' => true]], false, false, CooldownState::Unconfigured],
            'cooldown without period' => [['cooldown' => ['ignore' => ['acme/*']]], false, false, CooldownState::Unconfigured],
            'configured period' => [['cooldown' => ['period' => '7 days']], false, false, CooldownState::Configured],
            'period in seconds' => [['cooldown' => ['period' => 86400]], false, false, CooldownState::Configured],
            'zero period' => [['cooldown' => ['period' => '0']], false, false, CooldownState::Disabled],
            'zero days' => [['cooldown' => ['period' => '0 days']], false, false, CooldownState::Disabled],
            'blocking turned off' => [['cooldown' => ['period' => '1 day', 'block' => false]], false, false, CooldownState::Disabled],
            'all policies turned off' => [false, '1 day', false, CooldownState::Disabled],
            'cooldown turned off' => [['cooldown' => false], false, false, CooldownState::Disabled],
            'env period re-enables a turned-off cooldown' => [['cooldown' => false], '1 day', false, CooldownState::Configured],
            'env period alone' => [null, '6 hours', false, CooldownState::Configured],
            'env period of zero' => [['cooldown' => ['period' => '7 days']], '0', false, CooldownState::Disabled],
            'env block turns blocking back on' => [['cooldown' => ['period' => '1 day', 'block' => false]], false, 'true', CooldownState::Configured],
            'env block turns blocking off' => [['cooldown' => ['period' => '1 day']], false, '0', CooldownState::Disabled],
        ];
    }

    #[Test]
    public function it_parses_composer_durations(): void
    {
        $this->assertSame(86400, NativeCooldown::parseSeconds(86400));
        $this->assertSame(3600, NativeCooldown::parseSeconds('3600'));
        $this->assertSame(604800, NativeCooldown::parseSeconds('7 days'));
        $this->assertSame(604800, NativeCooldown::parseSeconds('1 week'));
        $this->assertSame(21600, NativeCooldown::parseSeconds('6hours'));
        $this->assertNull(NativeCooldown::parseSeconds('tomorrow'));
        $this->assertNull(NativeCooldown::parseSeconds(-1));
        $this->assertNull(NativeCooldown::parseSeconds(null));
    }

    #[Test]
    public function it_writes_periods_in_the_largest_whole_unit(): void
    {
        $this->assertSame('1 day', NativeCooldown::formatPeriod(86400));
        $this->assertSame('7 days', NativeCooldown::formatPeriod(604800));
        $this->assertSame('1 hour', NativeCooldown::formatPeriod(3600));
        $this->assertSame('36 hours', NativeCooldown::formatPeriod(129600));
        $this->assertSame('90 minutes', NativeCooldown::formatPeriod(5400));
        $this->assertSame('61 seconds', NativeCooldown::formatPeriod(61));
        $this->assertSame('0 seconds', NativeCooldown::formatPeriod(0));
    }

    #[Test]
    public function it_keeps_the_users_own_ignore_rules_for_a_package_it_adds_rules_for(): void
    {
        $locked = ['constraint' => '==1.0.0.0', 'reason' => 'locked'];

        $combined = NativeCooldown::combine(
            [
                'acme/everything' => null,
                'acme/reasoned' => 'we trust it',
                'acme/ranged' => ['constraint' => '^2.0'],
                'acme/listed' => [['constraint' => '^3.0'], ['constraint' => '^4.0']],
                'acme/empty' => [],
                0 => 'acme/untouched',
            ],
            [
                'acme/everything' => [$locked],
                'acme/reasoned' => [$locked],
                'acme/ranged' => [$locked],
                'acme/listed' => [$locked],
                'acme/empty' => [$locked],
                'acme/new' => [$locked],
            ]
        );

        $this->assertSame([
            'acme/everything' => [[], $locked],
            'acme/reasoned' => [['reason' => 'we trust it'], $locked],
            'acme/ranged' => [['constraint' => '^2.0'], $locked],
            'acme/listed' => [['constraint' => '^3.0'], ['constraint' => '^4.0'], $locked],
            'acme/empty' => [[], $locked],
            'acme/new' => [$locked],
        ], $combined);
    }
}
