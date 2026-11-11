#!/usr/bin/env bash
# ==============================================================================
# setup_ssl.sh — Generate SSL certificates for SuiteCRM Nginx reverse proxy
#
# Usage:
#   bash scripts/setup_ssl.sh --self-signed              # Quick test cert (365 days)
#   bash scripts/setup_ssl.sh --letsencrypt -d domain.com # Production cert via Let's Encrypt
#   bash scripts/setup_ssl.sh --letsencrypt -d domain.com --email admin@domain.com
# ==============================================================================
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
SSL_DIR="${PROJECT_DIR}/nginx/ssl"

usage() {
    echo "Usage:"
    echo "  $0 --self-signed                           Generate self-signed cert for testing"
    echo "  $0 --letsencrypt -d <domain> [--email <e>] Obtain Let's Encrypt cert via certbot"
    echo ""
    echo "Examples:"
    echo "  bash scripts/setup_ssl.sh --self-signed"
    echo "  bash scripts/setup_ssl.sh --letsencrypt -d env-12345.jagoancloud.com"
    echo "  bash scripts/setup_ssl.sh --letsencrypt -d crm.example.com --email admin@example.com"
    exit 1
}

ensure_ssl_dir() {
    mkdir -p "$SSL_DIR"
    echo "[SSL] Directory ready: $SSL_DIR"
}

generate_self_signed() {
    ensure_ssl_dir
    echo "[SSL] Generating self-signed certificate (valid 365 days)..."
    openssl req -x509 -nodes -days 365 \
        -newkey rsa:2048 \
        -keyout "${SSL_DIR}/key.pem" \
        -out "${SSL_DIR}/cert.pem" \
        -subj "/CN=localhost/O=SuiteCRM-Dev/C=ID"
    chmod 600 "${SSL_DIR}/key.pem"
    chmod 644 "${SSL_DIR}/cert.pem"
    echo "[SSL] Self-signed certificate created:"
    echo "       cert : ${SSL_DIR}/cert.pem"
    echo "       key  : ${SSL_DIR}/key.pem"
    echo ""
    echo "[SSL] Next steps:"
    echo "  1. Restart nginx:  docker compose restart nginx"
    echo "  2. Access:         https://<your-host>"
    echo "  NOTE: Browsers will show a security warning for self-signed certs."
}

obtain_letsencrypt() {
    local domain=""
    local email=""

    while [[ $# -gt 0 ]]; do
        case "$1" in
            -d|--domain)
                domain="$2"; shift 2 ;;
            --email)
                email="$2"; shift 2 ;;
            *)
                echo "[ERROR] Unknown option for --letsencrypt: $1" >&2
                usage ;;
        esac
    done

    if [[ -z "$domain" ]]; then
        echo "[ERROR] Domain is required for Let's Encrypt. Use: --letsencrypt -d yourdomain.com" >&2
        exit 1
    fi

    ensure_ssl_dir

    # Create webroot directory for ACME challenge
    local webroot="${PROJECT_DIR}/nginx/certbot-webroot"
    mkdir -p "$webroot"

    echo "[SSL] Requesting Let's Encrypt certificate for: ${domain}"
    echo "[SSL] Make sure port 80 is reachable from the internet and nginx is running."
    echo ""

    local email_flag="--register-unsafely-without-email"
    if [[ -n "$email" ]]; then
        email_flag="--email ${email} --no-eff-email"
    fi

    # Run certbot via docker compose (certbot service defined in docker-compose.yml)
    docker compose run --rm certbot certonly \
        --webroot \
        --webroot-path=/var/www/certbot \
        --agree-tos \
        ${email_flag} \
        -d "$domain"

    # Copy certs to nginx ssl directory
    local le_live="/etc/letsencrypt/live/${domain}"
    echo "[SSL] Copying certificates from certbot volume..."

    # Extract certs from the certbot container's volume
    docker compose run --rm --entrypoint "" certbot \
        sh -c "cat ${le_live}/fullchain.pem" > "${SSL_DIR}/cert.pem"
    docker compose run --rm --entrypoint "" certbot \
        sh -c "cat ${le_live}/privkey.pem" > "${SSL_DIR}/key.pem"

    chmod 600 "${SSL_DIR}/key.pem"
    chmod 644 "${SSL_DIR}/cert.pem"

    echo "[SSL] Let's Encrypt certificate installed:"
    echo "       cert : ${SSL_DIR}/cert.pem"
    echo "       key  : ${SSL_DIR}/key.pem"
    echo ""
    echo "[SSL] Next steps:"
    echo "  1. Restart nginx:  docker compose restart nginx"
    echo "  2. Access:         https://${domain}"
    echo "  3. Set SUITECRM_PROTO=https in your .env file"
    echo ""
    echo "[SSL] To renew later:"
    echo "  docker compose run --rm certbot renew"
    echo "  Then copy certs again and restart nginx."
}

# --- Main ---
if [[ $# -lt 1 ]]; then
    usage
fi

case "$1" in
    --self-signed)
        generate_self_signed
        ;;
    --letsencrypt)
        shift
        obtain_letsencrypt "$@"
        ;;
    -h|--help)
        usage
        ;;
    *)
        echo "[ERROR] Unknown option: $1" >&2
        usage
        ;;
esac
