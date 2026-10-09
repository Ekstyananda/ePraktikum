# Operasi M0–M6

## Runtime dan sumber

Laravel 13.34.0 terkunci di composer.lock, runtime PHP 8.4.19 FPM Debian Bookworm, Nginx 1.28.2 Alpine, Composer 2.8.12; image dipin dengan tag dan digest. Bootstrap 5.3.8 dibundel dalam public/vendor/bootstrap dengan lisensi MIT dan SHA256SUMS. Tidak ada CDN runtime, Vite, atau kebutuhan npm untuk menjalankan aplikasi.

Sumber resmi yang diperiksa saat implementasi: https://laravel.com/framework/docs/releases (Laravel 13 membutuhkan PHP >=8.3), https://www.php.net/supported-versions.php (cabang 8.4 didukung), https://laravel.com/framework/docs/deployment. Pin ini untuk reproduksibilitas, bukan klaim patch paling baru. Perbarui patch runtime dan lockfile secara terkontrol, jalankan CI sebelum deploy.

## Konfigurasi jaringan MySQL yang sudah ada

Semua perintah bagian ini dijalankan operator pada VM sendiri, bukan dari workspace Codex. Periksa network/container MySQL yang sudah ada; hindari inspeksi environment container karena mungkin memuat rahasia.

```bash
docker network ls
docker network inspect lab-external
# Hanya bila belum ada jaringan bersama yang sesuai:
docker network create lab-external
# Hanya bila MySQL belum bergabung; ganti nama container dengan nama aktual:
docker network connect --alias mysql-lab lab-external NAMA_CONTAINER_MYSQL
```

Jika MySQL sudah berada pada network lain yang sesuai, gunakan network tersebut di MYSQL_EXTERNAL_NETWORK dan alias stabil aktual di DB_HOST. Jangan menjalankan network connect dua kali pada network yang sama. IP lama 172.18.0.5 hanya fallback yang dapat dikonfigurasi; jangan mengandalkannya karena IP container dapat berubah. Network aplikasi `internal` menghubungkan Nginx/PHP. Network `web` hanya untuk Nginx dan published port. App/scheduler/worker bergabung ke network MySQL eksternal; Nginx tidak bergabung ke network DB.

Isi `.env` di server: DB_DATABASE=Lab, DB_CONNECTION=mysql, DB_PORT=3306, DB_USERNAME akun khusus Lab, DB_PASSWORD di server saja, DB_HOST alias, MYSQL_EXTERNAL_NETWORK nama network. Gunakan akun dengan hak hanya untuk Lab; migrasi membutuhkan CREATE/ALTER/INDEX/REFERENCES dan runtime membutuhkan SELECT/INSERT/UPDATE/DELETE. Jangan gunakan root untuk runtime. `.env.example` berisi password dan key kosong. Jangan commit `.env`, dump, atau rahasia.

Periksa port host sebelum memilih APP_PORT: `ss -ltn`. Default 8095 (8080 umum dipakai layanan lain). Build, generate APP_KEY secara pribadi lalu masukkan ke `.env`, migrate non-destructive, bootstrap admin dan `up` sesuai README. Jalankan satu bootstrap; setelah admin ada perintah ini menolak admin kedua. Password tidak ditempatkan dalam argumen command. Tidak ada reset password email pada M1; admin dapat mereset aslab di Profil. Simpan kredensial admin dengan aman.

Verifikasi konektivitas tanpa mutasi DB:

```bash
docker compose run --rm --no-deps app php -r '$h=getenv("DB_HOST"); exit(gethostbyname($h)===$h?1:0);'
docker compose run --rm --no-deps app php artisan migrate:status
docker compose ps
curl -f http://localhost:8095/up
curl -f http://localhost:8095/health/ready
```

`/up` memeriksa boot aplikasi, `/health/ready` menjalankan SELECT 1 dan hanya mengembalikan ready/unavailable; tidak menampilkan error DB atau kredensial. Jangan menjalankan migrate:fresh/reset/rollback massal pada produksi. Migrasi M0–M4 membuat skema pada Lab kosong; tidak menyentuh database lain.

## HTTPS dan trusted proxy

Tetapkan APP_URL ke URL HTTPS aktual dan SESSION_SECURE_COOKIE=true setelah akses HTTPS tersedia. Untuk Cloudflare Tunnel/reverse proxy, arahkan origin ke Nginx dan isi TRUSTED_PROXIES dengan IP/CIDR proxy yang benar-benar berada di depan aplikasi (dipisahkan koma). Jangan memakai wildcard tanpa isolasi origin. Nginx meneruskan header ke PHP-FPM. Session HttpOnly, SameSite=lax; produksi memakai database dan SESSION_ENCRYPT=true. APP_DEBUG=false. Waktu penyimpanan UTC; tampilan jadwal menggunakan Asia/Jakarta sesuai spesifikasi aplikasi.

