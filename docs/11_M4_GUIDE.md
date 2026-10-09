# M4 — pengumpulan dan penilaian

M4 menyediakan pengelolaan tugas, penerimaan cetak/digital oleh pengelola, nilai praktik langsung, aturan nilai berversi dan finalisasi per praktikan. Praktikan tetap **tanpa akun**. Form pengumpulan publik dengan secret token adalah pekerjaan M5.

## Tugas dan jadwal pengumpulan

Dari offering buka **Pengumpulan & Tugas**. Buat jenis Pendahuluan, Aktivitas, Praktik Lab, Laporan akhir atau Lainnya. Pendahuluan/Aktivitas/Lab mempunyai pertemuan asal. Praktik Lab memakai pemeriksaan langsung tanpa submission; laporan akhir memakai penerimaan cetak. Tidak ada tugas atau bobot resmi yang dibuat otomatis.

Buka **Jadwal pengumpulan** untuk menetapkan sesi, pelaksanaan pengumpulan, waktu buka/tenggat/tutup WIB dan izin terlambat. Pertemuan asal terpisah dari pelaksanaan pengumpulan: Aktivitas 1 dapat diterima pada pertemuan 2. Waktu tersimpan UTC. Jadwal yang sudah mempunyai penerimaan tidak dapat diubah; histori keterlambatan tetap konsisten.

Konfigurasi tugas bersama memerlukan `submissions.manage` untuk semua sesi offering. Aslab terbatas sesi dapat menangani jadwal/penerimaan sesi yang diizinkan. Data dari offering lain ditolak backend. Setelah transfer praktikan, penerimaan lama memerlukan izin sesi sekarang **dan** sesi jadwal arsip, termasuk pada daftar dan unduhan.

## Penerimaan dan revisi

Klik nama praktikan pada penerimaan tugas. Masukkan waktu terima **sebenarnya**, status, catatan dan konfirmasi. Waktu input dan pengelola dicatat terpisah; terlambat dihitung dari waktu terima, bukan waktu input. Waktu masa depan/di luar periode atau keterlambatan yang tidak diizinkan ditolak.

- Cetak: Diterima → Perlu revisi → Revisi diterima → Selesai. Attachment tidak diperlukan dan ditolak untuk mode cetak.
- Digital: unggahan pertama berstatus Dikirim. Minta revisi sebelum menerima file baru. Setiap file mempunyai versi, waktu terima, waktu input, pengelola dan checksum; versi lama tetap dapat diunduh pengelola berizin.
- Koreksi wajib alasan dan versi terbaru. Keputusan eksplisit Tidak dikumpulkan juga wajib alasan, tanpa file/waktu penerimaan; keputusan ini tidak otomatis memberi nilai nol.
- Riwayat penerimaan menampilkan perubahan status/waktu/pengelola/alasan. Keterlambatan yang membutuhkan review harus diputuskan sebelum finalisasi.

PDF/DOCX/ZIP/TXT/SQL maksimal 10 MB (dapat diturunkan lewat konfigurasi). Berkas privat, diunduh sebagai attachment melalui otorisasi backend, tanpa URL storage publik. SQL tidak dieksekusi. Belum ada antivirus/CDR. File staged dibersihkan ketika transaksi gagal.

## Laporan akhir

Setiap tugas laporan akhir membuat 16 item: Cover; Pendahuluan 1–5; Daftar Pustaka untuk masing-masing Pendahuluan; Aktivitas 1–5. Checklist disimpan **per laporan praktikan**, dengan alasan dan konfirmasi. Seluruh item harus lengkap sebelum status Selesai. Untuk mengoreksi laporan Selesai, minta revisi dahulu.

Checklist bukan nilai dan tidak membuat komponen berbobot. Aktivitas 5 dan laporan akhir tidak otomatis dihitung dua kali. Bila keduanya diberi bobot positif terpisah, aturan membutuhkan konfirmasi ketentuan resmi.

## Komponen, nilai, aturan dan finalisasi

1. Pengelola berizin `grading_rules.manage` membuka Komponen Nilai. Kaitkan komponen dengan tugas, isi maksimum skor dan status wajib/aktif. Bobot boleh kosong sebagai draf. Satu komponen per tugas. Izin aturan ini **nonaktif secara default** untuk aslab.
2. Pengelola `grades.manage` membuka Penilaian → nama praktikan. Pilih komponen yang akan disimpan. Belum dinilai berarti NULL, bukan nol. Nilai dinilai harus dalam rentang maksimum; keputusan nol untuk kewajiban tidak terpenuhi harus eksplisit dan beralasan. Koreksi nilai tersimpan memerlukan alasan. Praktik Lab dinilai langsung tanpa unggahan/penerimaan.
3. Buat versi draf aturan dengan sumber/keterangan resmi, ambang lulus, pembulatan, presisi, batas huruf dan kebijakan terlambat. Tidak ada bobot/ambang bawaan dari mockup. Terbitkan hanya setelah bobot aktif tepat 100%, semua ketentuan lengkap dan batas huruf mencakup 0. Perubahan komponen membuat aturan lama tidak valid untuk finalisasi berikutnya; buat versi baru.
4. Buka pratinjau final per praktikan. Sistem memeriksa aturan terbit, nilai wajib/berbobot, tugas wajib, keputusan keterlambatan dan checklist laporan. Pratinjau berubah sejak dibuka menghasilkan konflik 409, bukan finalisasi data yang belum ditinjau.
5. Konfirmasi finalisasi menyimpan identitas, aturan, rincian nilai/kewajiban dan hasil sebagai arsip. Nilai/penerimaan/checklist praktikan tersebut terkunci. Adanya satu hasil final juga mengunci konfigurasi bersama tugas/jadwal/komponen/aturan offering agar arsip tetap konsisten. Praktikan lain masih dapat dinilai dengan konfigurasi yang sama.

Semester/offering locked menolak penulisan. Pembukaan ulang final/semester, remidi dan riwayat nilai remidi belum tersedia (M5–M6). Penguncian hasil M4 khusus penilaian/pengumpulan; bukan pengganti penguncian seluruh semester.

## Pengujian dan contoh lokal

Ikuti README untuk membuat MySQL terisolasi dan menjalankan PHPUnit. `AssessmentPreviewSeeder` hanya menerima lingkungan local/testing dan database `portal_test`; menyiapkan offering M4, tiga praktikan dan jadwal berlabel Data contoh. Seeder memanggil PreviewSeeder sehingga membutuhkan `PORTAL_DEMO_PASSWORD` acak dari operator, dan menolak offering contoh M4 yang sudah ada.

```bash
# Hanya setelah konfigurasi pengujian terisolasi; jangan pada Lab.
php artisan db:seed --class=AssessmentPreviewSeeder --force
```

`scripts/browser-m4-check.cjs` memakai Playwright, `PORTAL_BASE_URL`, `PORTAL_DEMO_PASSWORD`, serta direktori `/artifacts` yang dapat ditulis. Jalankan hanya terhadap data seeder yang baru: skrip membuat tugas, aturan QA, nilai dan arsip final. Aturan QA tersebut diberi label Data contoh, **bukan aturan resmi SBD**. Hasil aktual disimpan dalam artifacts/browser-m4-results.json. Tes PHPUnit menginisialisasi ulang database tes; jalankan sebelum seeding/browser.
