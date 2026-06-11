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

        if (PackageFilter::isLocalPathPackage($package)) {
            $this->io->write(sprintf(
                '<info>[Soak Time] Skipping integrity pinning for %s@%s (local path repository).</info>',
                $name,
                $version
            ), true, IOInterface::VERBOSE);

            return;
        }

        $entry = $this->lockFile->lookup($name, $version);

        if ($entry !== null) {
            if ($this->mutableDevAdvanced($package, $entry)) {
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

    /**
     * Re-pinning overwrites recorded integrity evidence, so it is only safe for
     * a package declared as a mutable dev branch — elsewhere a changed reference
     * is tampering and must hard-fail. Only the unforgeable git SHA counts as an
     * advance; attacker-controlled URLs never trigger a re-pin.
     */
    private function mutableDevAdvanced(PackageInterface $package, IntegrityEntry $entry): bool
    {
        if (! $package->isDev() || ! PackageFilter::matchesWhitelist($package->getName(), $this->devBranches)) {
            return false;
        }

        return $entry->sourceReference !== null
            && $entry->sourceReference !== $package->getSourceReference();
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
