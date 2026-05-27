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
                throw new \RuntimeException(sprintf(
                    "[Soak Time] No recorded dist sha256 for %s@%s.\n".
                    "  This version was previously pinned without a dist archive.\n".
                    "  Refusing to trust a first-seen archive for an already-known version.\n".
                    "  To override after manual verification, delete the entry from %s.",
                    $name,
                    $version,
                    $this->lockFile->path
                ));
            }

            return;
        }

        if ($package->getInstallationSource() === 'dist') {
            throw new \RuntimeException(sprintf(
                "[Soak Time] No dist hash was pinned for %s@%s.\n".
                "  Composer installed this package from dist, but post-file-download did not record it.\n".
                "  Refusing to leave the installed version unpinned.",
                $name,
                $version
            ));
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

        $this->lockFile->record(new IntegrityEntry(
            $name,
            $version,
            null,
            $sourceReference,
            $package->getSourceUrl(),
            $package->getDistUrl(),
            new \DateTimeImmutable(),
        ));

        $this->io->write(sprintf(
            '<info>[Soak Time] Pinned %s@%s (source ref %s…)</info>',
            $name,
            $version,
            substr($sourceReference, 0, 12)
        ), true, IOInterface::VERBOSE);
    }
}
