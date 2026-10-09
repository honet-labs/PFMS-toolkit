#!/usr/bin/env bash
# ==============================================================================
# PFMS-Toolkit - Automated Installer & Deployer for Pandora FMS
#
# Repository  : https://github.com/honet-labs/PFMS-toolkit.git
# Target Path : /var/www/html/pandora_console/custom/pfms-toolkit
# Ownership   : apache:apache
#
# Usage:
#   sudo bash installer-pfms-toolkit.sh
#
# One-liner Installation via curl:
#   curl -sSL https://raw.githubusercontent.com/honet-labs/PFMS-toolkit/main/installer-pfms-toolkit.sh | sudo bash
# ==============================================================================

set -eo pipefail

# --- Color Palettes ---
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
PURPLE='\033[0;35m'
CYAN='\033[0;36m'
BOLD='\033[1m'
NC='\033[0m' # No Color

# --- Defaults ---
PANDORA_BASE_DIR="/var/www/html/pandora_console"
DEFAULT_TARGET_PARENT="${PANDORA_BASE_DIR}/custom"
DEFAULT_TARGET_DIR="${DEFAULT_TARGET_PARENT}/pfms-toolkit"
DEFAULT_REPO_URL="https://github.com/honet-labs/PFMS-toolkit.git"
DEFAULT_REPO_BRANCH="main"
DEFAULT_USER="apache"
DEFAULT_GROUP="apache"

TARGET_DIR="${DEFAULT_TARGET_DIR}"
REPO_URL="${DEFAULT_REPO_URL}"
REPO_BRANCH="${DEFAULT_REPO_BRANCH}"
TARGET_USER="${DEFAULT_USER}"
TARGET_GROUP="${DEFAULT_GROUP}"
FORCE_UPDATE=0

# --- Helper Functions ---
print_banner() {
    echo -e "${CYAN}"
    echo "================================================================="
    echo "       ____  ________  _________       ______             __  ___    "
    echo "      / __ \/ ____/  |/  / ___/       /_  __/___  ____   / / / /____ "
    echo "     / /_/ / /_  / /|_/ /\__ \  ______ / / / __ \/ __ \ / /_/ //_  / "
    echo "    / ____/ __/ / /  / /___/ / /_____// / / /_/ / /_/ // /_  /  / /_ "
    echo "   /_/   /_/   /_/  /_//____/        /_/  \____/\____//_/ /_/  /___/ "
    echo "=================================================================${NC}"
    echo -e "${BOLD}   PFMS-Toolkit Installer & Deployer for Pandora FMS Console${NC}"
    echo -e "   Target: ${BOLD}${TARGET_DIR}${NC}"
    echo -e "   Owner : ${BOLD}${TARGET_USER}:${TARGET_GROUP}${NC}"
    echo "-----------------------------------------------------------------"
}

print_help() {
    cat <<EOF
Usage: sudo bash $0 [OPTIONS]

Options:
  -p, --path <dir>       Custom installation directory
                         (Default: /var/www/html/pandora_console/custom/pfms-toolkit)
  -b, --branch <branch>  Git branch to clone/pull (Default: main)
  -u, --user <user>      Web server user owner (Default: apache)
  -g, --group <group>    Web server group owner (Default: apache)
  --update               Force update if already installed
  -h, --help             Show this help message

Examples:
  sudo bash $0
  sudo bash $0 --update
  sudo bash $0 --user www-data --group www-data
EOF
}

# --- Parse Arguments ---
while [[ $# -gt 0 ]]; do
    case "$1" in
        -p|--path)
            TARGET_DIR="$2"
            shift 2
            ;;
        -b|--branch)
            REPO_BRANCH="$2"
            shift 2
            ;;
        -u|--user)
            TARGET_USER="$2"
            shift 2
            ;;
        -g|--group)
            TARGET_GROUP="$2"
            shift 2
            ;;
        --update)
            FORCE_UPDATE=1
            shift
            ;;
        -h|--help)
            print_help
            exit 0
            ;;
        *)
            echo -e "${RED}[ERROR] Unknown option: $1${NC}"
            print_help
            exit 1
            ;;
    esac
done

# --- Privilege Check ---
if [[ "$(id -u)" -ne 0 ]]; then
    echo -e "${RED}[ERROR] This installer must be executed as root or with sudo privileges.${NC}"
    echo -e "Example: ${BOLD}sudo bash $0${NC}"
    exit 1
fi

print_banner

# --- Step 1: Detect & Prepare Dependencies ---
echo -e "${BLUE}[1/6] Checking system requirements...${NC}"

