# LEMBAR TANYA-JAWAB KRUSIAL MAHASISWA IT (CHEATSHEET Q&A)

> **Informasi Sistem & Versi Instance Terverifikasi:**  
> - **SuiteCRM Core Version:** 8.8.1 (Angular 18 Frontend / Legacy Engine 7.14.7 / Sugar 6.5.25 CE)  
> - **Database Server:** MariaDB 10.11.11-debian-12-r11  
> - **Tujuan Dokumen:** Kumpulan jawaban komprehensif atas pertanyaan teknis dan arsitektur yang sering muncul dalam ujian dan praktikum mata kuliah Sistem Informasi Perusahaan.

---

### Q1: Bagaimana cara SuiteCRM mengirim Webhook notifikasi ke sistem lain (misalnya Slack, Telegram, atau ERP)?
**Jawaban:**
Terdapat dua pendekatan standar industri di SuiteCRM:
1. **Pendekatan Full-Code (Logic Hook PHP):**  
   Dibuat file hook pada `custom/modules/Opportunities/logic_hooks.php` dengan trigger `after_save`. Ketika kolom `$bean->sales_stage === 'Closed Won'`, script menjalankan HTTP POST request menggunakan pustaka `curl` bawaan PHP atau Guzzle HTTP client ke endpoint Webhook eksternal.  
   *Kelebihan:* Mendukung transformasi payload kompleks, enkripsi signature HMAC, dan mekanisme *retry*.
2. **Pendekatan Low-Code (SuiteCRM Workflow):**  
   Melalui menu **Admin → Workflow Management**, buat aturan baru pada modul Opportunities dengan kondisi `Sales Stage = Closed Won`, lalu tambahkan action pengiriman HTTP request atau email notifikasi.

---

### Q2: Kapan seorang Business Analyst / Software Engineer menggunakan Studio vs. Logic Hook?
**Jawaban:**
- **Gunakan Studio (No-Code UI):**  
  Ketika perubahan bersifat **struktural deklaratif**, seperti: menambah field tanggal kadaluarsa garansi mesin, mengubah susunan layout form entri data, menambah opsi dropdown kategori kopi, atau mengatur field menjadi *mandatory/required*. Studio aman terhadap update versi dan tidak memicu bug runtime kode.
- **Gunakan Logic Hook (Full-Code PHP):**  
  Ketika perubahan melibatkan **kalkulasi dinamis, aturan validasi antar-modul, atau integrasi pihak ketiga**, seperti: menghitung otomatis skor risiko churn akun berdasarkan frekuensi komplain di modul Cases, memblokir pembuatan opportunity jika kontak belum memvalidasi nomor telepon, atau sinkronisasi data ke sistem perbankan.

---

