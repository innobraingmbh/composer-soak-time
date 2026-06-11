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
    /**
     * @param  list<string>  $devBranches  Package-name patterns declared as mutable dev branches.
     */
    public function __construct(
        private readonly IntegrityLockFile $lockFile,
        private readonly array $devBranches = [],
    ) {}

    /**
     * Hard-fail if any candidate package@version no longer matches recorded
     * integrity metadata. This is the cheap-and-early signal that a tag,
     * source repository, or dist URL moved before Composer downloads code.
     *
     * @param  iterable<PackageInterface>  $packages
     */
    public function verify(iterable $packages): void
    {
        foreach ($packages as $package) {
            // Local path repositories live in the same trust domain as the
            // root project and carry no immutable reference to defend. The dist
            // type alone is spoofable, so confirm the URL is actually local.
            if (PackageFilter::isLocalPathPackage($package)) {
                continue;
            }

            $entry = $this->lockFile->lookup($package->getName(), $package->getPrettyVersion());

            if ($entry === null) {
                continue;
            }

            // Declared mutable dev branches may legitimately advance their reference.
            if ($package->isDev() && PackageFilter::matchesWhitelist($package->getName(), $this->devBranches)) {
                continue;
            }

            $this->assertMatches($package, 'source reference', $entry->sourceReference, $package->getSourceReference());
            $this->assertMatches($package, 'source URL', $entry->sourceUrl, $package->getSourceUrl());
            $this->assertMatches($package, 'dist URL', $entry->distUrl, $package->getDistUrl());
        }
    }

    private function assertMatches(
        PackageInterface $package,
        string $field,
        ?string $recorded,
        ?string $candidate,
    ): void {
        if ($recorded === null) {
            return;
        }

        if ($candidate === $recorded) {
            return;
        }

        $isDev = $package->isDev();

        throw new \RuntimeException(sprintf(
            "[Soak Time] Integrity metadata drift for %s@%s.\n".
            "  Field:     %s\n".
            "  Recorded:  %s\n".
            "  Candidate: %s\n".
            ($isDev
                ? "  This is a dev (mutable) version whose reference has changed since it was first installed.\n".
                  "  If this change is expected (e.g. the branch advanced), declare the package as a\n".
                  "  mutable dev branch in composer.json:\n".
                  "    \"extra\": { \"soak-time-dev-branches\": [\"%s\"] }\n".
                  "  To override after manual verification, delete the entry from %s."
                : "  The metadata for this exact version has changed since it was first installed.\n".
                  "  This is the signature of an altered historical release or repository rewrite.\n".
                  "  Investigate before proceeding. To override after manual verification,\n".
                  "  delete the entry from %s."),
            $package->getName(),
            $package->getPrettyVersion(),
            $field,
            $recorded,
            $candidate ?? '<missing>',
            ...($isDev ? [$package->getName(), $this->lockFile->path] : [$this->lockFile->path])
        ));
    }
}
