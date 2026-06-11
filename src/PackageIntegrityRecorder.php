<?php

namespace Innobrain\SoakTime;

use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;

final class PackageIntegrityRecorder
{
    /**
     * @param  list<string>  $devBranches  Package-name patterns declared as mutable dev branches.
     */
    public function __construct(
        private readonly IntegrityLockFile $lockFile,
        private readonly IOInterface $io,
        private readonly array $devBranches = [],
    ) {}

    public function record(PackageInterface $package): void
    {
        $name = $package->getName();
        $version = $package->getPrettyVersion();

        if ($package->getDistType() === 'path') {
            $this->io->write(sprintf(
                '<info>[Soak Time] Skipping integrity pinning for %s@%s (local path repository).</info>',
                $name,
                $version
            ), true, IOInterface::VERBOSE);

            return;
        }

        $entry = $this->lockFile->lookup($name, $version);

        if ($entry !== null) {
            $isMutableDev = $package->isDev()
                && PackageFilter::matchesWhitelist($name, $this->devBranches);

            if ($isMutableDev && $this->referenceChanged($package, $entry)) {
                $this->repin($package);

                return;
            }

            (new ReferenceDriftCheck($this->lockFile, $this->devBranches))->verify([$package]);

            if ($package->getInstallationSource() === 'dist' && $entry->sha256 === null) {
                throw $this->unobservedDistArchive($name, $version);
            }

            return;
        }

        $sourceReference = $package->getSourceReference();

        if ($sourceReference === null || $sourceReference === '') {
            throw new \RuntimeException(sprintf(
                "[Soak Time] Cannot pin integrity for %s@%s.\n".
                "  Composer did not provide a dist archive hash or a source reference.\n".
                "  Disable integrity checks only if you intentionally accept unpinned updates.",
                $name,
                $version
            ));
        }

        if ($package->getInstallationSource() === 'dist') {
            throw $this->unobservedDistArchive($name, $version);
        }

        $this->lockFile->record(new IntegrityEntry(
            $name,
            $version,
            null,
            $sourceReference,
            $package->getSourceUrl(),
            $package->getDistUrl(),
            new \DateTimeImmutable(),
        ));
        $this->lockFile->save();

        $this->io->write(sprintf(
            '<info>[Soak Time] Pinned %s@%s (source ref %s…)</info>',
            $name,
            $version,
            substr($sourceReference, 0, 12)
        ), true, IOInterface::VERBOSE);
    }

    private function referenceChanged(PackageInterface $package, IntegrityEntry $entry): bool
    {
        if ($entry->sourceReference !== null && $entry->sourceReference !== $package->getSourceReference()) {
            return true;
        }

        if ($entry->sourceUrl !== null && $entry->sourceUrl !== $package->getSourceUrl()) {
            return true;
        }

        if ($entry->distUrl !== null && $entry->distUrl !== $package->getDistUrl()) {
            return true;
        }

        return false;
    }

    private function repin(PackageInterface $package): void
    {
        $name = $package->getName();
        $version = $package->getPrettyVersion();
        $sourceReference = $package->getSourceReference();

        if ($sourceReference === null || $sourceReference === '') {
            throw new \RuntimeException(sprintf(
                "[Soak Time] Cannot re-pin mutable dev branch %s@%s.\n".
                "  Composer did not provide a source reference for the updated branch tip.\n".
                "  Disable integrity checks only if you intentionally accept unpinned updates.",
                $name,
                $version
            ));
        }

        $this->lockFile->record(new IntegrityEntry(
            $name,
            $version,
            null,
            $sourceReference,
            $package->getSourceUrl(),
            $package->getDistUrl(),
            new \DateTimeImmutable(),
        ));
        $this->lockFile->save();

        $this->io->write(sprintf(
            '<info>[Soak Time] Re-pinned mutable dev branch %s@%s (source ref %s…)</info>',
            $name,
            $version,
            substr($sourceReference, 0, 12)
        ), true, IOInterface::VERBOSE);
    }

    private function unobservedDistArchive(string $name, string $version): \RuntimeException
    {
        return new \RuntimeException(sprintf(
            "[Soak Time] No dist hash was pinned for %s@%s.\n".
            "  Composer installed this package from dist, but the active plugin did not observe the archive download.\n".
            "  Refusing to leave the installed version pinned only by metadata.\n".
            "  Reinstall from source so Composer verifies the source reference instead:\n".
            "    composer reinstall %s --prefer-source\n".
            "  For global plugin installs, use:\n".
            "    composer global reinstall %s --prefer-source",
            $name,
            $version,
            $name,
            $name
        ));
    }
}
