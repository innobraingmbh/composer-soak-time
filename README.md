[![Latest Version on Packagist](https://img.shields.io/packagist/v/innobrain/soak-time.svg?style=flat-square)](https://packagist.org/packages/innobrain/soak-time)
[![Total Downloads](https://img.shields.io/packagist/dt/innobrain/soak-time.svg?style=flat-square)](https://packagist.org/packages/innobrain/soak-time)

# Innobrain Soak Time 🛡️

A Composer plugin that enforces a **soak time** — a minimum age — on every package version before install. New releases stay out of the solver pool until they age past the threshold, blocking zero-day malicious releases (typosquats, account takeovers, malicious co-maintainer pushes).

A date filter alone is defeatable: an attacker can force-push an old tag at a malicious commit with a backdated `GIT_COMMITTER_DATE`, and Packagist serves that timestamp. Packagist.org locks the source and dist reference of **stable** versions and refuses moved tags ([composer/packagist#1742](https://github.com/composer/packagist/pull/1742), [docs](https://packagist.org/about/version-immutability)) — but only for stable versions on packagist.org. The plugin pins each version's git SHA, source URL, dist URL, and dist sha256 in `composer-integrity.lock`, extending that protection to dev versions, the local download cache, and non-packagist sources, and hard-fails on any later drift. See [SECURITY_MODEL.md](SECURITY_MODEL.md).

## 🧭 How it works

Four checks run on every install/update:

| Check | Hook | Catches |
|---|---|---|
| **Timestamp filter** (`PackageFilter`) | `PRE_POOL_CREATE` | Fresh malicious releases — drops versions younger than the soak time from the solver pool. |
| **Reference drift** (`ReferenceDriftCheck`) | `PRE_POOL_CREATE` | Altered historical releases — a backdated `GIT_COMMITTER_DATE` still changes the content-addressed SHA, which can't be forged. |
| **Hash pinning** (`HashVerifier`) | `POST_FILE_DOWNLOAD` | Cache poisoning at `~/.composer/cache/files/` — re-hashes the downloaded archive (Composer's native sha1 is empty for GitHub zips). |
| **Source pinning** (`PackageIntegrityRecorder`) | `POST_PACKAGE_INSTALL` / `POST_PACKAGE_UPDATE` | `--prefer-source` installs; fails closed if a dist install never exposes its archive. |

Pins are written to `composer-integrity.lock` when a version is first seen (trust-on-first-use) and verified on every later run.

## 📦 Installation

```bash
composer require --dev innobrain/soak-time   # project
composer global require innobrain/soak-time  # all local projects
```

> **Upgrading from ≤ v1.3.0?** `composer update` fails because the old `SoakTimeConfig` is still in PHP memory. Reinstall instead: `composer global remove innobrain/soak-time && composer global require innobrain/soak-time` (or the `--dev` equivalents).

## ⚙️ Configuration

Default soak time is **168h (7 days)**. Configure via `extra` in `composer.json`:

```json
{
    "extra": {
        "soak-time-hours": 168,
        "soak-time-whitelist": ["roave/security-advisories", "your-company/*"]
    }
}
```

- **Per-run override:** `SOAK_TIME_HOURS=336 composer update` (takes precedence; ignored with a warning if not a non-negative integer).
- **Whitelist** bypasses the soak filter for trusted packages that update constantly. `*` is allowed in the **name** half only — the vendor must be a literal (`your-company/*`, `your-company/lib-*`). Vendor-side wildcards (`*/x`, `*/*`, `*`) are rejected. `SOAK_TIME_SKIP` accepts the same patterns.
- Versions with no release date are filtered unless whitelisted. Whitelist path/internal repos only if you trust their metadata.

Windows PowerShell sets env vars as `$env:SOAK_TIME_HOURS=336; composer update`.

## 🔐 Integrity lock file

`composer-integrity.lock` records each version's `sha256` (when Composer exposes the archive), `sourceReference`, `sourceUrl`, `distUrl`, and `firstSeenAt`. **Commit it alongside `composer.lock`** — later installs verify against it and hard-fail on drift.

Packages from [`path` repositories](https://getcomposer.org/doc/05-repositories.md#path) are exempt from integrity pinning entirely: they are local code in the same trust domain as the root project, have no archive hash or source reference to pin, and would otherwise fail every install.

Some paths (including plugin self-update) install from dist without exposing the archive; the plugin then fails closed — fix with `composer global reinstall innobrain/soak-time --prefer-source`. Opt out (not recommended) with `soak-time-integrity: false`, or relocate via `soak-time-integrity-lock`:

```json
{
    "extra": {
        "soak-time-integrity": true,
        "soak-time-integrity-lock": "composer-integrity.lock"
    }
}
```

## 🚨 Emergency skip

Install a fresh security patch by skipping the freshness filter for one package (integrity checks still run):

```bash
SOAK_TIME_SKIP=vendor/package composer update vendor/package
```

`SOAK_TIME_SKIP=1` skips freshness for the whole run.

## 🔍 Troubleshooting

Run `composer update -v` to see dropped versions. If the soak time hides **every** version of a required package, resolution fails — the plugin names the package and its newest version's age up front. Fix by lowering `SOAK_TIME_HOURS`, whitelisting it, or a one-run `SOAK_TIME_SKIP`.

## 🙏 Credits & License

Fork of [`cotonet/soak-time`](https://github.com/cotonet-resiliencia-digital/composer-soak-time) by **Cotonet - Resiliência Digital**. MIT License — see [LICENSE](LICENSE). Copyright Cotonet - Resiliência Digital (original) and Innobrain GmbH (fork).
