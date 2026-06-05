# Releasing

How to cut a release of this plugin. Each Craft major version has its own line:
`dev-craft-5` (develop) → `craft-5` (stable, where releases are tagged); later
`dev-craft-6` / `craft-6`, maintained in parallel. Tags are **bare semver**
(`1.1.0`, no `v` prefix). The git tag is the source of truth for Packagist.

## Steps

1. **Start the release** in Tower's Git-flow → creates `release/x.x.x` off the
   develop branch (`dev-craft-5`).

2. **Write the release notes** in `CHANGELOG.md` under the
   `## x.x.x - Unreleased` heading.

3. **Run the prepare script** on the release branch — *don't skip this*:

   ```bash
   ./scripts/prepare-release.sh        # infers version from the release/x.x.x branch name
   ```

   It bumps `version` in `composer.json` **and** dates the changelog heading
   (`Unreleased` → today). Commit the result.

4. **Finish the release** in Tower. It merges `release/x.x.x` into both the
   develop and stable branches, deletes the release branch, and **tags** the
   stable branch.

   ⚠️ Make sure **"Tag new version" is enabled** in Tower's finish-release
   dialog (or set `gitflow.prefix.versiontag` to empty so it tags bare
   `x.y.z`). If it's off, no tag is created and nothing publishes.

5. **Push** the stable branch, the develop branch, **and the tag**:

   ```bash
   git push origin craft-5
   git push origin dev-craft-5
   git push origin x.x.x
   ```

## What happens automatically on tag push

- `.github/workflows/release.yml` extracts that version's `CHANGELOG.md`
  section and creates the **GitHub Release**.
- The **Packagist** webhook publishes the new version (near-instant).

## Sanity checks

- `composer.json` `version` **must equal** the tag. The tag is permanent on
  Packagist, so verify before pushing it.
- Bump `schemaVersion` (main plugin class) **only** when migrations ship — it's
  independent of the plugin version.
- Don't bump versions on feature branches.
