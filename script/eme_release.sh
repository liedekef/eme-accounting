#!/bin/bash
# =============================================================================
# EME Accounting — GitHub release script
#
# Usage:
#   bash script/eme_release.sh <old_version> <new_version>
#   bash script/eme_release.sh 1.0.0 1.0.1
#
# What it does:
#   1. PHP syntax check of all plugin sources
#   2. Refuses to run with a dirty git working tree
#   3. Bumps Version (plugin header + constant) and Stable tag
#   4. Builds dist/<plugin>.zip
#   5. Commits, pushes and creates the github release v<version>
#
# Prerequisites: php, zip, git, gh (authenticated)
# =============================================================================

set -eu

plugin="eme-accounting"
main_file="${plugin}.php"
old_release="${1:-}"
release="${2:-}"

if [ -z "$old_release" ] || [ -z "$release" ]; then
    echo "Usage: $0 <old version number> <new version number>"
    exit 1
fi

if [ "$old_release" = "$release" ]; then
    echo "Old and new version number are identical."
    exit 1
fi

scriptpath=$(realpath "$0")
scriptdir=$(dirname "$scriptpath")
basedir=$(dirname "$scriptdir")
cd "$basedir"

# --- 1. syntax check of all plugin php files (vendor/dist are not our code) ---
has_error=0
while IFS= read -r file; do
    echo "Checking: $file"
    if ! php -l "$file" > /dev/null; then
        echo "Syntax error in file: $file"
        has_error=1
    fi
done < <(find . -name '*.php' -not -path './.git/*' -not -path './dist/*' -not -path './vendor/*' | sort)

if [ "$has_error" -eq 1 ]; then
    echo "Some PHP files have syntax errors."
    exit 1
fi

# --- 2. the git tree must be clean, otherwise the tag would not match the zip -
if [ -n "$(git status --porcelain)" ]; then
    echo "Working tree is not clean, commit (or stash) your changes first:"
    echo ""
    git status --short
    exit 1
fi

if git rev-parse -q --verify "refs/tags/v${release}" > /dev/null; then
    echo "Tag v${release} already exists."
    exit 1
fi

# --- 3. update the release version -------------------------------------------
if ! grep -qE "^ \* Version:[[:space:]]*$old_release$" "$main_file"; then
    echo "Version $old_release not found in $main_file"
    exit 1
fi
if ! grep -qE "^Stable tag: $old_release$" readme.txt; then
    echo "Stable tag $old_release not found in readme.txt"
    exit 1
fi

sed -i "s/$old_release/$release/" "$main_file"
sed -i "s/^Stable tag: $old_release$/Stable tag: $release/" readme.txt

echo "Version bumped to $release:"
grep -E "Version:|EME_ACCOUNTING_VERSION" "$main_file"
grep -E "^Stable tag:" readme.txt

# --- 4. create a zip of the new release ---------------------------------------
rm -f "$basedir/../${plugin}.zip"
cd "$basedir/.."
zip -r "${plugin}.zip" "$plugin" -x '*.git*' "$plugin/dist*" "$plugin/script*" "$plugin/*.md" '*.swp' '*.DS_Store'
mkdir -p "$basedir/dist"
mv "${plugin}.zip" "$basedir/dist/"

if ! unzip -l "$basedir/dist/${plugin}.zip" | grep -q " $plugin/$main_file"; then
    echo "$plugin/$main_file missing inside the zip, aborting."
    exit 1
fi

# --- 5. commit, push and create the github release ----------------------------
cd "$basedir"
git commit -m "release $release" -a
git push
gh release create "v${release}" --generate-notes --title "v${release}" "./dist/${plugin}.zip"

echo ""
echo "Release $release done: https://github.com/liedekef/${plugin}/releases/tag/v${release}"
