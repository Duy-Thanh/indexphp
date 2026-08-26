#!/usr/bin/env bash
# ==============================================================================
# DroidSpaces-OSS Debian 13 (Trixie) - Control Center Setup Script
# Hardware: Google Pixel 7a (Tensor G2)
# Repo: https://github.com/Duy-Thanh/indexphp.git
# ==============================================================================

set -euo pipefail

GREEN='\033[1;32m'
BLUE='\033[1;34m'
YELLOW='\033[1;33m'
RED='\033[1;31m'
NC='\033[0m' # No Color

echo -e "${BLUE}=====================================================${NC}"
echo -e "${GREEN}  Control Center Installer for DroidSpaces-OSS      ${NC}"
echo -e "${BLUE}=====================================================${NC}"

# Root privileges check
if [ "$(id -u)" -ne 0 ]; then
    echo -e "${RED}[ERROR] This script must be run as ROOT inside the DroidSpaces container!${NC}"
    exit 1
fi

WEB_DIR="/var/www/html"
BIN_DIR="${WEB_DIR}/bin"
REPO_URL="https://github.com/Duy-Thanh/indexphp.git"
SERVICE_NAME="control-center"

echo -e "${YELLOW}[1/6] Updating system & installing packages (including git)...${NC}"
apt-get update -qq
apt-get install -y --no-install-recommends \
    git \
    php-cli \
    mpv \
    ffmpeg \
    procps \
    psmisc \
    python3 \
    curl \
    ca-certificates \
    wget

echo -e "${YELLOW}[2/6] Cloning / Pulling source code from GitHub (${REPO_URL})...${NC}"
if [ -d "${WEB_DIR}/.git" ]; then
    echo -e "${BLUE}Directory already contains a git repo. Pulling latest changes...${NC}"
    git -C "${WEB_DIR}" fetch --all
    git -C "${WEB_DIR}" reset --hard origin/main || git -C "${WEB_DIR}" reset --hard origin/master
    git -C "${WEB_DIR}" pull
else
    echo -e "${BLUE}Cloning repository into ${WEB_DIR}...${NC}"
    rm -rf "${WEB_DIR}"
    git clone "${REPO_URL}" "${WEB_DIR}"
fi

echo -e "${YELLOW}[3/6] Creating bin directory at ${WEB_DIR}/bin...${NC}"
mkdir -p "${BIN_DIR}"
chmod -R 755 "${WEB_DIR}"

echo -e "${YELLOW}[4/6] Downloading latest yt-dlp binary to ${BIN_DIR}/yt-dlp...${NC}"
curl -L https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp -o "${BIN_DIR}/yt-dlp"
chmod +x "${BIN_DIR}/yt-dlp"
echo -e "${GREEN}yt-dlp version: $(${BIN_DIR}/yt-dlp --version)${NC}"

echo -e "${YELLOW}[5/6] Configuring Systemd Service (${SERVICE_NAME}.service)...${NC}"
CAT_SERVICE=$(cat <<EOF
[Unit]
Description=Control Center Web UI (MPV Engine)
After=network.target

[Service]
Type=simple
User=root
WorkingDirectory=${WEB_DIR}
ExecStart=/usr/bin/php -S 0.0.0.0:8080 -t ${WEB_DIR}
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
EOF
)

echo "${CAT_SERVICE}" > "/etc/systemd/system/${SERVICE_NAME}.service"

echo -e "${YELLOW} Fixing permission"
usermod -aG audio www-data

echo -e "${YELLOW}[6/6] Enabling and starting service...${NC}"
systemctl daemon-reload
systemctl enable "${SERVICE_NAME}"
systemctl restart "${SERVICE_NAME}"

echo -e "${BLUE}=====================================================${NC}"
echo -e "${GREEN}✔ AUTOMATED INSTALLATION COMPLETED SUCCESSFULLY!${NC}"
echo -e "${BLUE}=====================================================${NC}"
echo -e "📌 Source code automatically pulled from GitHub."
echo -e "📌 Server running at Container IP / Host Port: ${GREEN}http://0.0.0.0:8080${NC}"
echo -e "📌 Service management commands:"
echo -e "   - Check status  : ${YELLOW}systemctl status ${SERVICE_NAME}${NC}"
echo -e "   - Restart service: ${YELLOW}systemctl restart ${SERVICE_NAME}${NC}"
echo -e "   - View PHP logs  : ${YELLOW}journalctl -u ${SERVICE_NAME} -f${NC}"
echo -e "${BLUE}=====================================================${NC}"
