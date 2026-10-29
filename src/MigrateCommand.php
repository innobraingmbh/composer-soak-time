<?php

namespace Innobrain\SoakTime;

use Composer\Command\BaseCommand;
use Composer\Config\JsonConfigSource;
use Composer\Factory;
use Composer\Json\JsonFile;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class MigrateCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->setName('soak-time:migrate')
            ->setDescription("Moves soak-time-hours and soak-time-whitelist in composer.json to Composer's cooldown policy")
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show the changes without writing composer.json');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->getIO();

        if (! NativeCooldown::isAvailable()) {
            $io->writeError('<error>Composer\'s cooldown policy needs Composer 2.11 or newer. Run "composer self-update" first.</error>');

            return 1;
        }

        $composerFile = Factory::getComposerFile();

        if (realpath(dirname($composerFile)) === realpath($this->requireComposer()->getConfig()->get('home'))) {
            $io->writeError([
                '<error>This is the global composer.json, whose config only applies to "composer global" commands.</error>',
                '<error>To cover every project, run: composer config --global policy.cooldown.period "7 days"</error>',
            ]);

            return 1;
        }

        $file = new JsonFile($composerFile);
        $json = $file->read();

        if (isset($json['config']['policy']['cooldown'])) {
            $io->writeError('<error>composer.json already configures "policy.cooldown". Nothing to migrate.</error>');

            return 1;
        }

        try {
            $migration = CooldownMigration::fromExtra($json['extra'] ?? []);
        } catch (\InvalidArgumentException $exception) {
            $io->writeError('<error>'.$exception->getMessage().'</error>');

            return 1;
        }

        $io->write([
            '<info>Adding to composer.json:</info>',
            '  "config": { "policy": { "cooldown": '.JsonFile::encode($migration->cooldown, JSON_UNESCAPED_SLASHES).' } }',
        ]);

        if ($migration->removedKeys !== []) {
            $io->write('<info>Removing from "extra":</info> '.implode(', ', $migration->removedKeys));
        }

        if ($migration->skippedPatterns !== []) {
            $io->writeError(sprintf(
                '<warning>Not carried over, as the soak filter already ignores them as invalid: %s</warning>',
                implode(', ', $migration->skippedPatterns)
            ));
        }

        if ($input->getOption('dry-run')) {
            return 0;
        }

        $migration->applyTo(new JsonConfigSource($file));

        $io->write([
            '<info>Done. Per-package windows, the security-fix window and integrity checks keep working through soak-time.</info>',
            'Next steps:',
            '  - Run "composer update --lock": changing the policy config marks composer.lock as outdated.',
            '  - In CI and scripts, use COMPOSER_POLICY_COOLDOWN_PERIOD instead of SOAK_TIME_HOURS, and --no-cooldown instead of SOAK_TIME_SKIP=1.',
            '  - Everyone on the project needs Composer 2.11+: older versions ignore policy.cooldown, and the plugin then falls back to its defaults.',
        ]);

        return 0;
    }
}
