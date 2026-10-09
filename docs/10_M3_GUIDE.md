# Penggunaan M3 — Pertemuan, modul, presensi dan cetak TTD

## Persiapan dan izin

Buat roster/sesi melalui M2 dahulu, kemudian buka offering → **Pertemuan, Modul & Presensi**. Tidak ada akun praktikan. Jadwal asli, materi dan syllabus belum diberikan; produksi tidak diisi contoh.

Pengelola yang memiliki materials.manage untuk seluruh sesi offering dapat membuat/edit pertemuan bersama dan mengunggah/terbitkan/tarik modul. Ketentuan seluruh sesi diperlukan karena pertemuan/modul berlaku bersama satu offering. Aslab yang dibatasi sesi dapat membaca materi bersama dan mengatur jadwal pelaksanaan sesinya jika materials.manage diberikan, tetapi tidak mengubah materi untuk sesi lain. attendance.manage diperiksa per sesi untuk presensi, snapshot, cetak dan scan. reports.export tidak menggantikan izin presensi. Izin dibaca terbaru, tidak hanya disembunyikan pada UI.

## Pertemuan dan jadwal pelaksanaan

1. Pada offering kosong, jumlah awal default 5 dapat disesuaikan (1–100), konfirmasi lalu **Buat pertemuan awal**. Ini hanya membuat nomor/judul generik, tidak membuat tanggal atau materi fiktif. Bisa juga **Tambah pertemuan** satu per satu.
2. **Edit pertemuan** untuk nomor/judul sesuai instruksi resmi. Edit membutuhkan alasan dan version. Snapshot/cetakan yang sudah dibuat mempertahankan judul lama.
3. **Jadwalkan per sesi**: pilih pertemuan dan sesi berizin, tanggal/jam mulai–selesai WIB dan ruangan. Waktu disimpan UTC; pasangan sesi/pertemuan unik dan interval pada sesi yang sama tidak boleh tumpang tindih.
4. Jadwal dapat diedit dengan alasan/version sebelum snapshot dibuat. Identitas pasangan sesi/pertemuan tidak dipindah lewat edit. Setelah snapshot, jadwal tidak dapat diubah dari UI/API M3.

Pertemuan tidak dihapus melalui UI M3 agar tidak membuang arsip/materi. Jadwal pengumpulan dan jenis tugas ada pada M4, terpisah dari pertemuan asal; belum diimplementasikan pada M3.

## Snapshot peserta

Buka **Presensi · nama sesi**, periksa roster dan tanggal pelaksanaan, konfirmasi lalu **Bekukan peserta**. GET presensi/cetak tidak membuat snapshot diam-diam; cetak sebelum snapshot mengarahkan ke langkah persiapan. Snapshot mencakup enrollment aktif pada saat pembekuan yang membership-nya berlaku pada tanggal pelaksanaan WIB: valid_from inklusif, valid_until eksklusif.

NBI, nama, kelas SIM, kategori kelas, label sesi, urutan cetak dan metadata praktikum/semester/pertemuan/tanggal/jam/ruangan disimpan. Urutan awal berdasarkan NBI teks; nomor tidak bergantung paritas NBI. Setelah dibekukan, pindah sesi, rename master atau enrollment baru tidak mengubah arsip. Snapshot tidak dapat di-refresh/delete melalui UI. Semua presensi awal **Belum dicatat**, bukan Alpa. Snapshot kosong ditolak agar operator memeriksa roster. Satu enrollment tidak dapat muncul dua kali pada pertemuan logis yang sama di dua sesi; database dan backend menjaga constraint offering.

Batas M3: status active enrollment diperiksa saat pembekuan, belum mempunyai histori status bertanggal. Untuk jadwal lama, periksa roster sebelum membekukan. Transfer sementara/approval per pertemuan dan susulan merupakan M5. Perbaikan snapshot peserta yang sudah dibekukan membutuhkan alur audit khusus berikutnya; jangan mengubah tabel langsung untuk kegiatan operasional.

## Rekap presensi dan koreksi

1. Pilih pelaksanaan melalui daftar pertemuan; cari NBI/nama snapshot atau filter status. Pagination dan filter dijalankan server.
2. Centang baris yang akan disimpan. **Pilih halaman presensi** hanya mencentang halaman aktif, maksimum 100 per request. Pilihan tidak melebar ke halaman/filter lain.
3. Pilih status Hadir/Izin/Sakit/Alpa/Belum dicatat dan catatan per baris. **Simpan pilihan** menyimpan status/catatan baris terpilih. **Hadir untuk pilihan** menetapkan Hadir hanya pada pilihan, dengan catatan per baris yang ada di form. Baris tidak dicentang tetap utuh sekalipun input statusnya diubah.
4. Konfirmasi rekap sesuai TTD fisik. Input awal tidak memerlukan alasan. Setiap perubahan setelah data tercatat, termasuk catatan atau pengembalian ke Belum dicatat, wajib alasan minimal 5 karakter.
5. Seluruh batch satu transaksi. Backend memeriksa setiap participant/session/version. Target tidak berizin, payload invalid, satu versi stale atau audit gagal menggagalkan seluruh batch. Konflik 409 terlihat; muat ulang lalu periksa perubahan pengelola lain.

Pada HP gunakan **Edit presensi** di bawah nama praktikan untuk membuka status, catatan dan alasan dalam form penuh. Penyimpanan tetap memakai endpoint scoped/versioned yang sama, hanya satu participant; praktikan lain tidak berubah.

Audit menyimpan old/new, actor, waktu UTC, alasan dan batch UUID. Snapshot tetap utuh. Tidak ada otomatis Hadir dari izin/susulan, atau Alpa dari blank. Data no-op dilewati. Log viewer immutable UI masih M6; M3 menulis audit atomik.

