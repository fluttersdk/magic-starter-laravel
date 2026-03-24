# Release

Release a new version of `fluttersdk/magic-starter-laravel` to Packagist via GitHub Release.

**Usage**: `/release <version>` (e.g., `/release 1.1.0`)

## Phase 1: Pre-flight Checks

1. Verify you are on the `master` branch: `git branch --show-current`
2. Verify working tree is clean: `git status --porcelain` should be empty
3. Verify no uncommitted changes: `git diff --cached --quiet`
4. Pull latest: `git pull origin master`

If any check fails, stop and inform the user.

## Phase 2: Version Bump

1. Update `CHANGELOG.md`:
   - Move all content under `## [Unreleased]` into a new section `## [<version>] - <today's date>`
   - Add a fresh empty `## [Unreleased]` section at the top
   - Preserve existing changelog entries below

## Phase 3: Quality Gates

Run all three checks — **all must pass**:

```bash
composer test        # PHPUnit test suite
composer lint        # Pint dry-run check
composer analyse     # PHPStan level 6
```

If any check fails, stop and fix before continuing. Do not skip.

## Phase 4: Commit

```bash
git add CHANGELOG.md
git commit -m "chore(release): <version>"
```

## Phase 5: Tag

```bash
git tag <version>
```

Use the exact version string (e.g., `1.1.0`), no `v` prefix.

## Phase 6: Push

```bash
git push origin master
git push origin <version>
```

Pushing the tag triggers `.github/workflows/publish.yml` which:
1. Runs the validate job (tests, lint, analyse)
2. Creates a GitHub Release with auto-generated release notes

## Phase 7: Verify

1. Confirm tag was pushed: `git ls-remote --tags origin | grep <version>`
2. Check GitHub Actions: `gh run list --workflow=publish.yml --limit=1`
3. Remind: Packagist auto-syncs via GitHub webhook. Verify at:
   - **GitHub**: https://github.com/fluttersdk/magic-starter-laravel/releases
   - **Packagist**: https://packagist.org/packages/fluttersdk/magic-starter-laravel

> **Note**: If Packagist doesn't update within a few minutes, check that the GitHub webhook is configured at https://github.com/fluttersdk/magic-starter-laravel/settings/hooks