### Nginx Proxy Manager di VPS

Lihat [15_VPS_NPM.md](15_VPS_NPM.md): overlay `compose.npm.yaml`, `TRUSTED_PROXIES` = IP container NPM persis (bukan subnet).

### Cloudflare Tunnel (cloudflared di host)

Alur: pengunjung → Cloudflare → `cloudflared` di host → `http://localhost:8095` → Nginx (container) → PHP-FPM. Berlaku juga untuk paket gratis.

1. Nginx hanya dibuka di loopback: `APP_BIND=127.0.0.1` (default). Docker membuka port melewati UFW, jadi jangan ganti ke `0.0.0.0` kecuali tanpa tunnel dan firewall sudah ditinjau.
2. Network `web` memakai subnet tetap `WEB_SUBNET=10.231.0.0/24` dengan gateway `WEB_GATEWAY=10.231.0.1`, sehingga IP yang dilihat Nginx tidak berubah setelah `down`/`up`. Bila subnet itu sudah dipakai di VM (cek `docker network ls` lalu `docker network inspect`), ganti keduanya.
3. Isi `TRUSTED_PROXIES` dengan gateway tersebut, misalnya `TRUSTED_PROXIES=10.231.0.1`, lalu `docker compose up -d app scheduler`.
4. Konfigurasi ingress `cloudflared` mengarah ke `http://localhost:8095` (sesuai `APP_PORT`). Isi `APP_URL=https://<domain>` dan `SESSION_SECURE_COOKIE=true`.
5. Verifikasi: buka halaman lewat domain, lalu `docker compose logs --tail=5 nginx`. IP di awal baris harus gateway (`10.231.0.1`). Aplikasi membaca IP asli dari `X-Forwarded-For` yang ditambahkan Cloudflare; isi palsu dari pengunjung di sebelah kiri header diabaikan.

Tanpa `TRUSTED_PROXIES` semua pengunjung tampak dari IP gateway, sehingga batas laju per IP (portal publik dan login) berlaku untuk seluruh pengguna sekaligus.

## Persistensi dan pembaruan

`portal-storage` menyimpan storage Laravel termasuk storage/app/private, log dan cache file. Runtime menggunakan UID/GID 33 (www-data); volume baru mendapatkan permission dari image. Upload privat tidak ada di public, tidak disymlink, dan disk lokal tidak memiliki serve route. Upload impor M2 hanya diproses sementara; staging pratinjau dienkripsi dalam database dan dibersihkan setelah kedaluwarsa. Nginx hanya mengeksekusi /index.php, menolak file PHP lain.

```bash
# Sesudah backup operator terverifikasi dan kode baru ditinjau:
docker compose build app nginx
docker compose run --rm --no-deps app php artisan migrate --force
docker compose up -d --wait
# Bila worker profile digunakan:
docker compose --profile jobs up -d
```

Pembaruan ke portal multi-praktikum: image kini memuat ekstensi GD (sampul WebP), jadi `build app nginx` wajib; migrasi menambah slug otomatis dari kode praktikum (lihat docs/14_MULTI_PRAKTIKUM_GUIDE.md). Simpan tag image lama (`docker tag portal-praktikum-app:latest portal-praktikum-app:prev`, sama untuk `-web`) sebelum build agar bisa kembali bila perlu; skema baru hanya menambah kolom/tabel sehingga image lama tetap kompatibel.

M0–M5 belum memerlukan worker. Scheduler M2 menghapus staging kedaluwarsa tiap jam melalui portal:prune-previews dengan withoutOverlapping/onOneServer. Hanya satu scheduler untuk instalasi ini; jangan scale scheduler. Job masa depan wajib memakai locking dan withoutOverlapping/onOneServer dengan shared cache DB. Jangan memakai `down -v` pada deployment: itu menghapus storage. Recreate app tidak menghapus volume. Cek perubahan skema sebelum rollback image; rollback aplikasi hanya aman bila skema tetap kompatibel. Entrypoint PHP-FPM hanya membersihkan cache Blade persisten agar tampilan lama tidak terbawa setelah update image. Tidak ada migrasi otomatis/destruktif dalam entrypoint.

## Backup dan restore (M6)

