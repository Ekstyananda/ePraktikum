# Portal Praktikum — paket handoff Codex
Paket dokumentasi dan aplikasi Laravel yang kini mengimplementasikan M0–M6. Bahasa antarmuka Indonesia. Fokus awal SBD Untag Surabaya.

## Cara menggunakan
1. Ekstrak seluruh ZIP ke workspace Codex.
2. Baca CODEX_PROMPT.md lalu docs/01_SCOPE.md sampai docs/07_ACCEPTANCE.md.
3. Baca design/INDEX.md dan spesifikasi setiap halaman sebelum menggunakan PNG.
4. Kerjakan milestone secara berurutan; laporkan hasil verifikasi dan fitur yang belum selesai.

## Prioritas sumber
Keputusan tertulis dalam docs dan spesifikasi halaman mengalahkan mockup raster. PNG adalah referensi visual, bukan spesifikasi data. Desain pertama mengatur layout umum; tabel mengikuti aturan terbaru. Logo asli tersedia di assets/logo-prodi.png.

## Batas
Modul, daftar mahasiswa SBD, jadwal aktual, bobot nilai, aturan remidi dan deadline resmi belum tersedia. Semua contoh harus diberi label data contoh. Jangan mengimpor data mahasiswa MJK sebagai data produksi SBD. Jangan memakai bobot atau syllabus yang diciptakan mockup.
Tidak ada password produksi di paket ini. MySQL eksternal sudah ada; satu database Lab masih kosong. Jangan membangun container MySQL baru.

## Pembaruan
Dosen pembimbing opsional saat impor dan bisa ditetapkan massal untuk beberapa praktikan. Rancangan database menggunakan master supervisors dan FK nullable, menggantikan field nama bebas baseline.

---

# Implementasi aplikasi M0–M6

Aplikasi Laravel 13 + MySQL, Blade dan Bootstrap lokal telah ditambahkan. Cakupan aktual dan bukti verifikasi ada di [BUILD_STATUS.md](BUILD_STATUS.md). Dokumen handoff di atas tetap menjadi acuan fitur berikutnya.

## Menjalankan pengujian terisolasi

Prasyarat: PHP 8.4 dengan pdo_mysql, mbstring, XML, intl, zip; Composer 2; Docker dan Compose. Tidak membutuhkan Node untuk aplikasi.

```bash
composer install
php scripts/init-test-env.php
docker compose --env-file .env.testing -f compose.test.yaml up -d --wait
vendor/bin/phpunit
```

Skrip pertama menolak menimpa `.env.testing` yang sudah ada. Database pengujian hanya `portal_test`, di jaringan `portal-praktikum-isolated-test`, port loopback 13379, storage tmpfs. Tes menolak host/database lain sebelum migrasi. Tes menggunakan MySQL nyata; tidak menggunakan SQLite.

## Menjalankan aplikasi dengan MySQL eksternal

Lakukan konfigurasi berikut pada server sendiri. Tidak ada koneksi produksi yang dilakukan dari workspace ini.

```bash
cp .env.example .env
# Isi APP_URL, kredensial akun DB khusus Lab, dan MYSQL_EXTERNAL_NETWORK.
docker compose build app nginx
# Hasil key hanya untuk .env server, simpan APP_KEY=base64:... secara pribadi.
docker compose run --rm --no-deps app php artisan key:generate --show
docker compose run --rm --no-deps app php artisan migrate --force
docker compose run --rm --no-deps app php artisan portal:admin admin@domain-anda --name="Administrator"
docker compose up -d --wait
```

