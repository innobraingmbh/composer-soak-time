<?php

namespace Innobrain\SoakTime;

use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Plugin\PostFileDownloadEvent;

/**
 * Second layer beneath ReferenceDriftCheck. Catches cache poisoning at
 * `~/.composer/cache/files/`: Composer's fast-cache branch reuses a
 * previously-downloaded zip without re-fetching, and its native sha1 check
 * is empty for GitHub-served packages (`dist.shasum` is blank there).
 * POST_FILE_DOWNLOAD fires for both cache and network paths, so this check
 * runs either way.
 */
final class HashVerifier
{
    /**
     * @param  list<string>  $devBranches  Package-name patterns declared as mutable dev branches.
     */
    public function __construct(
        private readonly IntegrityLockFile $lockFile,
        private readonly IOInterface $io,
        private readonly array $devBranches = [],
    ) {}

    public function verify(PostFileDownloadEvent $event): void
    {
        if ($event->getType() !== 'package') {
            return;
        }

        $package = $event->getContext();

        if (! $package instanceof PackageInterface) {
            return;
        }

        $fileName = $event->getFileName();

        if ($fileName === null || ! is_file($fileName)) {
            throw new \RuntimeException(sprintf(
                '[Soak Time] Cannot verify package download for %s@%s because Composer did not provide a readable file.',
                $package->getName(),
                $package->getPrettyVersion()
            ));
        }

        // PostFileDownloadEvent::getChecksum() returns the DECLARED sha1 from
        // metadata (empty for GitHub/GitLab dist URLs), so compute the real
        // digest ourselves.
        $computed = hash_file('sha256', $fileName);

        if ($computed === false) {
            throw new \RuntimeException(sprintf(
                '[Soak Time] Cannot compute sha256 for downloaded package file: %s',
                $fileName
            ));
        }

        $name = $package->getName();
        $version = $package->getPrettyVersion();
        $entry = $this->lockFile->lookup($name, $version);

        if ($entry !== null) {
            if ($this->mutableDevAdvanced($package, $entry)) {
                $this->repin($package, $computed);

                return;
            }

            (new ReferenceDriftCheck($this->lockFile, $this->devBranches))->verify([$package]);

            if ($entry->sha256 === null) {
                throw new \RuntimeException(sprintf(
                    "[Soak Time] No recorded dist sha256 for %s@%s.\n".
                    "  This version was previously pinned without observing a dist archive.\n".
                    "  Refusing to trust a later first-seen archive for an already-known version.\n".
                    "  Reinstall from source so Composer verifies the recorded source reference:\n".
                    "    composer reinstall %s --prefer-source\n".
                    "  For global plugin installs, use:\n".
                    "    composer global reinstall %s --prefer-source\n".
                    "  To override after manual verification, delete the entry from %s.",
                    $name,
                    $version,
                    $name,
                    $name,
                    $this->lockFile->path
                ));
            }

            if (hash_equals($entry->sha256, $computed)) {
                return;
            }

            throw new \RuntimeException(sprintf(
                "[Soak Time] Integrity check FAILED for %s@%s.\n".
                "  Recorded sha256: %s\n".
                "  Downloaded sha256: %s\n".
                "  This means the dist for this exact version has changed since it was first installed.\n".
                "  That is the signature of an altered historical release. Investigate before proceeding.\n".
                "  To override after manual verification, delete the entry from %s.",
                $name,
                $version,
                $entry->sha256,
                $computed,
                $this->lockFile->path
            ));
        }

        $newEntry = new IntegrityEntry(
            $name,
            $version,
            $computed,
            $package->getSourceReference(),
            $package->getSourceUrl(),
            $package->getDistUrl(),
            new \DateTimeImmutable(),
        );

        $this->lockFile->record($newEntry);
        $this->lockFile->save();

        $this->io->write(sprintf(
            '<info>[Soak Time] Pinned %s@%s (sha256 %s…)</info>',
            $name,
            $version,
            substr($computed, 0, 12)
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

    private function repin(PackageInterface $package, string $sha256): void
    {
        $name = $package->getName();
        $version = $package->getPrettyVersion();

        $newEntry = new IntegrityEntry(
            $name,
            $version,
            $sha256,
            $package->getSourceReference(),
            $package->getSourceUrl(),
            $package->getDistUrl(),
            new \DateTimeImmutable(),
        );

        $this->lockFile->record($newEntry);
        $this->lockFile->save();

        $this->io->write(sprintf(
            '<info>[Soak Time] Re-pinned mutable dev branch %s@%s (sha256 %s…)</info>',
            $name,
            $version,
            substr($sha256, 0, 12)
        ), true, IOInterface::VERBOSE);
    }
}
