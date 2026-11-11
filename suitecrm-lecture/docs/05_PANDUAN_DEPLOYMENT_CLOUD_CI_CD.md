# PANDUAN DEPLOYMENT OTOMATIS SUITECRM 8 KE CLOUD HOSTING (CI/CD)

Dokumen ini memandu langkah demi langkah menyiapkan dan menjalankan **Automated CI/CD Deployment** untuk stack SuiteCRM 8 (studi kasus: PT Sumber Kopi Nusantara) ke Cloud VPS (DigitalOcean, AWS EC2, GCP Compute Engine, Hetzner, IDCloudHost, Biznet Gio, dll.) menggunakan **GitHub Actions**.

---

## 1. Arsitektur CI/CD Pipeline

```
[ Developer Local ]
       │  git push origin main
       ▼
[ GitHub Repository ] 
       │  triggers workflow .github/workflows/deploy.yml
       ▼
[ GitHub Actions Runner (Ubuntu) ]
  ├── 1. Lint & Validate docker-compose.yml dan skrip bash
  ├── 2. Generate file .env server dari GitHub Secrets (ENV_FILE)
  ├── 3. SCP Transfer file stack ke Cloud VPS via SSH Key
  └── 4. SSH Remote Execution di Cloud VPS:
         ├── docker compose pull & docker compose up -d
         ├── bash scripts/wait_for_suitecrm.sh (tunggu hingga installer lock)
         ├── make smoke (verifikasi bootstrap PHP CLI)
         ├── make seed-data (seeding data 1 tahun PT Sumber Kopi Nusantara)
         ├── make install-lang (pemasangan paket Bahasa Indonesia)
         └── make verify (20/20 assertions PASS)
       │
       ▼
[ Cloud VPS (Ubuntu / Debian) ]
  ├── SuiteCRM 8 (Container: http://localhost:8080)
  ├── MariaDB 10.11 (Internal Container)
  └── Mailpit (Container: Web UI http://localhost:8025)
       │
       ▼ (Opsional tapi Direkomendasikan)
[ Nginx / Caddy Reverse Proxy + SSL Let's Encrypt ]
  └── https://crm.sumberkopi.id (Akses Pengguna / Mahasiswa)
```

---

## 2. Kebutuhan Server Cloud (VPS)

| Komponen | Spesifikasi Minimum | Rekomendasi Demo / Perkuliahan |
| :--- | :--- | :--- |
| **Sistem Operasi** | Ubuntu 22.04 / 24.04 LTS atau Debian 12 | Ubuntu 24.04 LTS (x86_64) |
| **CPU** | 2 vCPU | 2 - 4 vCPU |
| **RAM** | 2 GB (+ 2 GB Swap file) | 4 GB RAM |
| **Penyimpanan** | 25 GB SSD | 40 GB NVMe SSD |
| **Jaringan** | 1 IP Publik Statis | Port 22 (SSH), 80/443 (HTTP/HTTPS) |

> [!IMPORTANT]
> SuiteCRM 8 mengompilasi dan meng-cache aset Angular SPA serta metadata Symfony pada saat booting pertama. Pastikan VPS memiliki RAM minimal 2 GB dan aktifkan swap file minimal 2 GB agar proses build database Bitnami tidak mengalami *Out-Of-Memory* (OOM).

---

## 3. Persiapan di Cloud VPS

Lakukan SSH ke VPS baru Anda, lalu jalankan perintah berikut:

### 3.1. Buat Swap File 2 GB (Wajib jika RAM <= 2 GB)
```bash
sudo fallocate -l 2G /swapfile
sudo chmod 600 /swapfile
sudo mkswap /swapfile
sudo swapon /swapfile
echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab
```

### 3.2. Instal Docker Engine & Docker Compose
```bash
# Update paket sistem
sudo apt-get update && sudo apt-get upgrade -y
sudo apt-get install -y curl ca-certificates gnupg lsb-release make git

# Tambah GPG key resmi Docker
sudo install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/ubuntu/gpg | sudo gpg --dearmor -o /etc/apt/keyrings/docker.gpg
sudo chmod a+r /etc/apt/keyrings/docker.gpg

# Tambah repositori Docker
echo \
  "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/ubuntu \
  $(lsb_release -cs) stable" | sudo tee /etc/apt/sources.list.d/docker.list > /dev/null

# Instal Docker & Plugin Compose
sudo apt-get update
sudo apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin

# Masukkan user ke grup docker agar tidak perlu sudo saat deploy
sudo usermod -aG docker $USER
```