Aplikasi membuat satu arsip ZIP berisi dump logis database (`database.sql`), seluruh berkas privat (`files/...`) dan `manifest.json` (jumlah baris per tabel, SHA-256 dump dan tiap berkas). Arsip ditulis sebagai `.partial-*`, dibuka ulang dan diverifikasi, baru diberi nama final beserta berkas `.sha256`. Run tercatat **Berhasil** hanya bila dump, seluruh berkas dan verifikasi sukses; selain itu **Gagal** tanpa arsip. Pesan error disimpan tanpa password atau path absolut. Dump memakai PDO dalam satu *consistent snapshot* InnoDB, tanpa binary `mysqldump` dan tanpa password pada argumen. Data tabel `sessions`, `cache`, `jobs`, token reset dan staging impor tidak ikut (hanya strukturnya).

### Menyiapkan tujuan

```bash
sudo install -d -o 33 -g 33 -m 0750 /DATA/AppData/portal-backups
```

Di `.env`: `BACKUP_HOST_PATH=/DATA/AppData/portal-backups` (folder host, terpisah dari volume `portal-storage`), `BACKUP_DESTINATION=/backups` (path di container), `BACKUP_DESTINATION_LABEL` (nama yang tampil di UI). Isi `BACKUP_PASSWORD` dengan rahasia panjang untuk enkripsi AES-256 setiap entri arsip; **wajib** bila arsip disalin keluar VM. Simpan password itu di luar VM juga, karena arsip tidak bisa dibuka tanpanya. Lalu `docker compose up -d app scheduler`.

### Menjalankan

- **Manual**: Pengaturan → Backup → *Jalankan Backup*. Request hanya mengantrekan; container scheduler memprosesnya di latar belakang dalam ±1 menit. Tidak perlu worker.
- **Terjadwal**: `BACKUP_ENABLED=true`. Jam (WIB) dan jumlah arsip yang disimpan diatur admin di halaman Backup (usulan awal 02:00, 7 arsip). Retensi hanya menghapus arsip `portal-backup-*.zip` yang dibuat aplikasi.
- **CLI**: `docker compose exec app php artisan portal:backup --now`.

Akses halaman: admin, atau aslab yang diberi izin `backup.run` secara eksplisit. Halaman tidak menampilkan path asli atau rahasia. Salinan ke cloud dan backup berpemicu perubahan data (`BACKUP_CHANGE_THRESHOLD`): [16_BACKUP_RCLONE.md](16_BACKUP_RCLONE.md).

### Uji restore (terisolasi)

1. Buat database kosong terpisah, misalnya `portal_restore`, di MySQL yang **bukan** database Lab produksi, atau di container MySQL uji.
2. Isi `RESTORE_DB_HOST`, `RESTORE_DB_DATABASE`, `RESTORE_DB_USERNAME`, `RESTORE_DB_PASSWORD` di `.env` (atau `-e` pada perintah).
3. Jalankan:

```bash
docker compose exec app php artisan portal:backup-verify portal-backup-YYYYMMDD-HHMMSS-N.zip
docker compose exec app php artisan portal:backup-verify portal-backup-YYYYMMDD-HHMMSS-N.zip --restore
```

Perintah pertama memeriksa SHA-256 arsip, dump dan setiap berkas. Perintah kedua memulihkan dump ke koneksi `restore` lalu membandingkan jumlah baris setiap tabel dengan manifest. Perintah menolak bila target sama dengan database aplikasi atau bernama `Lab`.

### Restore produksi (manual, setelah review)

Tidak ada tombol restore. Lakukan hanya setelah uji restore berhasil dan disetujui:

1. `docker compose exec app php artisan down`, lalu buat backup kondisi saat ini (`portal:backup --now`) sebagai titik balik.
2. Ekstrak arsip di host ke folder sementara yang hanya bisa dibaca operator. Arsip terenkripsi AES-256 tidak bisa dibuka `unzip` bawaan; pakai `7z x arsip.zip -o/tmp/restore-portal` (paket `p7zip-full`), yang akan meminta password.
3. Impor `database.sql` ke database Lab dengan client MySQL dan file kredensial (`--defaults-extra-file`), bukan password di argumen.
4. Salin isi `files/` ke volume storage pada `storage/app/private/` dengan pemilik UID 33.
5. `docker compose exec app php artisan portal:backup-verify <arsip>` pada arsip yang sama, periksa data contoh di aplikasi, lalu `php artisan up`. Hapus folder ekstrak.

## Lingkungan tes dan preview

