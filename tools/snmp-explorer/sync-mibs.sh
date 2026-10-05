#!/usr/bin/env bash
# ==============================================================================
# PFMS SNMP Explorer - MIB Sync Utility
# 
# Automatically downloads and syncs vendor MIB collections (LibreNMS / Observium)
# directly into the toolkit directory without manual web uploads.
#
# Usage:
#   bash sync-mibs.sh [librenms|observium|all] [--system]
#
# Examples:
#   bash sync-mibs.sh librenms            # Syncs LibreNMS MIBs into engine/mibs/
#   bash sync-mibs.sh observium           # Syncs Observium MIBs into engine/mibs/
#   bash sync-mibs.sh all                 # Syncs both LibreNMS & Observium
#   bash sync-mibs.sh librenms --system   # Syncs into /usr/share/snmp/mibs/
# ==============================================================================

set -e

SOURCE="${1:-librenms}"
FLAG="${2:-}"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TOOLKIT_MIB_DIR="${SCRIPT_DIR}/engine/mibs"
SYSTEM_MIB_DIR="/usr/share/snmp/mibs"
CACHE_DIR="${SCRIPT_DIR}/engine/storage/cache"

TARGET_DIR="${TOOLKIT_MIB_DIR}"
if [[ "$FLAG" == "--system" || "$SOURCE" == "--system" ]]; then
    TARGET_DIR="${SYSTEM_MIB_DIR}"
    if [[ "$SOURCE" == "--system" ]]; then
        SOURCE="${2:-librenms}"
    fi
fi

mkdir -p "$TARGET_DIR"

echo "================================================================="
echo "   PFMS SNMP Explorer - MIB Synchronizer"
echo "================================================================="
echo " Source       : $SOURCE"
echo " Target Dir   : $TARGET_DIR"
echo " Date         : $(date)"
echo "-----------------------------------------------------------------"

sync_repo() {
    local name="$1"
    local repo_url="$2"
    local tar_url="$3"
    local temp_dir
    temp_dir=$(mktemp -d -t pfms-mibs-XXXXXX)

    echo "[+] Downloading MIBs from $name..."

    # Method 1: Fast tar stream via curl (no git history bloat)
    local downloaded=0
    if command -v curl >/dev/null 2>&1 && command -v tar >/dev/null 2>&1; then
        echo "    Using curl + tar stream extraction..."
        if curl -fL --progress-bar "$tar_url" | tar -xz -C "$temp_dir" --strip-components=1; then
            downloaded=1
        fi
    fi

    # Method 2: Fallback to Git sparse-checkout if tar extraction failed
    if [[ "$downloaded" -eq 0 ]] && command -v git >/dev/null 2>&1; then
        echo "    Using git sparse-checkout..."
        rm -rf "$temp_dir"/*
        git clone --depth 1 --filter=blob:none --sparse "$repo_url" "$temp_dir"
        (cd "$temp_dir" && git sparse-checkout set mibs)
        downloaded=1
    fi

    if [[ "$downloaded" -eq 0 ]]; then
        echo "[!] Error: Failed to download MIBs from $name. Please verify internet connectivity."
        rm -rf "$temp_dir"
        return 1
    fi

    local src_mibs="$temp_dir/mibs"
    if [[ ! -d "$src_mibs" ]]; then
        src_mibs="$temp_dir"
    fi

    if [[ -d "$src_mibs" ]]; then
        echo "    Copying vendor MIB directories to $TARGET_DIR..."
        cp -rn "$src_mibs"/* "$TARGET_DIR/" 2>/dev/null || cp -r "$src_mibs"/* "$TARGET_DIR/"
        echo "    [OK] Successfully merged MIBs from $name."
    else
        echo "[!] Warning: 'mibs' directory not found in downloaded archive."
    fi

    rm -rf "$temp_dir"
}

case "$SOURCE" in
    librenms)
        sync_repo "LibreNMS" \
            "https://github.com/librenms/librenms.git" \
            "https://github.com/librenms/librenms/archive/refs/heads/master.tar.gz"
        ;;
    observium)
        sync_repo "Observium" \
            "https://github.com/froggatt/observium.git" \
            "https://github.com/froggatt/observium/archive/refs/heads/master.tar.gz"
        ;;
    all)
        sync_repo "Observium" \
            "https://github.com/froggatt/observium.git" \
            "https://github.com/froggatt/observium/archive/refs/heads/master.tar.gz"
        sync_repo "LibreNMS" \
            "https://github.com/librenms/librenms.git" \
            "https://github.com/librenms/librenms/archive/refs/heads/master.tar.gz"
        ;;
    *)
        echo "Usage: bash $0 [librenms|observium|all] [--system]"
        exit 1
        ;;
esac

# Clear candidate manifest cache
if [[ -d "$CACHE_DIR" ]]; then
    echo "[+] Clearing SNMP Explorer manifest cache..."
    rm -f "${CACHE_DIR}"/mibs_manifest_*.json
fi

# Set permissions
echo "[+] Setting filesystem permissions..."
chmod -R 755 "$TARGET_DIR" 2>/dev/null || true
if id -u apache >/dev/null 2>&1; then
    chown -R apache:apache "$TARGET_DIR" 2>/dev/null || true
elif id -u www-data >/dev/null 2>&1; then
    chown -R www-data:www-data "$TARGET_DIR" 2>/dev/null || true
fi

# Output summary statistics
vendor_count=$(find "$TARGET_DIR" -mindepth 1 -maxdepth 1 -type d | wc -l)
mib_count=$(find "$TARGET_DIR" -type f \( -name "*.mib" -o -name "*.my" -o -name "*.txt" -o -name "*MIB*" \) | wc -l)

echo "-----------------------------------------------------------------"
echo " Synchronized successfully!"
echo " Vendor Subdirectories : $vendor_count"
echo " Total MIB Files       : $mib_count"
echo " Location              : $TARGET_DIR"
echo "================================================================="