> [!TIP]
> Setelah menjalankan `usermod -aG docker $USER`, logout dari sesi SSH (`exit`) lalu login kembali agar izin grup docker aktif. Uji dengan menjalankan `docker run --rm hello-world`.

### 3.3. Buat Folder Target Deployment
```bash
mkdir -p ~/suitecrm-lecture
```

---

## 4. Setup SSH Key untuk GitHub Actions

GitHub Actions membutuhkan SSH Private Key untuk masuk ke VPS dan melakukan deployment.

### 4.1. Buat Pasangan Kunci SSH Baru di Komputer Anda (atau di VPS)
Jalankan di terminal lokal Anda:
```bash
ssh-keygen -t ed25519 -C "github-actions-suitecrm" -f suitecrm_deploy_key
```
Perintah ini akan menghasilkan dua file:
1. `suitecrm_deploy_key` (Private Key — **RAHASIA**, disimpan di GitHub Secrets).
2. `suitecrm_deploy_key.pub` (Public Key — dimasukkan ke VPS).

### 4.2. Daftarkan Public Key ke VPS
Tambahkan isi `suitecrm_deploy_key.pub` ke file `~/.ssh/authorized_keys` di VPS:
```bash
# Di VPS Anda:
echo "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAI... isi_public_key_anda" >> ~/.ssh/authorized_keys
chmod 600 ~/.ssh/authorized_keys
chmod 700 ~/.ssh
```

---

## 5. Konfigurasi GitHub Repository Secrets

Buka repositori GitHub Anda di browser:
1. Klik tab **Settings** > **Secrets and variables** > **Actions**.
2. Klik tombol **New repository secret**.
3. Tambahkan 5 rahasia berikut:

| Nama Secret | Deskripsi / Contoh Nilai |
| :--- | :--- |
| `SSH_HOST` | IP publik VPS Anda (contoh: `103.187.12.34`) atau nama domain (`crm.domainanda.com`). |
| `SSH_USER` | Username login SSH VPS Anda (contoh: `ubuntu`, `debian`, atau `root`). |
| `SSH_KEY` | Isi lengkap teks **Private Key** `suitecrm_deploy_key` (termasuk baris `-----BEGIN OPENSSH PRIVATE KEY-----` hingga `-----END OPENSSH PRIVATE KEY-----`). |
| `SSH_PORT` | Port SSH server Anda (isi `22` jika menggunakan port default). |
| `DEPLOY_PATH` | Path direktori di VPS (contoh: `/home/ubuntu/suitecrm-lecture` atau `~/suitecrm-lecture`). |
| `ENV_FILE` | **Seluruh isi file `.env` untuk server produksi** (lihat panduan di bawah). |

### Template Isi Secret `ENV_FILE`:
Salin template berikut, ganti password dengan string acak yang aman, dan paste ke dalam secret `ENV_FILE`:

