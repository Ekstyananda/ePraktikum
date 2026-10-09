# Pindah ke VPS dengan Nginx Proxy Manager

Alur baru: pengunjung → DNS (tanpa proxy Cloudflare) → **Nginx Proxy Manager (NPM)** di VPS, port 443 → Nginx portal (container) → PHP-FPM. Tidak ada cloudflared.

Semua perintah dijalankan operator sendiri. Jangan menampilkan `.env` di layar bersama/log.

## 0. Prasyarat VPS

- Docker + Compose v2, NPM sudah jalan dan membuka port 80/443. Firewall hanya membuka 22, 80, 443 (dan 81 untuk admin NPM bila perlu, sebaiknya dibatasi IP).
- RAM: tiap worker PHP-FPM ±60 MB saat sibuk. Default 16 worker (±1 GB). VPS 2 GB: `FPM_MAX_CHILDREN=10`.
- MySQL 8 di VPS. Bila sudah ada container MySQL, pakai itu (lihat docs/08 bagian jaringan). Bila belum ada:

```bash
docker network create lab-external
docker volume create mysql-lab-data
# Password root diketik interaktif lewat file sementara, jangan masuk riwayat shell.
read -rs -p "Root password MySQL: " P && printf 'MYSQL_ROOT_PASSWORD=%s\n' "$P" > /root/mysql-lab.env && chmod 600 /root/mysql-lab.env && unset P
docker run -d --name mysql-lab --restart unless-stopped --network lab-external --network-alias mysql-lab \
  --env-file /root/mysql-lab.env -v mysql-lab-data:/var/lib/mysql mysql:8.0
```

  Lalu buat database dan akun khusus (ganti password):

```sql
CREATE DATABASE Lab CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'portal_lab'@'%' IDENTIFIED BY '<password-kuat>';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES, DROP ON Lab.* TO 'portal_lab'@'%';
```

  (`docker exec -it mysql-lab mysql -uroot -p`.) MySQL tidak di-publish ke internet.

## 1. Backup di server lama

```bash
cd /DATA/AppData/Project
docker compose exec app php artisan down
docker compose exec app php artisan portal:backup --now
docker compose exec app php artisan portal:backup-verify portal-backup-YYYYMMDD-HHMMSS-N.zip
```

Mode maintenance mencegah data baru masuk setelah backup. Salin arsip (dan `.sha256`) dari `/DATA/AppData/portal-backups` ke VPS lewat `scp`. Salin juga nilai `.env` lama secara pribadi (APP_KEY sebaiknya tetap sama; `BACKUP_PASSWORD` dibutuhkan untuk membuka arsip terenkripsi).

## 2. Kode dan `.env` di VPS

```bash
git clone <repo> /opt/portal-praktikum && cd /opt/portal-praktikum
cp .env.example .env && chmod 600 .env
sudo install -d -o 33 -g 33 -m 0750 /opt/portal-backups
```

Isi `.env` dari nilai lama, lalu ubah:

| Variabel | Nilai |
|---|---|
| `APP_URL` | `https://<domain>` |
| `SESSION_SECURE_COOKIE` | `true` |
| `COMPOSE_FILE` | `compose.yaml:compose.npm.yaml` |
| `NPM_NETWORK` | network Docker NPM (langkah 3) |
| `TRUSTED_PROXIES` | IP container NPM (langkah 3) |
| `DB_HOST` / `MYSQL_EXTERNAL_NETWORK` | `mysql-lab` / `lab-external` (atau milik MySQL yang sudah ada) |
| `BACKUP_HOST_PATH` | `/opt/portal-backups` |

`APP_BIND=127.0.0.1` dibiarkan: port 8095 hanya untuk cek dari VPS, publik masuk lewat NPM.

## 3. Jaringan NPM dan IP tepercaya

```bash
docker ps --format '{{.Names}}' | grep -i proxy          # nama container NPM
docker inspect <npm> -f '{{range $n,$v := .NetworkSettings.Networks}}{{$n}} {{$v.IPAddress}}{{"\n"}}{{end}}'
```

Isi `NPM_NETWORK` dengan nama network dan `TRUSTED_PROXIES` dengan **IP NPM persis** (misal `172.20.0.2`), bukan subnet `/16` atau `/24`. Subnet memuat gateway Docker; siapa pun yang masuk lewat gateway dapat memalsukan `X-Forwarded-For` dan melewati batas laju.

