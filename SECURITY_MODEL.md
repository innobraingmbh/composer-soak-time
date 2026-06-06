# Security Model

`innobrain/soak-time` protects Composer updates against two supply-chain failure modes:

- A fresh malicious release that has not had time to be noticed.
- An existing version whose source reference, source URL, dist URL, or archive contents change after it was already trusted.

The plugin does this with two layers. First, it removes package versions newer than the configured soak time from Composer's solver pool. Second, it records integrity evidence for every installed `name@version` in `composer-integrity.lock` and verifies that evidence on later installs.

## What Is Enforced

- Recent package versions are filtered unless whitelisted.
- Versions with no release date are filtered unless whitelisted, except Composer platform packages such as `php` and `ext-*`.
- Existing lock entries are fail-closed: malformed or incomplete integrity data stops the run.
- Source installs are pinned by source reference and source URL.
- Dist downloads are pinned by the archive's sha256 when Composer exposes the archive to the plugin. If Composer installs from dist without exposing the archive, the run fails closed and the package must be reinstalled with `--prefer-source`.
- `SOAK_TIME_SKIP` bypasses only freshness filtering. Integrity checks still run.

## Relationship to Packagist Version Immutability

Since June 2026, Packagist.org locks the source and dist reference of each stable version once published and refuses to follow a moved or rewritten upstream tag ([composer/packagist#1742](https://github.com/composer/packagist/pull/1742), [docs](https://packagist.org/about/version-immutability)). This closes the force-pushed-tag attack upstream for packagist.org stable releases — the same threat the reference pin addresses.

The plugin's checks are not redundant with it. They still cover what immutability does not:

- Dev versions (`dev-*`, `*-dev`), which remain mutable by design.
- Fresh releases, which a locked reference does nothing to delay.
- Local cache poisoning under `~/.composer/cache/files/`, which is client-side.
- Packagist bugs and admin takedown/soft-delete paths, plus non-packagist sources (Satis, private Packagist, path/VCS repos, mirrors) that have no such gate.

For packagist.org stable versions the reference pin is now defense-in-depth on top of the registry; everywhere else it remains the primary guard.

## Trust Boundary

The first time a version is installed is trust-on-first-use. At that point the plugin records what Composer resolved and downloaded. After that, the recorded evidence becomes the local source of truth.

For this model to hold:

- Commit `composer-integrity.lock` with `composer.lock`.
- Review new integrity entries like dependency updates, especially when using `SOAK_TIME_SKIP`.
- Treat deletion or manual edits of integrity entries as a security override.

## Out Of Scope

This plugin does not prove that a package is benign. It does not replace code review, vulnerability scanning, provenance/signature systems, or maintainer trust. A malicious release that ages past the soak window can still be installed if it is otherwise selected by Composer.

The plugin also relies on Composer, the configured repositories, TLS transport, and local filesystem integrity during the first-seen install. Its job is to make later drift visible and to slow down newly published attacks.
