<?php

namespace Innobrain\SoakTime;

use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;

final class PackageIntegrityRecorder
{
    public function __construct(
        private readonly IntegrityLockFile $lockFile,
        private readonly IOInterface $io,
    ) {}

    public function record(PackageInterface $package): void
    {
        $name = $package->getName();
        $version = $package->getPrettyVersion();
        $entry = $this->lockFile->lookup($name, $version);

        if ($entry !== null) {
            (new ReferenceDriftCheck($this->lockFile))->verify([$package]);

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

    private function unobservedDistArchive(string $name, string $version): \RuntimeException
    {
        return new \RuntimeException(sprintf(
            "[Soak Time] No dist hash was pinned for %s@%s.\n".
            "  Composer installed this package from dist, but the active plugin did not observe the archive download.\n".
            "  Refusing to leave the installed version pinned only by metadata.\n".
            "  Re-run with source installs so Composer verifies the source reference instead:\n".
            "    composer update %s --prefer-source\n".
            "  For global plugin updates, use:\n".
            "    composer global update %s --prefer-source",
            $name,
            $version,
            $name,
            $name
        ));
    }
}
