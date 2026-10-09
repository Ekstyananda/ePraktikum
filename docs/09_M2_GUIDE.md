# Penggunaan M2 — Master, praktikan dan dosen pembimbing

M2 tidak membuat akun praktikan. Semua halaman di bawah membutuhkan login pengelola dan permission backend. Produksi dimulai tanpa data contoh.

## Persiapan administrator

1. Pengaturan → Semester: tambah kode, label, tanggal mulai/selesai, status draf/aktif.
2. Master Praktikum: tambah kode/nama, misalnya nama praktikum resmi pengguna. Tidak ada syllabus atau bobot nilai yang diisi otomatis.
3. Pelaksanaan Praktikum: hubungkan praktikum dengan semester. Pasangan tidak boleh duplikat; identitas offering yang sudah dibuat tidak dipindahkan ke semester/praktikum lain.
4. Buka praktikum → Sesi & Jadwal: isi label, hari, jam WIB, ruangan dan kapasitas. Penanggung jawab opsional harus akun aktif yang berizin pada praktikum/sesi. Ini jadwal mingguan; tanggal pertemuan individual merupakan M3.
5. Pengaturan Aslab → klik nama → Praktikum/Sesi/Hak Akses: tetapkan offering. Default semua sesi termasuk sesi baru; ubah bobot/kelulusan dan backup tetap nonaktif pada preset standar.

Aslab berizin semua sesi dapat membuat sesi; aslab dibatasi sesi hanya dapat mengedit sesi yang ditugaskan. Semester/offering berstatus locked menolak perubahan M2. UI lock/reopen dengan alasan belum dibangun (M6).

Master/sesi menggunakan version dan audit. Data yang sudah digunakan tidak bisa dihapus; foreign key melindungi histori. Penghapusan data yang belum digunakan membutuhkan alasan dan konfirmasi. Tidak ada cascade penghapusan hasil akademik.

## Data praktikan

Buka offering → Data Praktikan. Kolom: No otomatis, NBI teks, Nama, Simpraktikum, Kelas, Sesi, Dosen Pembimbing. Search, filter sesi/kelas/dosen/status, jumlah per halaman dan pagination dijalankan di server; hanya enrollment dalam scope sesi berizin yang dikembalikan.

Tambah praktikan tidak mewajibkan dosen. Kosong ditampilkan **Belum ditentukan**. NBI harus disimpan sebagai teks agar nol awal tetap ada. Pasangan offering + student tidak boleh duplikat. Student bernama sama boleh mempunyai NBI berbeda; NBI yang sudah ada dengan nama berbeda ditolak saat menambah/impor agar tidak mengubah master diam-diam.

Edit membutuhkan alasan dan version; form lama ditolak dengan 409. NBI setelah pendaftaran tidak dapat diubah dari UI M2. Perubahan nama master yang juga terdaftar pada offering lain hanya boleh oleh admin. Kosongnya pilihan dosen pada edit mempertahankan dosen existing; pengosongan perlu checkbox eksplisit. Enrollment dapat dinonaktifkan; histori tidak dihapus.

Perubahan sesi langsung berlaku hari ini, memeriksa izin sesi asal dan tujuan serta kapasitas dalam transaksi. Membership lama ditutup (valid_until eksklusif), membership baru dibuat; tidak menghapus histori. Transfer temporary/future approval bukan fitur M2 (M5). Database memastikan satu membership terbuka per enrollment dan sesi/enrollment mempunyai offering sama.

## Dosen pembimbing master dan penetapan massal

Tambah master dosen dari Data Praktikan. Nama wajib, kode identitas/NIDN opsional. Gunakan dosen existing bila sudah tersedia. Nama sama untuk dua orang berbeda membutuhkan kode berbeda dan checkbox konfirmasi; nama bukan identifier global unik. Identity code unik, dan constraint juga mencegah duplikasi nama master yang belum punya kode.

1. Centang baris atau pilih **seluruh halaman aktif**. Jumlah pilihan terlihat.
2. Untuk seluruh hasil filter, pilih mode **Seluruh hasil filter — N praktikan** secara eksplisit. Ini terpisah dari checkbox halaman; maksimal 2000 sasaran per batch.
3. Pilih dosen master. Default **Isi yang belum ditentukan**: dosen existing dipertahankan. **Ganti seluruh pilihan** memerlukan alasan.
4. Pratinjau menampilkan seluruh NBI/nama sasaran, jumlah, mode, dosen dan alasan.
5. Konfirmasi daftar sasaran, lalu Simpan. Server memeriksa ulang seluruh scope dan version; satu sasaran tidak berizin/stale menggagalkan seluruh batch. Perubahan filter setelah preview tidak memperluas sasaran.