```bash
composer install
php scripts/init-test-env.php
docker compose --env-file .env.testing -f compose.test.yaml up -d --wait
vendor/bin/phpunit
vendor/bin/pint --test
composer validate --strict
sha256sum -c public/vendor/bootstrap/SHA256SUMS
```

TestCase memeriksa MySQL, database portal_test, serta host 127.0.0.1/mysql-test sebelum RefreshDatabase. Jangan mengubah guard ke Lab. Host loopback port 13379 hanya untuk MySQL uji. Ganti DB_PORT di .env.testing jika port itu sudah digunakan. Compose.test memakai tmpfs sehingga data contoh tidak bertahan setelah container dihapus.

Untuk preview lokal: bila `.env` belum ada, salin `.env.testing` menjadi `.env`, ubah APP_ENV=local dan SESSION_DRIVER=database. Jangan menimpa env produksi. `php artisan migrate` hanya ke portal_test. Tetapkan PORTAL_DEMO_PASSWORD melalui environment (kata sandi acak >=12 karakter, jangan commit), lalu `php artisan db:seed --class=PreviewSeeder` (atau `MultiPreviewSeeder` untuk dua praktikum, SBD dan PCD, beserta akun admin2@example.test). Seeder menolak database selain portal_test serta environment selain local/testing. Akun uji admin@example.test dan aslab@example.test berlabel Data contoh memakai password tersebut. Tidak ada praktikan atau jadwal SBD resmi yang ditanam.

```bash
php artisan serve --host=127.0.0.1 --port=18780
```

Preview Compose memakai network uji yang sudah dibuat, tanpa database baru di Compose utama:

```bash
MYSQL_EXTERNAL_NETWORK=portal-praktikum-isolated-test APP_PORT=18781 docker compose --env-file .env.testing -p portal-praktikum-smoke -f compose.yaml -f compose.smoke.yaml build app nginx
MYSQL_EXTERNAL_NETWORK=portal-praktikum-isolated-test APP_PORT=18781 docker compose --env-file .env.testing -p portal-praktikum-smoke -f compose.yaml -f compose.smoke.yaml up -d --wait
```

Screenshot aktual dan PDF layout dasar tersedia di artifacts/. Preview M5 memakai `PublicPreviewSeeder` (hanya portal_test) dan `scripts/browser-m5-check.cjs`; lihat docs/12_M5_GUIDE.md. Browser smoke memakai Playwright 1.58.2 dan `scripts/browser-check.cjs` untuk M1 dan `scripts/browser-m2-check.cjs` untuk M2; set PORTAL_BASE_URL, PORTAL_DEMO_PASSWORD dan mount direktori artifact ke /artifacts. Uji browser membutuhkan database preview yang sudah dimigrasi/di-seed; akun uji browser menggunakan email contoh acak setiap run. Jalankan tes PHPUnit terlebih dahulu karena RefreshDatabase mengatur ulang database pengujian. Screenshot tidak mengandung kata sandi. PDF adalah pemeriksaan layout dasar A4, bukan lembar TTD/presensi M3.

Hentikan hanya project pengujian setelah selesai: `docker compose --env-file .env.testing -f compose.test.yaml down` dan stack smoke menggunakan prefix/env/perintah compose yang sama dengan `down`. Jangan menghentikan container MySQL produksi.

Panduan pengelolaan master, dosen opsional, bulk assignment, impor dan ekspor M2: [09_M2_GUIDE.md](09_M2_GUIDE.md).


M3 menambah berkas modul/scan privat pada storage/app/private/academic. File tidak berada dalam public dan tidak perlu storage:link. Publikasi modul memakai route yang memeriksa published_at setiap request; scan selalu membutuhkan permission sesi. PHP upload/post 11M/12M dan Nginx 16M; batas aplikasi M3 10 MB (dapat diturunkan melalui ACADEMIC_UPLOAD_MAX_KB), impor M2 tetap 5 MB. Backup operator wajib memasukkan volume storage serta database dengan skema M3. Panduan: [10_M3_GUIDE.md](10_M3_GUIDE.md).

Preview M3 setelah PHPUnit: tetapkan PORTAL_DEMO_PASSWORD acak dalam environment, lalu `php artisan db:seed --class=MeetingPreviewSeeder`. Guard menolak server/database produksi. Seeder menolak duplikasi DEMO-M3; jalankan kembali tes hanya bila ingin mengatur ulang seluruh database uji. Browser `scripts/browser-m3-check.cjs` menggunakan Playwright 1.58.2 dan direktori artifact /artifacts. QA PDF terpisah menggunakan scripts/verify-m3-pdf.py dengan PyMuPDF 1.26.4.
