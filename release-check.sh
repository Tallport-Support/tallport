#!/usr/bin/env bash
# Check the committed dependencies and published assets before a release.

set -euo pipefail

cd "$(dirname "$0")"
source_dir=$PWD

# The files earlier releases shipped (System > Status > Files deletes them as leftovers).
php dev/removed-files.php --check

checkout_dir=$(mktemp -d "${TMPDIR:-/tmp}/tallport-release-check.XXXXXX")
cleanup() {
    if [ -d "$checkout_dir/source" ]; then
        git worktree remove --force "$checkout_dir/source" >/dev/null 2>&1 || true
    fi
    rm -rf "$checkout_dir"
}
trap cleanup EXIT

git worktree add --quiet --detach "$checkout_dir/source" HEAD
cp composer.json composer.lock "$checkout_dir/source/"
(
    cd "$checkout_dir/source"

    rm -rf vendor
    composer install --ignore-platform-reqs --no-interaction --no-progress

    if ! diff -qr "$source_dir/vendor" vendor; then
        echo "Committed vendor/ differs from composer install" >&2
        exit 1
    fi
    echo "Committed vendor/ matches composer install"

    for asset in fruitui livewire; do
        case "$asset" in
            fruitui) package_assets=vendor/fruitui/fruitui/build ;;
            livewire) package_assets=vendor/livewire/livewire/dist ;;
        esac

        if ! diff -qr "$package_assets" "$source_dir/public/vendor/$asset"; then
            echo "Published $asset assets differ from the installed package" >&2
            exit 1
        fi
    done
    echo "Published FruitUI and Livewire assets match the installed packages"
)

composer audit --locked --abandoned=fail
composer audit -d dev --locked --abandoned=fail
composer audit -d dev/boost --locked --abandoned=fail