# Detect Package Manager & install git if missing
if ! command -v git >/dev/null 2>&1; then
    echo -e "${YELLOW}[!] Git is not installed. Attempting automatic installation...${NC}"
    if command -v dnf >/dev/null 2>&1; then
        dnf install -y git
    elif command -v yum >/dev/null 2>&1; then
        yum install -y git
    elif command -v apt-get >/dev/null 2>&1; then
        apt-get update -y && apt-get install -y git
    elif command -v zypper >/dev/null 2>&1; then
        zypper install -y git
    else
        echo -e "${RED}[ERROR] Package manager not recognized. Please install 'git' manually and re-run.${NC}"
        exit 1
    fi
fi
echo -e "  ${GREEN}✓${NC} Git is available: $(git --version)"

# Check Pandora Console Directory
if [[ -d "${PANDORA_BASE_DIR}" ]]; then
    echo -e "  ${GREEN}✓${NC} Pandora FMS Console detected at: ${PANDORA_BASE_DIR}"
    if [[ -f "${PANDORA_BASE_DIR}/include/config.php" ]]; then
        echo -e "  ${GREEN}✓${NC} Pandora FMS Core Config file verified (include/config.php)"
    fi
else
    echo -e "  ${YELLOW}!${NC} Notice: '${PANDORA_BASE_DIR}' was not detected directly."
    echo -e "    Toolkit will still be installed to target: ${TARGET_DIR}"
fi

# Validate User & Group
if ! id "${TARGET_USER}" >/dev/null 2>&1; then
    if [[ "${TARGET_USER}" == "apache" ]] && id "www-data" >/dev/null 2>&1; then
        echo -e "  ${YELLOW}!${NC} User 'apache' not found, detected 'www-data' (Debian/Ubuntu). Using www-data."
        TARGET_USER="www-data"
        TARGET_GROUP="www-data"
    else
        echo -e "  ${YELLOW}!${NC} Creating web user and group '${TARGET_USER}:${TARGET_GROUP}'..."
        groupadd -r "${TARGET_GROUP}" 2>/dev/null || true
        useradd -r -g "${TARGET_GROUP}" -s /sbin/nologin -d "${PANDORA_BASE_DIR}" "${TARGET_USER}" 2>/dev/null || true
    fi
fi
echo -e "  ${GREEN}✓${NC} Target Ownership: ${TARGET_USER}:${TARGET_GROUP}"

# --- Step 2: Source Code Acquisition & Sync ---
echo -e "\n${BLUE}[2/6] Preparing repository source code...${NC}"

SCRIPT_DIR=""
if [[ -n "${BASH_SOURCE[0]:-}" ]] && [[ -f "${BASH_SOURCE[0]}" ]]; then
    SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
fi

TARGET_PARENT_DIR="$(dirname "${TARGET_DIR}")"
mkdir -p "${TARGET_PARENT_DIR}"

if [[ -d "${TARGET_DIR}/.git" ]]; then
    echo -e "  Existing git installation found at ${TARGET_DIR}."
    echo -e "  ${CYAN}[+] Pulling latest updates from branch '${REPO_BRANCH}'...${NC}"
    git -C "${TARGET_DIR}" fetch origin "${REPO_BRANCH}"
    git -C "${TARGET_DIR}" reset --hard "origin/${REPO_BRANCH}"
elif [[ -d "${TARGET_DIR}" ]]; then
    echo -e "  Target directory exists but is not a git repository."
    BACKUP_DIR="${TARGET_DIR}_backup_$(date +%Y%m%d_%H%M%S)"
    echo -e "  ${YELLOW}[+] Creating backup to ${BACKUP_DIR}...${NC}"
    mv "${TARGET_DIR}" "${BACKUP_DIR}"
    echo -e "  ${CYAN}[+] Cloning fresh repository from ${REPO_URL}...${NC}"
    git clone -b "${REPO_BRANCH}" "${REPO_URL}" "${TARGET_DIR}"
elif [[ -n "${SCRIPT_DIR}" ]] && [[ -f "${SCRIPT_DIR}/custom-index.php" ]] && [[ "${SCRIPT_DIR}" != "${TARGET_DIR}" ]]; then
    echo -e "  ${CYAN}[+] Installing from current local repository (${SCRIPT_DIR})...${NC}"
    if command -v rsync >/dev/null 2>&1; then
        rsync -a --delete --exclude 'temp' --exclude 'cache' "${SCRIPT_DIR}/" "${TARGET_DIR}/"
    else
        cp -a "${SCRIPT_DIR}" "${TARGET_DIR}"
    fi
else
    echo -e "  ${CYAN}[+] Cloning repository from ${REPO_URL} (${REPO_BRANCH})...${NC}"
    git clone -b "${REPO_BRANCH}" "${REPO_URL}" "${TARGET_DIR}"
fi

# --- Step 3: Runtime Directory Initialization ---
echo -e "\n${BLUE}[3/6] Initializing runtime directories and cache...${NC}"
mkdir -p "${TARGET_DIR}/temp"
mkdir -p "${TARGET_DIR}/cache"
mkdir -p "${TARGET_DIR}/tools/snmp-explorer/cache"

