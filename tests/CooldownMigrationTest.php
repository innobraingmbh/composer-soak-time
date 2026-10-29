<?php

namespace Innobrain\SoakTime\Tests;

use Composer\Config\JsonConfigSource;
use Composer\Json\JsonFile;
use Innobrain\SoakTime\CooldownMigration;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CooldownMigrationTest extends TestCase
{
    #[Test]
    public function it_migrates_the_default_soak_time_when_nothing_is_configured(): void
    {
        $migration = CooldownMigration::fromExtra([]);

        $this->assertSame(['period' => '7 days'], $migration->cooldown);
        $this->assertSame([], $migration->removedKeys);
    }

    #[Test]
    public function it_turns_the_whitelist_into_cooldown_ignore_rules(): void
    {
        $migration = CooldownMigration::fromExtra([
            'soak-time-hours' => 72,
            'soak-time-whitelist' => ['your-company/*', '*/*', 'roave/security-advisories', 42],
        ]);

        $this->assertSame(['period' => '3 days', 'ignore' => ['your-company/*', 'roave/security-advisories']], $migration->cooldown);
        $this->assertSame(['soak-time-hours', 'soak-time-whitelist'], $migration->removedKeys);
        $this->assertSame(['*/*', 'int'], $migration->skippedPatterns);
    }

    #[Test]
    public function it_does_not_carry_over_a_whitelist_that_is_not_a_list(): void
    {
        $migration = CooldownMigration::fromExtra(['soak-time-whitelist' => 'vendor/name']);

        $this->assertSame(['period' => '7 days'], $migration->cooldown);
        $this->assertSame(['string'], $migration->skippedPatterns);
    }

    #[Test]
    public function it_leaves_the_settings_soak_time_keeps_enforcing_in_extra(): void
    {
        $migration = CooldownMigration::fromExtra([
            'soak-time-hours' => 72,
            'soak-time-package-hours' => ['vendor/name' => 24],
            'soak-time-security-hours' => 48,
            'soak-time-dev-branches' => ['vendor/lib'],
        ]);

        $this->assertSame(['soak-time-hours'], $migration->removedKeys);
    }

    #[Test]
    public function it_refuses_an_invalid_soak_time(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        CooldownMigration::fromExtra(['soak-time-hours' => 'a week']);
    }

    #[Test]
    public function it_refuses_a_disabled_soak_time(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        CooldownMigration::fromExtra(['soak-time-hours' => 0]);
    }

    #[Test]
    public function it_moves_the_settings_and_keeps_everything_else(): void
    {
        $path = sys_get_temp_dir().'/soak-time-migration-'.bin2hex(random_bytes(6)).'.json';
        file_put_contents($path, json_encode([
            'name' => 'acme/app',
            'config' => ['sort-packages' => true],
            'extra' => [
                'soak-time-hours' => 72,
                'soak-time-whitelist' => ['acme/*'],
                'soak-time-package-hours' => ['acme/fast' => 24],
                'soak-time-dev-branches' => ['acme/lib'],
            ],
        ], JSON_PRETTY_PRINT));

        try {
            $file = new JsonFile($path);
            CooldownMigration::fromExtra($file->read()['extra'])->applyTo(new JsonConfigSource($file));

            $this->assertSame([
                'name' => 'acme/app',
                'config' => [
                    'sort-packages' => true,
                    'policy' => ['cooldown' => ['period' => '3 days', 'ignore' => ['acme/*']]],
                ],
                'extra' => [
                    'soak-time-package-hours' => ['acme/fast' => 24],
                    'soak-time-dev-branches' => ['acme/lib'],
                ],
            ], $file->read());
        } finally {
            @unlink($path);
        }
    }
}
