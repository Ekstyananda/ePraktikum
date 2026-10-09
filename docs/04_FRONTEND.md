# Kontrak frontend
Blade + Bootstrap dengan aset dibundle lokal. Bahasa Indonesia. Base: navy #12325B, blue #2563EB, cyan #06B6D4, background #F4F7FB, white. Font sans-serif terbaca, ukuran tabel cukup, label status selain warna.
Layout umum mengikuti mockup pertama: portal publik header horizontal dan hero ilustrasi basis data; pengelola sidebar navy, topbar semester/praktikum, kartu putih. Tidak memakai foto kampus dari mockup revisi. Logo asli berwarna di bidang putih, jangan mendistorsi/reka ulang logo.
Portal publik tanpa avatar, akun mahasiswa atau sidebar pengelola. Login Aslab kecil kanan atas. Navigasi publik responsif HP. Pengelola sidebar collapse; tabel overflow horizontal dengan sticky NBI/nama.
Setiap halaman memiliki empty/loading/error/success, validasi per field, status disabled, konfirmasi tindakan consequential. Search/filter/page size dan pagination server. Simpan menunjukkan sukses/gagal, dirty-state dan konflik concurrent. Komponen nilai dinamis bukan hardcode.
Modal edit aslab bisa full page untuk kejelasan: Profil, Praktikum, Sesi, Hak Akses. Detail log read-only diff before/after dengan alasan. Backup admin-only kecuali izin eksplisit diberikan.
A4 portrait default presensi hitam putih, repeat header, avoid split rows, cukup ruang tanda tangan, nomor alternating per row satu kolom. Test print browser/PDF, jangan berpatokan PNG yang tidak presisi.
Lihat design/INDEX.md. Crop kecil tetap resolusi native, bukan screenshot implementasi. Dokumen halaman mengoreksi artefak gambar.

Data Praktikan: kolom dosen tampil Belum ditentukan, checkbox seleksi, toolbar Tetapkan Dosen Pembimbing (aktif jika ada pilihan), filter dosen/belum ditentukan. Modal pilih dosen master, jumlah target, mode Isi yang kosong (default)/Ganti seluruh pilihan (konfirmasi+alasan), ringkasan hasil. Selection scope halaman vs seluruh filter jelas.