Bootstrap meminta kata sandi tersembunyi dua kali, minimal 12 karakter dengan huruf besar/kecil dan angka. Tidak ada akun/kata sandi default, registrasi publik, maupun login praktikan. Buka APP_URL (default http://localhost:8095), lalu Login Aslab. Administrator membuka **Pengaturan Aslab → klik nama → Profil / Praktikum / Sesi / Hak Akses → Simpan**.

Compose utama berisi PHP-FPM, Nginx dan satu scheduler. Worker opsional diaktifkan dengan `docker compose --profile jobs up -d`; M2 menjalankan pembersihan staging pratinjau kedaluwarsa tiap jam; belum memiliki job akademik/backup. Compose utama **tidak berisi MySQL**. Jaringan DB harus sudah tersedia. Detail jaringan, HTTPS/proxy, instalasi, update, persistensi, backup/restore manual dan preview: [docs/08_OPERATIONS.md](docs/08_OPERATIONS.md).

Data produksi dimulai kosong. Admin dapat membuat semester, master praktikum, pelaksanaan praktikum dan sesi melalui UI, lalu menugaskan aslab. Praktikan tetap tanpa login. Pengelola berizin dapat menambah/edit praktikan, mengimpor CSV/XLSX melalui pemetaan dan pratinjau, serta mengekspor master. Dosen pembimbing opsional; penetapan massal memakai daftar sasaran dan konfirmasi, dengan default hanya mengisi dosen yang kosong.

Panduan alur, batas impor dan aturan penetapan dosen: [docs/09_M2_GUIDE.md](docs/09_M2_GUIDE.md). Untuk mencoba, gunakan **PreviewSeeder khusus portal_test** sesuai panduan operasi.


## Alur M3

Buka offering → **Pertemuan, Modul & Presensi**. Siapkan jumlah pertemuan (default 5, editable), jadwalkan per sesi, lalu bekukan snapshot peserta sebelum presensi/cetak. Presensi awal Belum dicatat; simpan hanya baris dipilih. Koreksi membutuhkan alasan dan versi terbaru. Scan TTD privat dan opsional. Modul diunggah sebagai draf; setelah Terbitkan tersedia tanpa login pada `/modul`.

Panduan izin, snapshot, cetak A4, batas berkas dan preview: [docs/10_M3_GUIDE.md](docs/10_M3_GUIDE.md). Semua hasil pemeriksaan dan keterbatasan ada di BUILD_STATUS.md.

## Alur M4

Buka offering → **Pengumpulan & Tugas** untuk tugas dan jadwal penerimaan per sesi. Catat penerimaan cetak tanpa file atau digital dengan versi revisi privat. Laporan akhir mempunyai checklist per praktikan. **Penilaian** mendukung nilai lab langsung; **Komponen Nilai / Aturan Nilai** hanya untuk izin khusus. Bobot dan ketentuan yang belum resmi tetap draf, dan finalisasi ditolak sampai seluruh guard terpenuhi.

Panduan penerimaan, revisi, otorisasi, aturan dan arsip final: [docs/11_M4_GUIDE.md](docs/11_M4_GUIDE.md).

## Alur M5

Portal publik tanpa login: **Jadwal**, **Pengumpulan** tugas digital, **Pengajuan** (izin, pindah satu pertemuan/permanen, susulan), **Remidi** dan **Cek Status** dengan token rahasia yang ditampilkan sekali. Hanya praktikum berstatus *active* yang tampil di portal.

Pengelola membuka **Pengajuan** untuk memutuskan dengan alasan. Kapasitas sesi tujuan dikunci saat persetujuan, persetujuan tidak otomatis mengubah presensi atau nilai, dan hasil susulan/remidi disimpan sebagai versi baru tanpa menimpa data asli. Periode form dan program remidi diatur di **Pengajuan → Periode & program remidi**. File dari portal diperiksa di **Pengumpulan → Kiriman digital**.

Panduan lengkap, batas laju dan migrasi: [docs/12_M5_GUIDE.md](docs/12_M5_GUIDE.md).

## Alur M6

- **Pengumuman**: tulis, terbitkan (publik atau internal), arsipkan. Format sederhana yang disanitasi. Tampil di beranda dan `/pengumuman`.
- **Rekap**: presensi, pengumpulan, nilai (dari snapshot finalisasi) dan pengajuan; filter sesi/pertemuan; ekspor CSV/XLSX aman formula; cetak A4.
- **Log Aktivitas**: read-only, filter, detail sebelum/sesudah, rahasia disamarkan. Admin semua; aslab dengan izin `logs.view`.
- **Backup**: database + berkas privat dalam satu arsip ber-checksum (opsional AES-256), manual atau terjadwal lewat scheduler, retensi, uji restore terisolasi. Siapkan folder host milik UID 33 dan `BACKUP_HOST_PATH` sebelum dipakai.
- **Kunci semester / buka kembali** dan **buka koreksi nilai final** oleh admin dengan alasan yang tercatat.

Panduan: [docs/13_M6_GUIDE.md](docs/13_M6_GUIDE.md). Prosedur backup/restore: [docs/08_OPERATIONS.md](docs/08_OPERATIONS.md).

## Portal multi-praktikum

- `/` menampilkan **Pilih praktikum**; tiap praktikum punya portal sendiri di `/{slug}` (misalnya `/sbd`) dengan nama, warna, sampul dan layanan sendiri. Praktikan tetap tanpa akun.
- **Tampilan Portal** (admin, atau aslab dengan izin `portal.manage` seluruh sesi): identitas, palet warna, sampul (disimpan ulang sebagai WebP), layanan aktif, link + QR untuk dibagikan. Slug hanya diubah admin dengan alasan; slug lama tetap dialihkan.
- Data tiap portal terisolasi: modul, jadwal, pengumuman dan form hanya untuk pelaksanaan praktikum itu.
- Konfirmasi tiga tingkat: input pertama cukup Simpan, perubahan yang terlihat praktikan memakai centang, koreksi krusial wajib alasan.

Panduan: [docs/14_MULTI_PRAKTIKUM_GUIDE.md](docs/14_MULTI_PRAKTIKUM_GUIDE.md).
