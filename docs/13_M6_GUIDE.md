# Panduan M6 — Pengumuman, rekap, log, backup dan kunci semester

## Pengumuman

**Pengumuman** di sidebar (izin `announcements.manage`, standar untuk aslab, harus mencakup seluruh sesi praktikum).

- Tulis judul dan isi, pilih audiens **Publik** (beranda dan halaman `/pengumuman`) atau **Internal** (hanya dashboard pengelola). Admin dapat menandai **Umum** agar berlaku untuk semua praktikum.
- Isi memakai format sederhana: `**tebal**`, `*miring*`, daftar `- `, tautan `[teks](https://…)`. HTML mentah dan tautan berbahaya (`javascript:` dan sejenisnya) dibuang saat ditampilkan.
- *Simpan draf* tidak menampilkan apa pun ke publik. *Terbitkan* menampilkannya. *Arsipkan* (dengan alasan) menghilangkannya dari publik dan dashboard. Perubahan setelah dibuat wajib alasan; versi lama ditolak dengan 409.
- Pengumuman praktikum yang semesternya terkunci atau praktikumnya bukan *active* tidak tampil di portal publik.

## Rekap & Ekspor

**Rekap** (izin `reports.export`). Data dibatasi pada sesi yang diizinkan; filter sesi di luar lingkup ditolak 403.

| Rekap | Isi |
|---|---|
| Presensi | Praktikan × pertemuan dari snapshot dan rekap TTD, total Hadir/Izin/Sakit/Alpa/Belum dicatat. Filter sesi dan pertemuan. |
| Pengumpulan | Status penerimaan per tugas (cetak/digital), tanda terlambat. Filter sesi dan pertemuan asal. |
| Nilai | Praktikan final memakai **snapshot finalisasi** (nilai dan aturan saat itu); yang belum final ditandai. Kosong = belum diperiksa, bukan nol. Hasil remidi di kolom terpisah, tidak digabung. |
| Pengajuan | Semua pengajuan dengan status dan catatan keputusan. |

Ekspor CSV (UTF-8 dengan BOM) dan XLSX memakai data yang sama dengan tabel di halaman. Nilai yang diawali `= + - @` diberi awalan `'` agar spreadsheet tidak mengeksekusinya. Setiap ekspor tercatat di log. *Cetak* membuka versi A4 landscape untuk disimpan sebagai PDF dari browser.

## Log Aktivitas

- Admin: **Administrasi → Log Aktivitas**, seluruh log termasuk perubahan akun.
- Aslab: menu **Log Aktivitas** muncul bila admin memberi izin `logs.view` (default ditolak) dan aslab mencakup seluruh sesi praktikum itu. Hanya log praktikum tersebut yang terlihat.
- Filter pelaku, entitas, awalan aksi, sesi, praktikum dan rentang tanggal (WIB). Detail menampilkan sebelum/sesudah dengan field yang berubah ditandai, alasan, dan jumlah perubahan lain dalam batch yang sama.
- Nilai dengan kunci seperti `password`, `token`, `token_hash`, `secret`, `payload` selalu ditampilkan sebagai *disamarkan*.
- Log tidak memiliki route ubah atau hapus.

## Backup

Lihat docs/08_OPERATIONS.md bagian **Backup dan restore** untuk menyiapkan folder, enkripsi, jadwal, uji restore terisolasi dan restore produksi. Ringkasnya:

- **Pengaturan → Backup**: status berhasil terakhir, jadwal, retensi, tujuan, riwayat (ukuran, isi, SHA-256, error), tombol *Jalankan Backup* (diproses scheduler di latar belakang) dan pengaturan jam/retensi (admin).
- Berhasil hanya bila database dan seluruh berkas tersimpan dan arsip lolos verifikasi.
- Restore tidak tersedia sebagai tombol. `portal:backup-verify <arsip> --restore` menguji restore ke database terisolasi dan menolak database aplikasi.

## Kunci semester dan buka kembali

**Administrasi → Semester** (atau Pelaksanaan Praktikum) → *Kunci*. Isi alasan (minimal 10 karakter) dan konfirmasi.

- Semester atau praktikum terkunci menolak semua perubahan akademik (423): roster, presensi, pengumpulan, nilai, pengajuan, pengumuman. Portal publik tidak lagi menampilkan praktikum tersebut.
- *Buka kembali* hanya oleh admin dengan alasan; tercatat sebagai `master.reopened` di log. Praktikum tidak dapat dibuka selama semesternya masih terkunci.
- Status *Terkunci* tidak dapat dipilih dari form edit biasa.

## Buka koreksi nilai final

Pada **Penilaian → praktikan → Finalisasi**, admin melihat form *Buka koreksi* di bawah arsip final. Dengan alasan (minimal 10 karakter), arsip ditandai digantikan dan tetap tersimpan sebagai riwayat. Nilai dan pengumpulan praktikan dapat diubah lagi, lalu finalisasi ulang membuat **versi baru**. Database menjamin hanya satu arsip aktif per praktikan. Rekap nilai memakai versi aktif.

## Pengujian

- PHPUnit: `tests/Feature/OperationsTest.php` (11 tes). Tes restore membutuhkan `MYSQL_TEST_ROOT_PASSWORD` untuk membuat database `portal_restore_test` di container MySQL uji.
- Browser: `scripts/browser-m6-check.cjs` terhadap stack Docker (scheduler berjalan) dan `PublicPreviewSeeder` baru. `BACKUP_HOST_PATH` diarahkan ke folder uji yang dapat ditulis UID 33.

## Pengumpulan per pertemuan dan Laporan Akhir

- **Pengumpulan** membuka tab **Cetak/Digital**. Pilih pertemuan dan sesi untuk melihat semua tugas yang dikumpulkan pada pertemuan itu menurut jadwal pengumpulan masing-masing sesi (misalnya Pendahuluan 2 dan Aktivitas 1 pada Pertemuan 2), lengkap dengan tanggal terima, status dan nilai. Klik nama untuk mencatat penerimaan. Pengaturan tugas dan jadwal ada di **Kelola tugas & jadwal**.
- **Laporan Akhir** menampilkan penerimaan dan progres checklist setiap praktikan.
- Semua daftar menyediakan pilihan 10/25/50/100 baris per halaman.
