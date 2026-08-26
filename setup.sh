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

# Kiểm tra quyền root
if [ "$(id -u)" -ne 0 ]; then
    echo -e "${RED}[ERROR] Script này cần chạy với quyền ROOT trong container DroidSpaces!${NC}"
    exit 1
fi

WEB_DIR="/var/www/html"
BIN_DIR="${WEB_DIR}/bin"
REPO_URL="https://github.com/Duy-Thanh/indexphp.git"
SERVICE_NAME="control-center"

echo -e "${YELLOW}[1/6] Cập nhật hệ thống & cài đặt packages (bao gồm git)...${NC}"
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

echo -e "${YELLOW}[2/6] Clone / Pull source code từ GitHub (${REPO_URL})...${NC}"
if [ -d "${WEB_DIR}/.git" ]; then
    echo -e "${BLUE}Thư mục đã chứa git repo, đang tiến hành pull bản mới nhất...${NC}"
    git -C "${WEB_DIR}" fetch --all
    git -C "${WEB_DIR}" reset --hard origin/main || git -C "${WEB_DIR}" reset --hard origin/master
    git -C "${WEB_DIR}" pull
else
    echo -e "${BLUE}Thực hiện git clone về ${WEB_DIR}...${NC}"
    rm -rf "${WEB_DIR}"
    git clone "${REPO_URL}" "${WEB_DIR}"
fi

echo -e "${YELLOW}[3/6] Tạo thư mục bin tại ${WEB_DIR}/bin...${NC}"
mkdir -p "${BIN_DIR}"
chmod -R 755 "${WEB_DIR}"

echo -e "${YELLOW}[4/6] Tải phiên bản yt-dlp mới nhất vào ${BIN_DIR}/yt-dlp...${NC}"
curl -L https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp -o "${BIN_DIR}/yt-dlp"
chmod +x "${BIN_DIR}/yt-dlp"
echo -e "${GREEN}yt-dlp version: $(${BIN_DIR}/yt-dlp --version)${NC}"

echo -e "${YELLOW}[5/6] Cấu hình Systemd Service (${SERVICE_NAME}.service)...${NC}"
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

echo -e "${YELLOW}[6/6] Kích hoạt và khởi chạy service...${NC}"
systemctl daemon-reload
systemctl enable "${SERVICE_NAME}"
systemctl restart "${SERVICE_NAME}"

echo -e "${BLUE}=====================================================${NC}"
echo -e "${GREEN}✔ ĐÃ TỰ ĐỘNG HÓA CÀI ĐẶT THÀNH CÔNG!${NC}"
echo -e "${BLUE}=====================================================${NC}"
echo -e "📌 Source code đã được tự động kéo về từ GitHub."
echo -e "📌 Server đang chạy tại IP Container / Host Port: ${GREEN}http://0.0.0.0:8080${NC}"
echo -e "📌 Quản lý service:"
echo -e "   - Xem log status : ${YELLOW}systemctl status ${SERVICE_NAME}${NC}"
echo -e "   - Khởi động lại  : ${YELLOW}systemctl restart ${SERVICE_NAME}${NC}"
echo -e "   - Xem log php    : ${YELLOW}journalctl -u ${SERVICE_NAME} -f${NC}"
echo -e "${BLUE}=====================================================${NC}"