# Clear stale menu cache if any
if [[ -f "${TARGET_DIR}/temp/menu_cache.json" ]]; then
    rm -f "${TARGET_DIR}/temp/menu_cache.json"
    echo -e "  ${GREEN}✓${NC} Cleared stale menu cache."
fi

# --- Step 4: Web Entrypoint Symlink Configuration ---
echo -e "\n${BLUE}[4/6] Configuring portal entrypoint...${NC}"
# Symlink index.php to custom-index.php for clean default root URL resolution
if [[ ! -e "${TARGET_DIR}/index.php" ]]; then
    ln -sf "custom-index.php" "${TARGET_DIR}/index.php"
    echo -e "  ${GREEN}✓${NC} Created entrypoint link: index.php -> custom-index.php"
else
    echo -e "  ${GREEN}✓${NC} Entrypoint index.php verified."
fi

# --- Step 5: Permissions & Ownership Configuration ---
echo -e "\n${BLUE}[5/6] Applying filesystem permissions & ownership...${NC}"
echo -e "  Setting ownership: ${BOLD}${TARGET_USER}:${TARGET_GROUP}${NC}..."
chown -R "${TARGET_USER}:${TARGET_GROUP}" "${TARGET_DIR}"

echo -e "  Applying standard directory permissions (0755)..."
find "${TARGET_DIR}" -type d -exec chmod 755 {} +

echo -e "  Applying standard file permissions (0644)..."
find "${TARGET_DIR}" -type f -exec chmod 644 {} +

echo -e "  Granting executable permissions to scripts (*.sh)..."
find "${TARGET_DIR}" -type f -name "*.sh" -exec chmod 755 {} +

echo -e "  Securing write access on runtime cache folders (0775)..."
chmod -R 775 "${TARGET_DIR}/temp" "${TARGET_DIR}/cache" "${TARGET_DIR}/tools/snmp-explorer/cache" 2>/dev/null || true

# --- Step 6: SELinux Security Context (if active) ---
echo -e "\n${BLUE}[6/6] Checking SELinux security policies...${NC}"
if command -v getenforce >/dev/null 2>&1; then
    SE_STATUS=$(getenforce 2>/dev/null || echo "Disabled")
    if [[ "${SE_STATUS}" != "Disabled" ]]; then
        echo -e "  SELinux status: ${BOLD}${SE_STATUS}${NC}. Configuring httpd_sys_rw_content_t context..."
        chcon -R -t httpd_sys_rw_content_t "${TARGET_DIR}" 2>/dev/null || true
        if command -v semanage >/dev/null 2>&1; then
            semanage fcontext -a -t httpd_sys_rw_content_t "${TARGET_DIR}(/.*)?" 2>/dev/null || true
            restorecon -R "${TARGET_DIR}" 2>/dev/null || true
        fi
        echo -e "  ${GREEN}✓${NC} SELinux policy updated."
    else
        echo -e "  SELinux is Disabled. Skipping context configuration."
    fi
else
    echo -e "  SELinux not detected on this system."
fi

# --- Final Summary ---
SERVER_IP=$(hostname -I 2>/dev/null | awk '{print $1}')
[[ -z "${SERVER_IP}" ]] && SERVER_IP="<YOUR_PANDORA_IP>"

echo -e "\n${GREEN}=================================================================${NC}"
echo -e "${GREEN}   PFMS-Toolkit has been successfully installed & configured!   ${NC}"
echo -e "${GREEN}=================================================================${NC}"
echo -e "  ${BOLD}Location  :${NC} ${TARGET_DIR}"
echo -e "  ${BOLD}Owner     :${NC} ${TARGET_USER}:${TARGET_GROUP}"
echo -e "  ${BOLD}Permissions:${NC} 0755 (Directories) / 0644 (Files) / 0775 (temp & cache)"
echo -e "  ${BOLD}Entrypoint:${NC} custom-index.php (aliased to index.php)"
echo -e "-----------------------------------------------------------------"
echo -e "  ${BOLD}Direct Access URL:${NC}"
echo -e "  ${CYAN}http://${SERVER_IP}/pandora_console/custom/pfms-toolkit/${NC}"
echo -e "  atau"
echo -e "  ${CYAN}http://${SERVER_IP}/pandora_console/custom/pfms-toolkit/custom-index.php${NC}"
echo "-----------------------------------------------------------------"
echo -e "  ${BOLD}Pandora FMS Console Menu Integration:${NC}"
echo -e "  Tambahkan custom link atau ekstensi di Pandora FMS Menu:"
echo -e "  Name : ${BOLD}PFMS Toolkit${NC}"
echo -e "  URL  : ${BOLD}/pandora_console/custom/pfms-toolkit/${NC}"
echo -e "${GREEN}=================================================================${NC}\n"

exit 0
