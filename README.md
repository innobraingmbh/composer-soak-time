# Innobrain Soak Time 🛡️

A Composer plugin that enforces a **soak time** — a minimum age — on every package version before install. New releases stay out of the solver pool until they age past the threshold, blocking zero-day malicious releases: typosquats, account takeovers, malicious co-maintainer pushes.

A release-date filter alone is defeatable: an attacker can force-push an *old* tag at a new malicious commit with a backdated `GIT_COMMITTER_DATE`, and Packagist serves the backdated timestamp. So the plugin also pins the **git SHA**, source URL, dist URL, and **dist sha256** of every version it sees. Those can't be backdated. If `vendor/pkg@1.2.3` later resolves to different integrity metadata or a different zip hash than the recorded one, the install hard-fails.

One product — soak time — done in a way that holds against both fresh malicious releases and altered historical releases (the laravel-lang / intercom-php 2026 pattern).

See [SECURITY_MODEL.md](SECURITY_MODEL.md) for the short threat model, guarantees, and limits.

## 🧭 How soak time stays honest

Three checks run on every install/update. Together they make soak time undefeatable by `GIT_COMMITTER_DATE` rewrites.

| Check | Hook | Catches | Why it works |
|---|---|---|---|
| **Timestamp filter** (`PackageFilter`) | `PRE_POOL_CREATE` | Fresh malicious releases | Drops recent versions from the solver pool until they reach minimum age. |
| **Reference drift** (`ReferenceDriftCheck`) | `PRE_POOL_CREATE` | Altered historical releases (force-pushed tag) | The git SHA is content-addressed over the commit's tree, parents, author, committer, message, *and dates*. Backdating `GIT_COMMITTER_DATE` still yields a different SHA, and the SHA can't be forged. |
| **Hash pinning** (`HashVerifier`) | `POST_FILE_DOWNLOAD` | Cache poisoning at `~/.composer/cache/files/` | Composer's native sha1 check is empty for GitHub-served packages; when Composer exposes the downloaded archive, we compute sha256 ourselves and compare against the pinned value. |
| **Source install pinning** (`PackageIntegrityRecorder`) | `POST_PACKAGE_INSTALL` / `POST_PACKAGE_UPDATE` | `--prefer-source` and source-only installs | If no dist archive is downloaded, the installed source reference is pinned and later checked. If Composer installs from dist without exposing the archive to the plugin, the run fails closed. |

The timestamp filter alone is **not** enough — the SHA/source-reference pin is the load-bearing primitive against altered historical releases. New pins are written to `composer-integrity.lock` as they are observed and saved again at `POST_INSTALL_CMD` / `POST_UPDATE_CMD`.

## 📦 Installation

Install as a dev dependency:

```bash
composer require --dev innobrain/soak-time
```

Or install globally to protect all local projects:

```bash
composer global require innobrain/soak-time
```

> **Upgrading from ≤ v1.3.0?** A direct `composer update` will fail because the old plugin's `SoakTimeConfig` class is still in PHP memory when the new code activates. Reinstall instead:
>
> ```bash
> composer global remove innobrain/soak-time && composer global require innobrain/soak-time
> # or, for project-local installs:
> composer remove --dev innobrain/soak-time && composer require --dev innobrain/soak-time
> ```

## ⚙️ Configuration

Default minimum age: **168 hours (7 days)**. Override in the `extra` section of `composer.json`:

```json
{
    "extra": {
        "soak-time-hours": 360
    }
}
```

### Overriding the Soak Time per Run

Override the configured soak time for a single run via the `SOAK_TIME_HOURS` environment variable (hours; takes precedence over `composer.json`):

**Linux / macOS:**
```bash
SOAK_TIME_HOURS=336 composer update
```

**Windows (PowerShell):**
```powershell
$env:SOAK_TIME_HOURS=336; composer update
```

If the value isn't a non-negative integer (e.g. a typo), the plugin ignores it and warns rather than silently disabling protection.