```ini
SUITECRM_IMAGE=bitnamilegacy/suitecrm:8.8.1-debian-12-r3
MARIADB_IMAGE=bitnamilegacy/mariadb:10.11.11-debian-12-r11
MAILPIT_IMAGE=axllent/mailpit:v1.33.0

RESTART_POLICY=unless-stopped

# Port binding:
# Jika menggunakan Nginx/Caddy di VPS yang sama, gunakan 127.0.0.1:8080:8080
# Jika ingin langsung diakses via IP publik VPS, ubah menjadi 8080:8080
SUITECRM_PORT_BINDING=127.0.0.1:8080:8080
MAILPIT_HTTP_BINDING=127.0.0.1:8025:8025
MAILPIT_SMTP_BINDING=127.0.0.1:1025:1025

MARIADB_ROOT_PASSWORD=RootSecret_Kopi2026_Secure!
MARIADB_USER=suitecrm
MARIADB_PASSWORD=DbSecret_Kopi2026_Secure!
MARIADB_DATABASE=bitnami_suitecrm

SUITECRM_DATABASE_HOST=mariadb
SUITECRM_DATABASE_PORT_NUMBER=3306
SUITECRM_DATABASE_USER=suitecrm
SUITECRM_DATABASE_PASSWORD=DbSecret_Kopi2026_Secure!
SUITECRM_DATABASE_NAME=bitnami_suitecrm

SUITECRM_USERNAME=admin
SUITECRM_PASSWORD=AdminKopi_Master_2026!
SUITECRM_EMAIL=admin@sumberkopi.id

# Host domain atau IP VPS Anda (tanpa port :8080):
# Contoh: crm.sumberkopi.id atau 103.187.12.34
SUITECRM_HOST=103.187.12.34

MAILPIT_SMTP_HOST=mailpit
MAILPIT_SMTP_PORT=1025
```

---

## 6. Setup Reverse Proxy Nginx & SSL Let's Encrypt (Sangat Direkomendasikan)

Untuk deployment produksi di cloud yang aman, cepat, dan profesional menggunakan nama domain (misal: `crm.domainanda.com`), **Nginx** adalah reverse proxy standar industri paling stabil.

