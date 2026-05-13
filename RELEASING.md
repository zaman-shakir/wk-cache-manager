# Releasing WK Cache Manager

How to publish a new version. Every release is auto-built and auto-distributed to every site running the plugin via the GitHub-based update checker. No manual zip uploads, no SFTP.

## TL;DR

```bash
cd wk-cache-manager
# 1. Bump version in plugin header
sed -i '' 's/Version: 3.9/Version: 4.0/' wk-cache-manager.php

# 2. Commit + tag + push
git add -A
git commit -m "v4.0: short description"
git tag v4.0
git push origin main
git push origin v4.0
```

Done. GitHub Actions builds the zip, creates the release, attaches the zip. Sites pick it up within 12 h (or instantly via `?force-check=1`).

---

## Prerequisites (one-time)

- Local git config in this repo points to:
  - `user.name = zaman-shakir`
  - `user.email = zamanshakirdev@gmail.com`
- Origin remote: `https://github.com/zaman-shakir/wk-cache-manager.git`
- You can push to `main` and create tags.

Verify:

```bash
git config user.name        # → zaman-shakir
git config user.email       # → zamanshakirdev@gmail.com
git remote -v               # → origin → zaman-shakir/wk-cache-manager
```

If not set:

```bash
git config user.name "zaman-shakir"
git config user.email "zamanshakirdev@gmail.com"
```

---

## Versioning

- Use **`MAJOR.MINOR`** in the plugin header (e.g. `3.9`, `4.0`, `4.1`).
- Tag name **must** be `v` + version (e.g. `v3.9`, `v4.0`).
- The CI workflow rejects mismatches between header version and tag name.
- Bump rules of thumb:
  - **Patch / minor fix** → bump the minor number (e.g. `3.9 → 3.10`).
  - **Breaking change or large feature** → bump the major (e.g. `3.10 → 4.0`).
  - Never reuse a version number. Never delete a tag.

---

## Step-by-step

### 1. Make changes on `main`

Edit code. Run local syntax check before committing:

```bash
find src -name "*.php" -print0 | xargs -0 -n1 php -l | grep -v "No syntax"
```

### 2. Bump the version

Open `wk-cache-manager.php` and update the `Version:` header line. There is exactly **one** place to update.

```php
 * Version: 4.0
```

### 3. Commit

```bash
git add -A
git commit -m "v4.0: human-readable summary"
```

Commit message tips:
- First line ≤ 60 chars.
- Mention the version up front.
- Body (optional) — bullet list of changes.

### 4. Tag and push

```bash
git tag v4.0
git push origin main
git push origin v4.0
```

> Tip: push the branch first, then the tag. If CI fails on `main` because of a typo, you can fix it and re-tag *before* the release goes out.

### 5. Wait ~30 s

GitHub Actions builds the zip and publishes the release.

Track the run: https://github.com/zaman-shakir/wk-cache-manager/actions

When the run is green, check:

- https://github.com/zaman-shakir/wk-cache-manager/releases/tag/v4.0
- The release should have one asset: `wk-cache-manager.zip` (~260 kB)

### 6. (Optional) Verify on a live site

```
https://your-site.example/wp-admin/plugins.php?force-check=1
```

You should see "Update available — version 4.0" within a couple of minutes.

---

## What the GitHub Action does

File: `.github/workflows/release.yml`

On every `v*` tag push:

1. Checks out the repo at that tag.
2. Reads `Version:` from `wk-cache-manager.php`.
3. **Sanity check**: aborts if `${TAG#v}` ≠ header version.
4. Builds zip:
   - Folder structure: `wk-cache-manager/...` (extracts cleanly into `wp-content/plugins/`)
   - Excludes: `.git`, `.github`, `*.log`, `.DS_Store`, `.gitignore`
   - Includes: `vendor/plugin-update-checker/` (required for the auto-updater to keep working post-update)
5. Creates / updates the GitHub Release with the matching tag.
6. Uploads `wk-cache-manager.zip` as a release asset.

The asset URL stays stable for every release:

```
https://github.com/zaman-shakir/wk-cache-manager/releases/download/v<X.Y>/wk-cache-manager.zip
```

This is the URL the plugin-update-checker library hands to WordPress when a site clicks "Update now".

---

## How sites get the update

