<?php

namespace Innobrain\SoakTime;

use Composer\Package\PackageInterface;

/**
 * Defends against the altered-historical-release attack (force-pushed tag
 * pointing at a new commit). The git SHA is content-addressed over the
 * commit's tree, parents, author, committer, message, and dates — backdating
 * `GIT_COMMITTER_DATE` to evade soak time still yields a different SHA, and
 * the SHA can't be forged. Load-bearing defense for this threat; PackageFilter
 * can't cover it because the soak timestamp is attacker-controlled.
 */
final class ReferenceDriftCheck
{
    public function __construct(private readonly IntegrityLockFile $lockFile) {}

    /**
     * Hard-fail if any candidate package@version has a recorded source.reference
     * that no longer matches what the pool offers. Cheap-and-early signal that
     * a tag was force-pushed.
     *
     * @param  iterable<PackageInterface>  $packages
     */
    public function verify(iterable $packages): void
    {
        foreach ($packages as $package) {
            $entry = $this->lockFile->lookup($package->getName(), $package->getPrettyVersion());

            if ($entry === null || $entry->sourceReference === null) {
                continue;
            }

            $candidate = $package->getSourceReference();

            if ($candidate === null || $candidate === '' || $candidate === $entry->sourceReference) {
                continue;
            }

            throw new \RuntimeException(sprintf(
                "[Soak Time] Source reference drift for %s@%s.\n".
                "  Recorded reference:  %s\n".
                "  Candidate reference: %s\n".
                "  The git commit for this exact version has moved since it was first installed.\n".
                "  This is the signature of a force-pushed tag (altered historical release).\n".
                "  Investigate before proceeding. To override after manual verification,\n".
                "  delete the entry from %s.",
                $package->getName(),
                $package->getPrettyVersion(),
                $entry->sourceReference,
                $candidate,
                $this->lockFile->path
            ));
        }
    }
}
