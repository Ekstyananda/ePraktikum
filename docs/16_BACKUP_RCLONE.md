# Backup otomatis ke cloud dengan Rclone

Alur: aplikasi membuat arsip di folder host (`BACKUP_HOST_PATH`) → skrip Rclone di **host VPS** menyalin arsip baru ke cloud (Google Drive, OneDrive, S3, B2, dst.).

Rclone sengaja tidak dipasang di container. Kredensial cloud tetap di host, dan aplikasi web tidak pernah bisa membaca atau menghapus salinan di cloud. Kalau aplikasi diretas, backup di cloud tetap aman.

## 1. Kapan backup dibuat

Isi arsip selalu sama: dump database `Lab`, seluruh berkas privat (kiriman tugas, modul, scan TTD, sampul) dan `manifest.json` ber-checksum. Format dan uji restore ada di [08_OPERATIONS.md](08_OPERATIONS.md#backup-dan-restore-m6).

| Pemicu | Kapan | Pengaturan |
|---|---|---|
| Harian | Sekali sehari setelah jam yang diatur | `BACKUP_ENABLED=true`; jam dan retensi di **Pengaturan → Backup** |
| **Perubahan data** | Setelah ≥ N perubahan sejak backup berhasil terakhir, dengan jeda minimal M menit dari run sebelumnya | `BACKUP_CHANGE_THRESHOLD=10`, `BACKUP_CHANGE_MIN_INTERVAL=60` |
| Manual | Tombol *Jalankan Backup* atau `php artisan portal:backup --now` | — |

### Cara kerja pemicu perubahan

- Yang dihitung adalah baris baru di **Log Aktivitas** sejak backup berhasil terakhir dimulai. Semua tindakan yang mengubah data sudah tercatat di sana:
  - kiriman tugas dan revisi dari portal
  - pengajuan izin/pindah/susulan/remidi
  - keputusan aslab
  - presensi, nilai, praktikan, modul, pengumuman, pengaturan

  Catatan milik backup sendiri tidak dihitung. Halaman yang hanya dibuka atau Cek Status tidak menambah hitungan.
- Satu perubahan = satu baris log. Simpan presensi 40 praktikan menghasilkan 40 baris, jadi langsung melewati ambang.
- Scheduler mengecek tiap menit. Bila ambang tercapai, tidak ada backup yang sedang antre/berjalan, dan jeda minimal sudah lewat, backup berpemicu **Perubahan data** diantrekan.
- **Jeda minimal penting saat pengumpulan ramai.** Misalnya 300 praktikan mengumpulkan dalam 2 jam: dengan jeda 60 menit hanya ada ±2–3 backup, bukan 30.
- Kurang dari 10 perubahan tetap aman: backup harian menangkapnya.
- Halaman Backup menampilkan pengaturan ini beserta jumlah perubahan yang belum dibackup.

### Ukuran arsip dan retensi

Setiap arsip berisi **semua** berkas privat, bukan hanya yang berubah. Menjelang akhir semester, kiriman tugas bisa membuat arsip mencapai beberapa GB.

- Cek ukuran di kolom *Ukuran* halaman Backup. Bila sudah lebih dari ±1 GB, naikkan `BACKUP_CHANGE_MIN_INTERVAL` ke 180–360, atau matikan pemicu perubahan (`0`) dan andalkan backup harian.
- Retensi lokal (Pengaturan → Backup) menghitung semua arsip, termasuk yang berpemicu perubahan. Dengan 7 arsip, hari sibuk bisa menghapus arsip harian kemarin dari disk lokal. Salinan di cloud tidak ikut terhapus (lihat langkah 4). Atur retensi lokal sesuai sisa disk: `df -h /opt/portal-backups`.

### Aktifkan

Di `.env` VPS:

```bash
BACKUP_ENABLED=true
BACKUP_HOST_PATH=/opt/portal-backups
BACKUP_PASSWORD=<rahasia-panjang>     # wajib: arsip akan keluar dari VPS
BACKUP_CHANGE_THRESHOLD=10
BACKUP_CHANGE_MIN_INTERVAL=60
```

Lalu terapkan:

```bash
docker compose up -d app scheduler
```

Simpan `BACKUP_PASSWORD` di password manager, di luar VPS. Tanpa password itu, arsip di cloud tidak bisa dibuka.

## 2. Pasang dan sambungkan Rclone (host VPS)

```bash
sudo -v && curl -fsSL https://rclone.org/install.sh | sudo bash
sudo rclone config
```

Di `rclone config`, buat remote bernama `portal-cloud`, misalnya tipe `drive` (Google Drive):

- Untuk Google Drive, pilih scope `drive.file`, supaya Rclone hanya bisa mengakses berkas yang dibuatnya sendiri.
- VPS tanpa browser: jawab `n` pada *auto config*, lalu jalankan `rclone authorize "drive"` di laptop dan tempel token yang keluar.

Konfigurasi tersimpan di `/root/.config/rclone/rclone.conf`. Amankan file itu:

```bash
sudo chmod 600 /root/.config/rclone/rclone.conf
```

Cek sambungan:

```bash
sudo rclone mkdir portal-cloud:portal-praktikum
sudo rclone lsd portal-cloud:
```

Opsional: tambahkan remote tipe `crypt` di atas `portal-cloud:` supaya nama berkas juga tersamar. Isi arsip sudah terenkripsi AES-256 oleh aplikasi.

## 3. Skrip sinkronisasi

Simpan skrip berikut sebagai `/usr/local/bin/portal-rclone`:

```bash
#!/bin/sh
# Copy finished Portal Praktikum archives to the cloud; never deletes the local copies.
set -eu
SRC=/opt/portal-backups
DST=portal-cloud:portal-praktikum
KEEP_DAYS=90
LOG=/var/log/portal-rclone.log

exec 9>/run/lock/portal-rclone.lock
flock -n 9 || exit 0

# Finished archives only (.partial-/.work- excluded); --min-age waits for the .sha256 file to be written.
rclone copy "$SRC" "$DST" --include 'portal-backup-*.zip' --include 'portal-backup-*.zip.sha256' \
  --min-age 2m --immutable --log-file "$LOG" --log-level INFO

# Cloud retention, only when a recent upload exists, so a stalled backup never empties the cloud.
if [ -n "$(rclone lsf "$DST" --include 'portal-backup-*.zip' --max-age 3d)" ]; then
  rclone delete "$DST" --include 'portal-backup-*' --min-age "${KEEP_DAYS}d" --log-file "$LOG" --log-level INFO
fi
```

Pasang dan uji:

```bash
sudo chmod 700 /usr/local/bin/portal-rclone
sudo /usr/local/bin/portal-rclone && sudo tail -n 20 /var/log/portal-rclone.log
```

Skrip ini sengaja memakai `copy`, bukan `sync`. Retensi lokal yang menghapus arsip lama tidak ikut menghapus salinan di cloud; salinan cloud baru dihapus setelah `KEEP_DAYS` hari.

## 4. Jalankan otomatis

Cron root tiap 5 menit. `rclone copy` hanya mengunggah berkas baru, jadi run yang tidak menemukan arsip baru hampir tanpa biaya. Unggahan terjadi paling lambat ±7 menit setelah backup selesai.

```bash
echo '*/5 * * * * root /usr/local/bin/portal-rclone' | sudo tee /etc/cron.d/portal-rclone
```

Rotasi log:

```bash
printf '/var/log/portal-rclone.log {\n weekly\n rotate 8\n compress\n missingok\n}\n' | sudo tee /etc/logrotate.d/portal-rclone
```

## 5. Pemeriksaan rutin

```bash
sudo rclone lsl portal-cloud:portal-praktikum | sort -k2,3 | tail -5   # arsip terbaru di cloud
grep -c ERROR /var/log/portal-rclone.log
```

Bandingkan arsip terbaru di cloud dengan baris *Berhasil* terakhir di halaman Backup. Bila sempat memakai Uptime Kuma, buat monitor *Push* dan tambahkan `curl -fsS <push-url>` di akhir skrip, supaya ada peringatan saat sinkronisasi berhenti.

Sebulan sekali, lakukan uji restore dari cloud ke database terisolasi:

```bash
sudo rclone copy portal-cloud:portal-praktikum/portal-backup-YYYYMMDD-HHMMSS-N.zip /opt/portal-backups/ \
  && sudo chown 33:33 /opt/portal-backups/portal-backup-YYYYMMDD-HHMMSS-N.zip
docker compose exec app php artisan portal:backup-verify portal-backup-YYYYMMDD-HHMMSS-N.zip --restore
```

`--restore` membutuhkan `RESTORE_DB_*` (lihat docs/08). Hapus arsip salinan itu dari `/opt/portal-backups` setelah uji, karena retensi aplikasi hanya menghapus arsip yang dibuatnya sendiri. Restore produksi dari cloud mengikuti [15_VPS_NPM.md](15_VPS_NPM.md) langkah 4 atau docs/08 bagian *Restore produksi*.