Each install runs the **plugin-update-checker** library (bundled in `vendor/`). It is wired in `wk-cache-manager.php`:

```php
$GLOBALS['wkcm_puc'] = PucFactory::buildUpdateChecker(
    'https://github.com/zaman-shakir/wk-cache-manager/',
    __FILE__,
    'wk-cache-manager'
);
$GLOBALS['wkcm_puc']->getVcsApi()->enableReleaseAssets();
```

Behavior:

- Polls the GitHub `releases/latest` endpoint every 12 h.
- Compares the latest release tag (with `v` prefix stripped) against the installed `Version:` header.
- If a newer version is found, hooks into WordPress's `update_plugins` transient — same path used by wordpress.org plugins.
- "Update available" notice appears in the **Plugins** screen.
- "Update now" downloads the zip attached to that release and lets WP do its normal in-place upgrade.

> No auto-update. Operators see the notice and decide when to click "Update now".

### Forcing a fresh check

Append to any admin URL:

- `?force-check=1` — tells WordPress to recheck all update sources (~5 s).
- `?puc_check_for_updates=1&puc_slug=wk-cache-manager` — tells the update checker to recheck *just this plugin*.

---

## Installing on a brand-new site

1. Download the latest zip:

   ```
   https://github.com/zaman-shakir/wk-cache-manager/releases/latest/download/wk-cache-manager.zip
   ```

2. WP admin → **Plugins → Add New → Upload Plugin** → select zip → **Install Now** → **Activate**.

3. From this point the site receives updates automatically through the WP Plugins page.

If the host blocks `update.php?action=upload-plugin` (some cPanel + ModSecurity setups do), upload the unzipped folder via SFTP to `/wp-content/plugins/wk-cache-manager/` instead, then activate from Plugins.

---

## Rollback

If a release breaks installs:

1. **Don't** delete the release or tag. Sites that already auto-updated would re-check and either error or downgrade unexpectedly.
2. Cut a *new* release with the previous good code:
   ```bash
   git revert --no-edit <bad-commit>
   sed -i '' 's/Version: 4.0/Version: 4.1/' wk-cache-manager.php
   git add -A && git commit -m "v4.1: revert v4.0 — <reason>"
   git tag v4.1
   git push origin main && git push origin v4.1
   ```
3. Sites pick up v4.1, which contains v3.9 code. Forward-only.

---

## Troubleshooting

**Workflow run failed with "Tag $TAG does not match plugin header version".**
You tagged `v4.0` but the header still says `3.9`. Delete the tag locally and remotely, fix the header, re-tag:

```bash
git tag -d v4.0
git push origin :refs/tags/v4.0
# fix header, commit, then re-tag
```

**Release exists but zip is missing.**
Check the Action run logs. Most common cause: zip build step failed (missing rsync, permissions). Re-run the workflow from the Actions tab.

**Sites still see the old version after release published.**
PUC throttles checks to once per 12 h per site. To force:

- Visit `/wp-admin/plugins.php?force-check=1`, or
- Visit `/wp-admin/plugins.php?puc_check_for_updates=1&puc_slug=wk-cache-manager`

**"Update now" times out / fails on a specific site.**
The site's host is blocking outbound HTTPS to `github.com` or the upgrade route on `update.php` is firewalled. Workarounds:

- Whitelist `github.com` and `objects.githubusercontent.com` in the host's egress rules.
- Or upload the zip via SFTP / file manager.

**Auto-updater stopped working after a release.**
Check that `vendor/plugin-update-checker/` is **inside** the release zip. The workflow includes it; if you ever switch the build step, keep that path.

---

## Releases that should NOT trigger an update notice

- Tags that don't start with `v` (e.g. `internal-cleanup`) — the workflow won't run.
- GitHub Releases marked as **pre-release** or **draft** — PUC's `releases/latest` endpoint ignores them.

Use pre-release for staging-only builds: tag `v4.0-rc1`, then create the release manually with the pre-release flag.

---

## File map (for future maintainers)

| Path | Purpose |
|------|---------|
| `wk-cache-manager.php` | Plugin entry. `Version:` header is the source of truth. Updater wiring lives here. |
| `vendor/plugin-update-checker/` | Bundled YahnisElsts library. Don't edit. Upgrade by replacing the folder. |
| `.github/workflows/release.yml` | Build + release automation. |
| `RELEASING.md` | This file. |