File konfigurasi Nginx siap pakai telah disediakan di folder repositori: [`nginx/suitecrm.conf`](file:///c:/Users/Pongo/Proyek/python/crm_suite/suitecrm-lecture/nginx/suitecrm.conf).

### 6.1. Instal Nginx & Certbot di VPS
Jalankan perintah berikut di terminal SSH VPS Anda:
```bash
sudo apt update
sudo apt install -y nginx certbot python3-certbot-nginx
```

### 6.2. Pasang Konfigurasi Reverse Proxy Nginx
Salin konfigurasi dari folder proyek ke direktori konfigurasi Nginx:
```bash
# Salin file konfigurasi
sudo cp ~/suitecrm-lecture/nginx/suitecrm.conf /etc/nginx/sites-available/suitecrm.conf

# Sesuaikan nama domain Anda (ganti crm.domainanda.com dengan domain asli Anda)
sudo nano /etc/nginx/sites-available/suitecrm.conf

# Aktifkan situs dengan membuat symbolic link
sudo ln -sf /etc/nginx/sites-available/suitecrm.conf /etc/nginx/sites-enabled/

# Hapus konfigurasi default Nginx jika belum dihapus
sudo rm -f /etc/nginx/sites-enabled/default

# Uji validitas sintaks konfigurasi Nginx
sudo nginx -t

# Muat ulang Nginx
sudo systemctl reload nginx
```

> [!IMPORTANT]
> **Parameter Kritis Nginx untuk SuiteCRM 8:**
> 1. `client_max_body_size 50M;` — Wajib disetel agar upload modul, dokumen PDF proposal, dan lampiran email tidak ditolak oleh Nginx dengan error `413 Request Entity Too Large`.
> 2. `proxy_read_timeout 300s;` — Menghindari error `504 Gateway Timeout` saat menjalankan laporan analitik atau kalkulasi pipeline berat.
> 3. `proxy_set_header Upgrade $http_upgrade;` & `Connection "upgrade";` — Wajib untuk kestabilan Angular SPA dan WebSocket Mailpit (`/api/v1/ws`).
> 4. `proxy_buffer_size 128k;` — Menghindari pemotongan header cookie sesi Symfony dan token OAuth2.

### 6.3. Pasang Sertifikat SSL Gratis (HTTPS Otomatis via Certbot)
Jalankan Certbot untuk mendapatkan sertifikat SSL resmi Let's Encrypt secara gratis:
```bash
sudo certbot --nginx -d crm.domainanda.com
```
- Masukkan alamat email Anda untuk notifikasi perpanjangan.
- Setujui syarat dan ketentuan (*Terms of Service*).
- Pilih opsi pengalihan otomatis seluruh traffic HTTP ke HTTPS (*Redirect*).
- *Certbot akan otomatis memperbarui sertifikat SSL setiap 90 hari di latar belakang melalui systemd timer.*

### 6.4. Periksa Nilai `SUITECRM_HOST` di `.env`
Pastikan di file `.env` server Anda (atau di GitHub Secret `ENV_FILE`), variabel `SUITECRM_HOST` disesuaikan dengan domain publik:
```ini
SUITECRM_HOST=crm.domainanda.com
SUITECRM_PORT_BINDING=127.0.0.1:8080:8080
```
Dengan konfigurasi ini, port `8080` hanya terbuka di localhost VPS (`127.0.0.1`), dan seluruh pengguna dari internet mengakses secara aman melalui port `443` (HTTPS) yang dikelola oleh Nginx!

---

### 6.5. Setup Khusus Jagoan Cloud (Docker Engine + Domain Bawaan)
Jika Anda menggunakan layanan **Jagoan Cloud** (Jelastic PaaS / Cloud VPS) yang menjalankan **Docker Engine** dan ingin menggunakan **domain bawaan** (contoh: `env-xxxxxx.jagoancloud.com` atau `app-xxxx.jagoancloud.com`):

Anda **TIDAK PERLU menginstal Nginx manual di OS server**. Kami telah menyertakan container Nginx siap pakai langsung di dalam `docker-compose.yml` yang menggunakan konfigurasi wildcard [`nginx/docker-nginx.conf`](file:///c:/Users/Pongo/Proyek/python/crm_suite/suitecrm-lecture/nginx/docker-nginx.conf) (otomatis menangani domain bawaan apa pun):

1. **Konfigurasi file `.env` di server Jagoan Cloud Anda:**
   ```ini
   # Aktifkan reverse proxy Nginx di dalam Docker Engine
   COMPOSE_PROFILES=nginx

   # Port binding
   NGINX_PORT_BINDING=80:80
   SUITECRM_PORT_BINDING=127.0.0.1:8080:8080
   MAILPIT_HTTP_BINDING=8025:8025

   # Masukkan domain bawaan dari dashboard Jagoan Cloud Anda (tanpa http:// dan tanpa :8080)
   SUITECRM_HOST=env-xxxxxx.jagoancloud.com
   ```

2. **Jalankan Stack di Terminal Web / SSH Jagoan Cloud:**
   ```bash
   make all
   ```
   *Atau langkah demi langkah:*
   ```bash
   docker compose up -d
   bash scripts/wait_for_suitecrm.sh
   make smoke
   make seed-data
   make install-lang
   make verify
   ```

3. **Akses Langsung di Browser:**
   - **SuiteCRM 8:** Buka `http://env-xxxxxx.jagoancloud.com` (langsung di port 80 standar, tanpa perlu mengetik `:8080`).
   - **Mailpit Web UI (Email Testing):** Buka `http://env-xxxxxx.jagoancloud.com:8025`.

---

### Opsi Alternatif: Menggunakan Caddy (Auto-HTTPS Tanpa Certbot)
Jika di server lain Anda ingin menggunakan Caddy:
```bash
sudo apt install -y debian-keyring debian-archive-keyring apt-transport-https curl
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' | sudo gpg --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' | sudo tee /etc/apt/sources.list.d/caddy-stable.list
sudo apt update && sudo apt install caddy -y

# Konfigurasi /etc/caddy/Caddyfile
crm.domainanda.com {
    reverse_proxy 127.0.0.1:8080
}
sudo systemctl reload caddy
```

### 6.6. Konfigurasi HTTPS / SSL (Let's Encrypt atau Self-Signed)

Stack Nginx container sudah siap melayani HTTPS (port 443) dengan konfigurasi TLS modern (TLSv1.2, TLSv1.3, session cache, dan HSTS).

#### Opsi A: Menggunakan Sertifikat Bawaan (Self-Signed)
Sertifikat self-signed default sudah disertakan di `./nginx/ssl/` dan langsung aktif saat container Nginx dijalankan. Seluruh lalu lintas data dienkripsi dengan HTTPS.

#### Opsi B: Mendapatkan Sertifikat Let's Encrypt Resmi (Gratis)
Jika Anda menggunakan domain bawaan Jagoan Cloud atau domain kustom yang terhubung ke IP publik:
```bash
# 1. Jalankan script setup SSL dengan domain Anda:
bash scripts/setup_ssl.sh --letsencrypt -d env-12345.jagoancloud.com --email admin@sumberkopi.id

# 2. Restart Nginx:
docker compose restart nginx

# 3. Pastikan protokol di .env sudah https:
# SUITECRM_PROTO=https
```

---

## 7. Menguji dan Memantau Deployment

### 7.1. Trigger Otomatis via Git Push
Setiap kali Anda melakukan push ke branch `main`:
```bash
git add .
git commit -m "feat: setup automated deployment workflow"
git push origin main
```
GitHub Actions akan secara otomatis menjalankan pipeline di tab **Actions**.

### 7.2. Trigger Manual via GitHub Web UI
1. Buka repositori di GitHub > klik tab **Actions**.
2. Pilih workflow **CI/CD Deploy SuiteCRM to Cloud Hosting** di menu sebelah kiri.
3. Klik dropdown **Run workflow**.
4. Anda dapat memilih opsi:
   - `Jalankan seeder B2B data (make seed-data)` (default: `true`)
   - `Install language pack Bahasa Indonesia (make install-lang)` (default: `true`)
   - `PERINGATAN: Reset volume & data database dari awal` (default: `false`)
5. Klik tombol hijau **Run workflow**.

### 7.3. Memeriksa Log Deployment
Buka execution run di GitHub Actions. Jika berhasil, Anda akan melihat log seperti berikut:
```text
[DEPLOY] Starting Docker Compose stack...
[DEPLOY] Waiting for SuiteCRM initialization and installer lock...
[wait] READY after 45s (HTTP 200 + /bitnami/suitecrm/public/legacy/config.php installer_locked verified).
[DEPLOY] Running bootstrap smoke test...
[SMOKE] SuiteCRM 8.8.1 Bootstrap OK.
[DEPLOY] Running B2B data seeder...
[SEED] B2B Seeder completed successfully.
[DEPLOY] Installing Indonesian language pack...
[LANG] Language pack id_ID installed successfully.
[DEPLOY] Running 20/20 verification assertions...
[PASS] Users count: 3 (admin + 2 staff)
[PASS] Accounts count: 5
[PASS] Contacts count: 5
[PASS] Opportunities count: 5
[PASS] Calls count: 5
[PASS] Meetings count: 3
[PASS] Cases count: 2
[PASS] Notes timeline count: 12
...
==========================================================
 [DEPLOY] SUCCESS: SuiteCRM is up, seeded, and verified!  
==========================================================
```

---

## 8. Troubleshooting Masalah Umum

### 1. Error: `Permission denied (publickey)`
- Pastikan isi private key di GitHub Secret `SSH_KEY` lengkap dari baris `-----BEGIN ...` sampai `-----END ...`.
- Pastikan public key sudah ada di file `~/.ssh/authorized_keys` milik user di VPS.
- Pastikan permission file di VPS benar: `chmod 700 ~/.ssh && chmod 600 ~/.ssh/authorized_keys`.

### 2. Error: `docker: permission denied while trying to connect to the Docker daemon socket`
- Pastikan user SSH Anda sudah dimasukkan ke grup docker: `sudo usermod -aG docker $USER`.
- Lakukan restart SSH session atau jalankan `sudo systemctl restart docker`.

### 3. Error: `Timed out waiting for SuiteCRM readiness`
- Periksa ketersediaan RAM di VPS dengan perintah `free -h`. Jika RAM habis (< 200 MB free), aktifkan swap file 2 GB (lihat Bagian 3.1).
- Periksa log container dengan perintah `docker compose logs --tail=100 suitecrm`.

### 4. Tampilan SuiteCRM blank / CSS tidak termuat
- Periksa nilai `SUITECRM_HOST` di `.env` VPS. Nilai ini harus berupa IP publik atau nama domain yang diketikkan di browser (tanpa `http://` dan tanpa `:8080`).
- Jalankan `make verify` untuk memastikan kontainer sehat.

