#!/usr/bin/env bash
# ==========================================================================
# AI Forge Dev Tools — Release Script
#
# Usage:
#   bash release.sh              Package current version
#   bash release.sh 0.2.0        Bump version, build, package, commit + tag
#
# Output: dist/wp-ai-forge-devtools-<version>.zip
# ==========================================================================

set -euo pipefail

SLUG="wp-ai-forge-devtools"
MAIN_FILE="$SLUG.php"
DIST_DIR="dist"

# --- Colors & helpers -----------------------------------------------------

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BOLD='\033[1m'
NC='\033[0m'

info()  { echo -e "  ${GREEN}✓${NC} $1"; }
warn()  { echo -e "  ${YELLOW}!${NC} $1"; }
error() { echo -e "  ${RED}✗${NC} $1" >&2; exit 1; }
step()  { echo -e "\n${BOLD}→ $1${NC}"; }

# --- Ensure we're at the plugin root -------------------------------------

if [[ ! -f "$MAIN_FILE" ]]; then
    error "Must be run from the plugin root (where $MAIN_FILE is)"
fi

# --- Version helpers ------------------------------------------------------

current_version() {
    grep -m1 "Version:" "$MAIN_FILE" | sed 's/.*Version:[[:space:]]*//' | tr -d '[:space:]'
}

bump_version() {
    local new_version="$1"
    local old_version
    old_version=$(current_version)

    if [[ "$old_version" == "$new_version" ]]; then
        warn "Version is already $new_version, skipping bump"
        return 0
    fi

    sed -i "s/Version:[[:space:]]*${old_version}/Version: ${new_version}/" "$MAIN_FILE"

    if [[ -f "admin/package.json" ]]; then
        sed -i "s/\"version\": \"${old_version}\"/\"version\": \"${new_version}\"/" "admin/package.json"
    fi

    info "Version bumped: ${old_version} → ${new_version}"
}

# --- Zip (zip or PowerShell fallback) ------------------------------------

create_zip() {
    local source_dir="$1"
    local zip_file="$2"

    if command -v zip &>/dev/null; then
        (cd "$DIST_DIR" && zip -rq "$(basename "$zip_file")" "$SLUG")
    elif command -v powershell.exe &>/dev/null; then
        local win_source win_zip
        win_source=$(cygpath -w "$source_dir" 2>/dev/null || echo "$source_dir")
        win_zip=$(cygpath -w "$zip_file" 2>/dev/null || echo "$zip_file")
        powershell.exe -NoProfile -Command \
            "Compress-Archive -Path '$win_source' -DestinationPath '$win_zip' -Force"
    else
        error "Neither 'zip' nor PowerShell available"
    fi
}

# ==========================================================================
# Main
# ==========================================================================

VERSION_ARG="${1:-}"
IS_RELEASE=false

if [[ -n "$VERSION_ARG" ]]; then
    if ! [[ "$VERSION_ARG" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
        error "Invalid version format: $VERSION_ARG (expected: X.Y.Z)"
    fi
    IS_RELEASE=true
fi

echo -e "${BOLD}Releasing ${SLUG}${NC}"

# --- Pre-flight checks ----------------------------------------------------

if [[ "$IS_RELEASE" == true ]]; then
    if [[ -n "$(git status --porcelain --untracked-files=no 2>/dev/null)" ]]; then
        error "Working tree has uncommitted changes. Commit or stash first."
    fi

    step "Bumping version to ${VERSION_ARG}"
    bump_version "$VERSION_ARG"
    VERSION="$VERSION_ARG"
else
    VERSION=$(current_version)
    info "Packaging version: ${VERSION}"
fi

# --- Build frontend -------------------------------------------------------

if [[ -f "admin/package.json" ]]; then
    step "Building frontend assets"

    if [[ ! -d "admin/node_modules" ]]; then
        info "Installing npm dependencies..."
        (cd admin && npm install --silent)
    fi

    (cd admin && npm run build) || error "Frontend build failed"
fi

# --- Assemble distribution ------------------------------------------------

step "Assembling distribution"

BUILD_DIR="$DIST_DIR/$SLUG"
ZIP_FILE="$DIST_DIR/$SLUG-$VERSION.zip"

rm -rf "$BUILD_DIR"
rm -f "$ZIP_FILE"
mkdir -p "$BUILD_DIR"

# Plugin entry point
cp "$MAIN_FILE" "$BUILD_DIR/"

# PHP source
[[ -d "src" ]] && cp -r src "$BUILD_DIR/src"

# Compiled frontend (bundles only)
if [[ -d "admin/build" ]]; then
    mkdir -p "$BUILD_DIR/admin/build"
    cp -r admin/build/* "$BUILD_DIR/admin/build/"
fi

# Vendor autoloader only (skip dev packages)
if [[ -f "vendor/autoload.php" ]]; then
    mkdir -p "$BUILD_DIR/vendor"
    cp vendor/autoload.php "$BUILD_DIR/vendor/"
    cp -r vendor/composer "$BUILD_DIR/vendor/composer"
else
    warn "No vendor/autoload.php — run 'composer install' first"
fi

# Optional directories
for dir in languages; do
    if [[ -d "$dir" ]]; then
        cp -r "$dir" "$BUILD_DIR/$dir"
        info "Included $dir/"
    fi
done

info "Distribution assembled"

# --- Create archive -------------------------------------------------------

step "Creating archive"
create_zip "$BUILD_DIR" "$ZIP_FILE"
rm -rf "$BUILD_DIR"

# --- Git commit + tag (release mode only) ---------------------------------

if [[ "$IS_RELEASE" == true ]]; then
    step "Committing release"

    git add "$MAIN_FILE"
    [[ -f "admin/package.json" ]] && git add admin/package.json
    [[ -d "admin/build" ]] && git add admin/build/

    git commit -m "$(cat <<EOF
Release v${VERSION}

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>
EOF
)"
    git tag "v${VERSION}"
    info "Tagged v${VERSION}"
    warn "Not pushed — run 'git push && git push --tags' when ready"
fi

# --- Summary --------------------------------------------------------------

ZIP_SIZE=$(du -h "$ZIP_FILE" 2>/dev/null | cut -f1 || echo "?")

echo ""
echo -e "${GREEN}${BOLD}Release ready!${NC}"
echo -e "  Version: ${BOLD}v${VERSION}${NC}"
echo -e "  Archive: ${BOLD}${ZIP_FILE}${NC} (${ZIP_SIZE})"
