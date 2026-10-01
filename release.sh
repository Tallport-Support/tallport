#!/usr/bin/env bash
#
# Publish a Tallport release that installations pick up through the built-in
# updater (System > Status > Update Now, or `php artisan tallport:update`).
#
#   ./release.sh ["release notes"] [extra gh release create options]
#   ./release.sh version ["release notes"] [extra gh release create options]
#   ./release.sh -m "release notes" ...
#
# Without a version, the last number of the current version is incremented
# (1.8.243.4 -> 1.8.243.5); give one only for a significant change.
# Release notes are optional: they go at the top of the GitHub release,
# above the list of changes GitHub generates, and into the release commit.
# Any argument that isn't a version number is taken as the notes.
#
# Extra options go to `gh release create`, e.g. --draft to review the release
# on GitHub before publishing it: installations only see published releases
# that are not marked as pre-release.
#
# This bumps 'version' in config/app.php only; 'compatibility_version' is the
# FreeScout version for FreeScout modules and is changed by hand.
#
# Only a commit that is pushed to origin/main and passed the "Tests" workflow
# can be released; the script waits for a CI run that is still in progress.

set -euo pipefail

cd "$(dirname "$0")"

current=$(sed -n "s/^[[:space:]]*'version'[[:space:]]*=>[[:space:]]*'\([^']*\)'.*/\1/p" config/app.php)
if [ -z "$current" ]; then
    echo "Cannot read the current version from config/app.php" >&2
    exit 1
fi

# Name the repository explicitly: with an upstream remote gh can't pick one.
repo=$(git remote get-url origin | sed -E 's#^(git@github\.com:|ssh://git@github\.com/|https://github\.com/)##; s#\.git$##')

version=""
notes=""
gh_options=()
while [ $# -gt 0 ]; do
    case "$1" in
        -m|--message)
            if [ $# -lt 2 ]; then
                echo "$1 needs the release notes as its value" >&2
                exit 1
            fi
            notes="$2"
            shift 2
            ;;
        -*)
            # Options for gh release create (with their values, if any).
            gh_options+=("$@")
            break
            ;;
        *)
            if [ -z "$version" ] && [ -z "$notes" ] && [[ "$1" =~ ^[0-9]+(\.[0-9]+)*$ ]]; then
                version="$1"
            elif [ -z "$notes" ]; then
                notes="$1"
            else
                echo "Unexpected argument: $1 (quote the release notes)" >&2
                exit 1
            fi
            shift
            ;;
    esac
done

if [ -z "$version" ]; then
    version=$(echo "$current" | awk -F. -v OFS=. '{ $NF++; print }')

    # A previous run committed "Release <current>" but didn't get to create the
    # GitHub release: finish that one instead of bumping again. Only a clear
    # "not found" counts; any other error (network, API) stops the script.
    if [ -n "$(git log --format=%H --grep="^Release $current\$" -1)" ]; then
        if ! view_output=$(gh release view "$current" --repo "$repo" 2>&1 >/dev/null); then
            if [ "$view_output" = "release not found" ]; then
                version="$current"
            else
                echo "Cannot check whether release $current exists on GitHub: $view_output" >&2
                exit 1
            fi
        fi
    fi
fi

if ! [[ "$version" =~ ^[0-9]+(\.[0-9]+)*$ ]]; then
    echo "Invalid version: $version" >&2
    exit 1
fi
if [ "$version" != "$current" ] && ! php -r 'exit(version_compare($argv[1], $argv[2], ">") ? 0 : 1);' "$version" "$current"; then
    echo "Version $version must be higher than the current version $current" >&2
    exit 1
fi

if [ "$(git rev-parse --abbrev-ref HEAD)" != "main" ]; then
    echo "Releases are made from main" >&2
    exit 1
fi
if [ -n "$(git status --porcelain)" ]; then
    echo "Commit or stash your changes first" >&2
    exit 1
fi
git fetch --quiet origin main
if [ "$(git rev-parse HEAD)" != "$(git rev-parse origin/main)" ]; then
    echo "main must match origin/main: push your commits and let CI test them first" >&2
    exit 1
fi

# Only release what CI tested: the latest "Tests" run for this commit must
# have passed. SKIP_CI=1 ./release.sh overrides this in an emergency.
if [ "${SKIP_CI:-}" = "1" ]; then
    echo "WARNING: releasing without checking CI (SKIP_CI=1)" >&2
else
    sha=$(git rev-parse HEAD)
    run=$(gh run list --repo "$repo" --workflow test.yml --commit "$sha" --limit 1 \
        --json databaseId,status,conclusion,url --jq '.[0] | select(.) | [.databaseId, .status, .conclusion, .url] | join("|")')
    if [ -z "$run" ]; then
        echo "No CI run found for ${sha:0:7}; wait for GitHub Actions to pick up the push" >&2
        exit 1
    fi
    IFS='|' read -r run_id run_status run_conclusion run_url <<< "$run"
    if [ "$run_status" != "completed" ]; then
        echo "Waiting for CI: $run_url"
        gh run watch "$run_id" --repo "$repo" --exit-status >/dev/null 2>&1 || true
        run_conclusion=$(gh run view "$run_id" --repo "$repo" --json conclusion --jq .conclusion)
    fi
    if [ "$run_conclusion" != "success" ]; then
        echo "CI did not pass for ${sha:0:7} ($run_conclusion): $run_url" >&2
        exit 1
    fi
    echo "CI passed for ${sha:0:7}"
fi

if [ "$version" = "$current" ]; then
    echo "Finishing release $version"
else
    echo "Releasing $current -> $version"
    sed -i "s/^\([[:space:]]*'version'[[:space:]]*=>[[:space:]]*'\)$current'/\1$version'/" config/app.php
    if [ -n "$notes" ]; then
        git commit --quiet -m "Release $version" -m "$notes" config/app.php
    else
        git commit --quiet -m "Release $version" config/app.php
    fi
fi

if [ -n "$notes" ]; then
    gh_options=(--notes "$notes" "${gh_options[@]}")
fi

git push --quiet origin main
gh release create "$version" --repo "$repo" --target main --title "Tallport $version" --generate-notes "${gh_options[@]}"