### Q3: Bagaimana arsitektur REST API SuiteCRM untuk integrasi dengan aplikasi pihak ketiga (Mobile App / E-Commerce)?
**Jawaban:**
SuiteCRM 8 menyediakan dua antarmuka API modern:
1. **REST API v8 (JSON:API Standard):**  
   Mengadopsi spesifikasi [jsonapi.org](https://jsonapi.org). Menggunakan otentikasi standar industri **OAuth2 (Bearer Token)** dengan endpoint `/api/oauth/v2/token`.
2. **GraphQL API:**  
   Diperkenalkan pada SuiteCRM 8 (`/api/graphql`) untuk memberikan kemampuan query data secara presisi, memungkinkan aplikasi mobile sales hanya mengambil atribut spesifik (misal: hanya mengambil `name` dan `phone_office`) guna menghemat konsumsi kuota bandwidth di lapangan.

---

### Q4: Mengapa SuiteCRM 8 memisahkan antara frontend Angular dan backend legacy PHP?
**Jawaban:**
Pemisahan ini dikenal sebagai strategi **Decoupled Modernization Architecture**:
- **Lapisan Frontend (Angular 18):** Menyediakan antarmuka Single Page Application (SPA) yang interaktif, mendukung modul federasi (*Module Federation*), dan pengalaman pengguna (*user experience*) yang sebanding dengan SaaS modern seperti Salesforce.
- **Lapisan Backend (Legacy PHP 8.1 / SugarBean):** SuiteCRM mewarisi logika bisnis matur selama lebih dari 15 tahun (manajemen hak akses peran/Role, mesin workflow, PDF generator, pelaporan agregat). Menulis ulang seluruh backend enterprise dari nol ke framework baru akan memakan waktu bertahun-tahun dan rentan bug stabilitas. Dengan mempertahankan engine legacy di belakang API, kestabilan logika enterprise tetap terjaga.

---

### Q5: Mengapa modul Quotes (Penawaran) di SuiteCRM tidak boleh dianggap sebagai modul ERP / Inventory?
**Jawaban:**
Dalam CRM Murni (*Pure CRM*), modul *Quotes* semata-mata adalah **alat pembuat proposal komersial (PDF Generator)** untuk mengomunikasikan estimasi harga kepada calon pembeli.  
- Modul Quotes **tidak memiliki logika pelacakan stok fisik gudang (*no warehouse tracking*)**.
- Modul Quotes **tidak memotong kartu stok (*zero stock reservation*)**.
- Modul Quotes **tidak menghasilkan jurnal akuntansi persediaan (*no GL journal entries*)**.  
Pelacakan stok, penerimaan barang, dan kalkulasi HPP (*COGS*) adalah domain sah dari sistem **ERP/SCM**, bukan CRM.

---

### Q6: Mengapa image Bitnami yang digunakan berlabel `bitnamilegacy` dan apa konsekuensi keamanannya?
**Jawaban:**
- **Latar Belakang:** VMware/Bitnami memindahkan katalog image publik gratis lama mereka ke namespace `bitnamilegacy` dan membekukan pembaruan (*frozen repository*), sementara image baru dialihkan ke program komersial *Bitnami Secure Images*.
- **Konsekuensi Keamanan:** Image `bitnamilegacy/suitecrm:8.8.1-debian-12-r3` tidak lagi menerima pembaruan patch keamanan (*security CVE patches*) dan perbaikan dependensi OS Linux.
- **Batasan Penggunaan:** Image ini **HANYA BOLEH DIGUNAKAN UNTUK DEMO LOKAL KULIAH (*Local Educational Demonstration Only*)** dengan port yang diikat ketat ke `127.0.0.1`. **DILARANG KERAS** digunakan di server produksi publik internet terbuka.

---

### Q7: Mengapa di basis data nilai dropdown disimpan sebagai KUNCI (*Key*), bukan LABEL bahasa (*Label*)?
**Jawaban:**
Contoh: Nilai kolom `cases.status` di tabel MariaDB tersimpan sebagai `Open_New`, namun di antarmuka web muncul teks `"New"`.
1. **Dukungan Multibahasa (Internationalization / i18n):** Jika pengguna dari Surabaya login, label dapat diterjemahkan menjadi `"Baru"`, sementara pengguna internasional melihat `"New"`, tanpa perlu mengubah nilai baris data di MariaDB.
2. **Kekebalan Logika Bisnis (*Immutability of Logic*):** Logic Hook, Workflow, dan query integrasi API merujuk pada key statis `Open_New`. Jika tim marketing ingin mengubah teks UI dari `"New"` menjadi `"Menunggu Respon"`, seluruh kode program dan query analitik tidak akan rusak.
3. **Efisiensi Penyimpanan Indeks:** Kunci yang ringkas dan terstandarisasi memudahkan pengindeksan basis data dibanding teks bebas.

---

### Q8: Bagaimana cara mereset total seluruh lingkungan demo jika terjadi kegagalan data saat praktikum?
**Jawaban:**
Cukup jalankan perintah otomatis berikut pada terminal:
```bash
make reset
```
Perintah ini akan secara atomik:
1. Mematikan seluruh kontainer dan **menghapus volume persistent data** (`docker compose down -v`).
2. Menghidupkan ulang container MariaDB, SuiteCRM, dan Mailpit secara bersih (`docker compose up -d`).
3. Menunggu selesainya inisialisasi basis data hingga HTTP 200 terverifikasi (`wait_for_suitecrm.sh`).
4. Menguji bootstrap core (`smoke_bootstrap.php`).
5. Memasukkan seluruh data seed B2B PT Sumber Kopi Nusantara secara idempotent (`seed_suitecrm_b2b.php`).
6. Menginstal paket Bahasa Indonesia (`install_id_lang.php`).
7. Memverifikasi 20 assertion integritas relasi CRM 5 tahun (`verify_seed.php` - 20/20 PASS).
Sistem kembali 100% ke kondisi demonstrasi awal yang sempurna dalam hitungan menit.

---

### Q9: Bagaimana SuiteCRM membedakan antara Pelanggan Baru (*New Acquisition*) dengan Pelanggan Setia Multi-Tahun (*Retention / Account Longevity*)?
**Jawaban:**
Melalui kombinasi data arsitektur berikut:
1. **Kolom `opportunities.opportunity_type`:**  
   Membedakan secara tegas antara `'New Business'` (transaksi mesin pertama kali, misal Grand Majapahit 2021) dan `'Existing Business'` (pengadaan unit ke-2 2025 atau addendum perpanjangan kontrak biji kopi 2026).
2. **Multi-Year Timeline di Subpanel History & Notes:**  
   Akun loyal seperti *Grand Majapahit Hotel Surabaya* memiliki deretan catatan kronologis Berita Acara Serah Terima (BAST) 2021 & 2025, laporan tahunan *Preventive Maintenance Report* (PMR) 2022-2025, dan notulen negosiasi perpanjangan kontrak.
3. **Metrik Analisis Churn & Customer Lifetime Value (CLV):**  
   Sistem analitik CRM mengakumulasikan seluruh nilai deal *Closed Won* yang berhasil ditutup dari satu akun selama 5 tahun (mencapai lebih dari Rp 340+ Juta untuk Grand Majapahit), memberikan justifikasi bagi tim Customer Success untuk memberikan layanan prioritas (VIP SLA).