## Cetak TTD A4

Klik **Cetak TTD A4**, lalu **Cetak / Simpan PDF** pada halaman khusus tanpa sidebar. Pilih A4 portrait, skala 100%, nonaktifkan header/footer browser. CSS menggunakan margin 12 mm, hitam putih, header tabel berulang, baris tidak terpecah antarlaman. Nomor tetap berlanjut antarlaman.

Kolom No 6%, NBI 20%, Nama 39%, Sesi 10%, TTD 25%. Satu kolom TTD tanpa garis pembagi tengah. Nomor urut ganjil berada kiri sel TTD, genap sekitar tengah-kanan pada barisnya. Nama lebih lebar daripada TTD. Label sesi/nama panjang dapat membungkus sehingga baris bertambah tinggi dan tetap menyediakan ruang tanda tangan. Cetak memakai snapshot, bukan roster terbaru. Pengujian PDF aktual dilakukan dengan 67 peserta, multi-halaman; bukti di artifacts/.

Tidak ada generator PDF server baru. HTML print khusus dapat disimpan sebagai PDF dari dialog browser. Pemilihan ukuran printer dan header/footer tetap dilakukan operator.

## Scan privat dan modul publik

Scan opsional PDF/JPG/JPEG/PNG. Konfirmasi pelaksanaan, unggah dan tambahkan catatan bila perlu. Scan berikutnya disimpan sebagai dokumen tambahan; file lama tidak ditimpa/dihapus. Unduhan scan memerlukan login aktif dan attendance.manage pada sesi; tidak tersedia di /storage atau route publik. Mengetahui ID dokumen tidak memberi akses. Berkas disimpan di disk local storage/app/private/academic dengan nama UUID tanpa extension, metadata/checksum SHA256 dan uploader. Nginx tidak menjalankan atau melayani direktori ini.

Modul/soal menerima PDF/DOCX/ZIP/TXT/SQL sebagai unduhan, tidak mengeksekusi SQL atau mengekstrak ZIP. Unggah **draf** dahulu; draf hanya dapat diunduh pengelola berizin. **Terbitkan** membutuhkan alasan, konfirmasi dan version. Setelah terbit, /modul dan unduhannya dapat digunakan publik tanpa login; tidak memuat roster, presensi atau scan. **Tarik publikasi** memblokir route unduhan publik, file lama tetap utuh. Berkas yang sudah diunduh pihak lain tidak dapat ditarik kembali. Untuk revisi unggah draf baru dan tarik materi lama bila perlu; file lama tidak ditimpa. Publikasi langsung, belum penjadwalan publikasi masa depan. Fitur publik M5 lainnya belum selesai.

Batas awal berkas M3 10 MB; ACADEMIC_UPLOAD_MAX_KB dapat diturunkan (maksimum 10240 KB). Runtime PHP upload 11 MB, post 12 MB, Nginx 16 MB. Impor M2 tetap 5 MB. MIME, extension, ukuran dan izin diperiksa backend; file PHP/HTML/SVG tidak diterima. Berkas divalidasi/disimpan privat dan dibersihkan jika transaksi DB gagal. Unduhan attachment memakai nosniff dan no-store. Belum tersedia antivirus/CDR; format unduhan tidak dirender inline oleh aplikasi.

Semester/offering locked menolak semua write M3 termasuk upload; baca/cetak tetap mengikuti izin. UI locking/admin reopen tetap M6. Penyimpanan memakai volume persisten dan nonroot UID 33. Backup operator perlu mencakup DB serta storage, mengikuti docs/08_OPERATIONS.md.

## Verifikasi

Jalankan lingkungan MySQL terisolasi dan `vendor/bin/phpunit`. MeetingTest menguji scope, snapshot, conflict/rollback, locked semester, upload private dan module publication. CI memeriksa seluruh compiled Blade dengan php -l.

Preview M3 memakai **MeetingPreviewSeeder**, hanya local/testing + portal_test, dengan PORTAL_DEMO_PASSWORD acak. Seeder juga menyiapkan PreviewSeeder dan 67 praktikan berlabel Data contoh; menolak jika DEMO-M3 sudah ada agar tidak mengubah snapshot contoh. Jalankan PHPUnit sebelum seed/browser karena tes mengatur ulang database uji.

`scripts/browser-m3-check.cjs` menggunakan Playwright 1.58.2, PORTAL_BASE_URL/PORTAL_DEMO_PASSWORD dan artifact mount /artifacts. Browser membuat 5 pertemuan dan jadwal, membekukan snapshot, menguji presensi/koreksi, dua request admin/aslab bersamaan, scan privat, publikasi/penarikan SQL dan mobile. `scripts/verify-m3-pdf.py` memakai PyMuPDF 1.26.4 hanya untuk QA, bukan dependency runtime; memeriksa PDF browser sebenarnya, A4 setiap halaman, header berulang, NBI/nama/TTD pada halaman sama dan parity nomor.

Referensi penyimpanan/unduhan resmi: [Laravel 13 Filesystem](https://laravel.com/framework/docs/filesystem). Ini dipakai bersama source framework yang terpasang, tidak menggunakan URL publik disk untuk file privat.

M5–M6 belum selesai: pengajuan/susulan/remidi, form publik lain, pengumuman, reports lengkap, log viewer dan backup UI/otomatis. Rincian hasil aktual ada di BUILD_STATUS.md.

Pengumpulan dan penilaian M4 kini tersedia; lihat [panduan M4](11_M4_GUIDE.md).