IP container bisa berubah bila NPM dibuat ulang. Kunci di compose NPM:

```yaml
services:
  app:            # service NPM
    networks:
      default:
        ipv4_address: 172.20.0.2
networks:
  default:
    ipam:
      config:
        - subnet: 172.20.0.0/24
```

## 4. Build, restore, jalankan

```bash
docker compose build app nginx
# Database kosong diisi dari dump (berisi CREATE TABLE), bukan dari migrate.
7z x portal-backup-*.zip -o/root/restore-portal      # minta BACKUP_PASSWORD bila terenkripsi
docker cp /root/restore-portal/database.sql mysql-lab:/tmp/portal.sql
docker exec -it mysql-lab sh -c 'mysql -uportal_lab -p Lab < /tmp/portal.sql; rm -f /tmp/portal.sql'
docker compose run --rm --no-deps app php artisan migrate:status
docker compose run --rm --no-deps app php artisan migrate --force   # hanya menambah migrasi yang belum ada
# Berkas privat ke volume storage (UID 33).
docker run --rm --user 0 --entrypoint sh -v portal-praktikum_portal-storage:/s -v /root/restore-portal/files:/f:ro portal-praktikum-app:latest \
  -c 'mkdir -p /s/app/private && cp -a /f/. /s/app/private/ && chown -R 33:33 /s/app/private'
docker compose up -d --wait
docker compose exec app php artisan portal:backup-verify <arsip-yang-disalin-ke-/opt/portal-backups>
rm -rf /root/restore-portal
```

Volume `portal-praktikum_portal-storage` dibuat oleh `docker compose run` di atas; cek dengan `docker volume ls`. Untuk mengecek arsip lewat `portal:backup-verify`, salin dulu ke `/opt/portal-backups` dengan pemilik UID 33.

## 5. Proxy Host di NPM

- **Details**: Domain `<domain>`, Scheme `http`, Forward Hostname `portal-praktikum`, Port `80`, *Block Common Exploits* aktif, Websockets tidak perlu.
- **SSL**: Let's Encrypt, *Force SSL*, *HTTP/2*. HSTS aktifkan setelah semuanya terbukti jalan.
- DNS: A record domain → IP VPS. Di Cloudflare set **DNS only** (awan abu-abu). Bila tetap di-proxy (oranye), semua praktikan tampak sebagai IP Cloudflare dan berbagi satu kuota batas laju.

## 6. Verifikasi

```bash
curl -f http://127.0.0.1:8095/up && curl -f http://127.0.0.1:8095/health/ready
curl -sI https://<domain>/login | grep -i set-cookie    # harus ada "secure"
docker compose logs --tail=3 nginx                      # IP di awal baris = IP NPM
```

Coba: login aslab, buka portal praktikum, unduh modul, kirim satu tugas uji berukuran ±10 MB (memastikan batas upload NPM tidak memotong), Cek Status. Setelah VPS terbukti, matikan ingress cloudflared di server lama dan hentikan stack lama (`docker compose down`, **tanpa** `-v`) agar tidak ada dua instalasi yang menerima data.

## Backup ke cloud

Aktifkan backup harian + berpemicu perubahan dan salinan Rclone ke cloud: [16_BACKUP_RCLONE.md](16_BACKUP_RCLONE.md).

## Kapasitas dan batas laju

Uji beban QA (4 core): 300 pengguna serentak, 0 gagal, ±200 req/detik, p95 ±1,5 detik. Bottleneck halaman biasa adalah CPU; worker FPM tambahan menjaga halaman tetap responsif saat ada upload/ekspor lambat.

Batas per menit (bisa diubah di `.env`, lalu `docker compose up -d app scheduler`):

| Variabel | Default | Keterangan |
|---|---|---|
| `PORTAL_SUBMIT_PER_IP` | 300 | kirim form/tugas, per IP publik |
| `PORTAL_SUBMIT_PER_IDENTITY` | 6 | per NBI/token, tetap ketat |
| `PORTAL_STATUS_PER_IP` | 120 | Cek Status |
| `PORTAL_DOWNLOAD_PER_IP` | 300 | unduh modul |

Satu lab biasanya keluar dari satu IP publik kampus, jadi batas per IP longgar dan perlindungan utama ada pada batas per identitas.