Setiap perubahan memiliki audit old/new supervisor_id, actor, waktu, alasan dan batch UUID yang sama. Ringkasan menampilkan jumlah diperbarui/dilewati. Pratinjau terikat akun/offering, berlaku 30 menit, hanya bisa dipakai satu kali. Memilih dosen yang sama dengan nilai existing dihitung dilewati.

## Impor CSV/XLSX

Unduh template CSV kosong dari Data Praktikan. Kolom yang diperlukan: NBI, Nama, Simpraktikum, Kelas, Sesi; Dosen Pembimbing opsional. Kolom No/ikon spreadsheet bukan data praktikan. CSV UTF-8 memakai pemisah koma. XLSX membaca sheet pertama. `.xls`, `.xlsm`, ODS dan format lain belum didukung.

Batas: berkas 5 MB, 2000 baris nonkosong, 30 kolom; ZIP XLSX maksimum 64 MB setelah ekstraksi dan 2000 entry. Runtime PHP menerima 11 MB untuk upload, post 12 MB, Nginx 16 MB (kapasitas runtime M3); validasi Laravel tetap membatasi file 5 MB. Dosen yang diisi harus nama master unik atau identity code; nama ambigu ditolak, tidak membuat master dosen otomatis. Sesi harus label yang tepat dalam offering yang dipilih dan berizin.

Alur: unggah → petakan kolom → Validasi pratinjau → periksa error baris → konfirmasi → Simpan impor. NBI XLSX wajib sel teks; angka ditolak karena nol awal yang sudah hilang tidak dapat dipulihkan. Formula/tanggal pada kolom data dipetakan ditolak, tidak dieksekusi. Baris kosong/header berulang diabaikan. NBI duplikat dalam file atau enrollment existing menghasilkan error. Tidak ada upsert/overwrite dosen existing secara diam-diam.

Unggah/pratinjau hanya membuat staging terenkripsi di database, **tidak mengubah students/enrollments/membership**. Berkas upload tidak dipublikasikan atau disimpan sebagai bahan unduh. Commit membaca data server, memvalidasi ulang izin/duplicate/master/kapasitas, lalu seluruh baris dalam satu transaksi. Baris invalid menolak seluruh impor; perbaiki file/pemetaan dan ulangi preview. Preview berlaku 30 menit, terikat akun/offering dan version, satu kali commit. Scheduler menghapus staging kedaluwarsa tiap jam memakai shared cache locking; `php artisan portal:prune-previews` dapat dijalankan operator. Tidak mengubah data akademik.

Pembaca/writer: OpenSpout 5.12.0, terkunci di composer.lock, PHP >=8.4. Referensi resmi API: https://github.com/openspout/openspout/blob/5.x/docs/documentation.md.

## Ekspor

Ekspor CSV/XLSX membutuhkan reports.export dan scope sesi. Filter diterapkan server, parameter tambahan tidak dapat memperluas izin. Tidak ada ekspor publik. XLSX menggunakan sel teks untuk NBI/nama; formula-like text di semua field dinetralisasi. CSV juga melindungi awalan = + - @ termasuk whitespace/control. Dosen kosong diekspor kosong agar bisa dipetakan sebagai optional pada impor; identity code digunakan jika ada untuk mencegah ambigu nama. Header No dapat diabaikan saat pemetaan ulang.

Untuk mempertahankan NBI dalam Excel, pilih ekspor XLSX. Jika memakai CSV, impor NBI sebagai kolom teks; double-click CSV dapat membuat Excel menebak angka dan menghilangkan nol awal. Ekspor tidak membuat komponen/bobot nilai.

## Verifikasi dan batas

Pengujian tetap MySQL portal_test terisolasi. Jalankan `vendor/bin/phpunit`, panduan lingkungan di docs/08_OPERATIONS.md. Browser M2: `scripts/browser-m2-check.cjs` dengan Playwright 1.58.2, PORTAL_BASE_URL dan PORTAL_DEMO_PASSWORD, serta direktori artifact /artifacts. PreviewSeeder hanya pada portal_test dan menghasilkan akun/master berlabel Data contoh; browser membuat contoh tambahan terlabel melalui UI.

M3 kini menyediakan pertemuan/snapshot/presensi/TTD dan modul; panduan di docs/10_M3_GUIDE.md. M4–M6 belum selesai: tugas/nilai/rules, form publik/status secret, approval susulan/remidi, log viewer, backup UI/otomatis dan lock/reopen. Lihat BUILD_STATUS.md untuk hasil tes aktual.
