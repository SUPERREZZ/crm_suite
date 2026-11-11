#!/usr/bin/env bash
# ==============================================================================
# deploy_all.sh — Pengganti 'make all' untuk server tanpa 'make'
# Menjalankan seluruh stack SuiteCRM 8 B2B Demo secara otomatis.
# ==============================================================================
set -euo pipefail

WEB_USER="daemon"
SUITECRM_ROOT="/bitnami/suitecrm"
PHP_BIN="/opt/bitnami/php/bin/php"

echo "=========================================================="
echo " Starting PT Sumber Kopi Nusantara SuiteCRM 8 Stack Setup "
echo "=========================================================="

# 0. Pastikan kita berada di direktori yang benar (ada docker-compose.yml)
if [ ! -f "docker-compose.yml" ]; then
    echo "[ERROR] File docker-compose.yml tidak ditemukan di direktori saat ini!" >&2
    echo "[ERROR] Pastikan Anda menjalankan script ini dari root folder project." >&2
    echo "[ERROR] Contoh: cd ~/suitecrm-lecture/crm_suite/suitecrm-lecture && bash scripts/deploy_all.sh" >&2
    exit 1
fi

# 1. Cek keberadaan .env
if [ ! -f ".env" ]; then
    if [ -f ".env.example" ]; then
        echo "[SETUP] File .env belum ada. Menyalin dari .env.example ..."
        cp .env.example .env
        echo "[SETUP] File .env berhasil dibuat dari template."
        echo "[SETUP] ============================================="
        echo "[SETUP] PENTING: Edit file .env terlebih dahulu!"
        echo "[SETUP] Sesuaikan password database, admin, dan domain."
        echo "[SETUP] Lalu jalankan ulang: bash scripts/deploy_all.sh"
        echo "[SETUP] ============================================="
        exit 0
    else
        echo "[ERROR] File .env dan .env.example tidak ditemukan!" >&2
        exit 1
    fi
fi

echo "[INFO] File .env ditemukan. Melanjutkan deployment..."

# 2. Jalankan docker compose (profile nginx otomatis dari COMPOSE_PROFILES di .env)
echo ""
echo "============================================"
echo " [1/6] Menjalankan Docker Compose stack...  "
echo "============================================"
docker compose up -d

# 3. Tunggu inisialisasi SuiteCRM (polling HTTP 200 + installer_locked)
echo ""
echo "============================================"
echo " [2/6] Menunggu SuiteCRM selesai setup...   "
echo "============================================"
echo "[INFO] Proses ini bisa memakan waktu 3-10 menit pada deploy pertama."
bash ./scripts/wait_for_suitecrm.sh

# 4. Smoke test bootstrap
echo ""
echo "============================================"
echo " [3/6] Smoke test bootstrap SuiteCRM...     "
echo "============================================"
docker compose exec -T -u "${WEB_USER}" -e SUITECRM_ROOT="${SUITECRM_ROOT}" suitecrm "${PHP_BIN}" /seed/smoke_bootstrap.php

# 5. Seeding data B2B 5 Tahun
echo ""
echo "============================================"
echo " [4/6] Seeding data B2B 5 Tahun...          "
echo "============================================"
echo "[INFO] Memasukkan 15 Accounts, 15 Contacts, 18 Opportunities, dll."
docker compose exec -T -u "${WEB_USER}" -e SUITECRM_ROOT="${SUITECRM_ROOT}" suitecrm "${PHP_BIN}" /seed/seed_suitecrm_b2b.php

# 6. Install paket bahasa Indonesia
echo ""
echo "============================================"
echo " [5/6] Install paket Bahasa Indonesia...    "
echo "============================================"
docker compose exec -T -u "${WEB_USER}" -e SUITECRM_ROOT="${SUITECRM_ROOT}" suitecrm "${PHP_BIN}" /seed/install_id_lang.php

# 7. Verifikasi 21/21 assertions
echo ""
echo "============================================"
echo " [6/6] Verifikasi integritas data (21/21)..."
echo "============================================"
docker compose exec -T -u "${WEB_USER}" -e SUITECRM_ROOT="${SUITECRM_ROOT}" suitecrm "${PHP_BIN}" /seed/verify_seed.php

echo ""
echo "=========================================================="
echo " [SUCCESS] SuiteCRM 8 Siap Digunakan!                     "
echo "=========================================================="
echo ""
echo " Akses SuiteCRM di browser:"
if grep -q "COMPOSE_PROFILES=nginx" .env 2>/dev/null; then
    DOMAIN=$(grep "^SUITECRM_HOST=" .env | cut -d= -f2 | tr -d '[:space:]')
    echo "   URL  : http://${DOMAIN:-localhost}"
else
    echo "   URL  : http://localhost:8080"
fi
echo "   Admin: Lihat kredensial di file .env"
echo ""
