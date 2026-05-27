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
- Dist installs are pinned by the downloaded archive's sha256, plus source and dist metadata when available.
- `SOAK_TIME_SKIP` bypasses only freshness filtering. Integrity checks still run.

## Trust Boundary

The first time a version is installed is trust-on-first-use. At that point the plugin records what Composer resolved and downloaded. After that, the recorded evidence becomes the local source of truth.

For this model to hold:

- Commit `composer-integrity.lock` with `composer.lock`.
- Review new integrity entries like dependency updates, especially when using `SOAK_TIME_SKIP`.
- Treat deletion or manual edits of integrity entries as a security override.

## Out Of Scope

This plugin does not prove that a package is benign. It does not replace code review, vulnerability scanning, provenance/signature systems, or maintainer trust. A malicious release that ages past the soak window can still be installed if it is otherwise selected by Composer.

The plugin also relies on Composer, the configured repositories, TLS transport, and local filesystem integrity during the first-seen install. Its job is to make later drift visible and to slow down newly published attacks.