### Whitelisting Packages

Some packages need to update constantly — security advisories, internal company packages — and should bypass the soak filter. Whitelist them permanently via `soak-time-whitelist`:

```json
{
    "extra": {
        "soak-time-hours": 168,
        "soak-time-whitelist": [
            "roave/security-advisories",
            "your-company/internal-package"
        ]
    }
}
```

Package versions with no release date are filtered out unless whitelisted. Add path repositories or internal repositories to the whitelist only when you intentionally trust their release metadata outside Packagist.

## 🔐 Integrity lock file

The plugin maintains `composer-integrity.lock` next to `composer.json`. For every package version installed, it records:

- `sha256` of the downloaded zip, when Composer exposes the archive to the plugin
- `sourceReference` (git commit SHA)
- `sourceUrl`
- `distUrl`
- `firstSeenAt` timestamp

**Commit this file alongside `composer.lock`.** On every subsequent install, the SHA, source reference, source URL, and dist URL for each `name@version` are verified against the recorded values. Any drift hard-fails the run — the signal of an altered historical release.

Opt out (not recommended) with `extra.soak-time-integrity: false`, or relocate the file via `extra.soak-time-integrity-lock`.

The first time a version is seen is trust-on-first-use. Review and commit the generated integrity lock with the same care as `composer.lock`; subsequent installs enforce the recorded evidence.

Some Composer paths, including plugin self-updates, can install from dist without delivering the archive to the active plugin instance. In that case the plugin fails closed. Re-run the command with `--prefer-source`, for example:

```bash
composer global update innobrain/soak-time --prefer-source
```

```json
{
    "extra": {
        "soak-time-integrity": true,
        "soak-time-integrity-lock": "composer-integrity.lock"
    }
}
```

## 🚨 Emergency Freshness Skip (Security Patches)

To install a critical security patch released hours ago, skip the freshness filter for that package with `SOAK_TIME_SKIP`:

**Linux / macOS:**
```bash
SOAK_TIME_SKIP=vendor/package-name composer update vendor/package-name
```

**Windows (PowerShell):**
```powershell
$env:SOAK_TIME_SKIP="vendor/package-name"; composer update vendor/package-name
```

`SOAK_TIME_SKIP=1` still skips the freshness filter for the whole run, but integrity checks remain enabled. Use the package-name form for emergency patches so the rest of the pool still soaks normally.

## 🔍 Debugging

Run with `-v` to see which versions are dropped. Output is grouped per package:

```bash
composer update -v
```

```
[Soak Time] Inspecting packages (minimum age: 168h)...
  monolog/monolog — dropped 3 version(s) newer than 168h: 3.8.2, 3.8.3, 3.8.4 (newest released 2026-05-20 14:30)
[Soak Time] Filtered out 3 recent version(s) across 1 package(s).
```

## 🧩 When Resolution Fails

If the soak time hides **every** available version of a required package, Composer can't resolve dependencies. The plugin detects this up front and names the responsible package along with its latest version's age, instead of leaving a cryptic solver error:

```
[Soak Time] Every version of "vendor/package" was filtered out (newest is only 6h old; soak time is 168h).
            If Composer now fails to resolve dependencies, this is the likely cause. Options:
              - Lower the soak time:  SOAK_TIME_HOURS=6 composer update
              - Whitelist it:         add "vendor/package" to extra.soak-time-whitelist
              - One-run skip:         SOAK_TIME_SKIP=vendor/package composer update
```

Whitelist the package if it legitimately needs frequent updates; use a one-run skip for an emergency patch.

## 🙏 Credits

Fork of [`cotonet/soak-time`](https://github.com/cotonet-resiliencia-digital/composer-soak-time) by **Cotonet - Resiliência Digital**. Thanks to the original authors for the foundation this fork builds on.

## 📄 License

MIT License — see [LICENSE](LICENSE).

Copyright Cotonet - Resiliência Digital (original) and Innobrain GmbH (fork).
