# Scope dan keputusan final
## Pengguna
Admin: semua scope, semester, akun dan permission. Aslab: seluruh sesi dalam offering praktikum yang ditugaskan secara default. Pembatasan sesi opsional per akun. Praktikan: portal publik tanpa akun.
Admin membuka Pengaturan > Aslab > klik nama > Profil/Praktikum/Sesi/Hak Akses > Simpan. Preset izin standar tersedia. Hak ubah bobot/kelulusan tidak aktif default; admin dapat mengaktifkannya. Kelola akun khusus admin.

## Menu publik
Beranda, Jadwal, Modul & Soal, Pengumpulan Tugas Digital, Pengajuan (izin/pindah/susulan), Remidi, Cek Status. Pengumuman tersedia dari beranda dan daftar/detail. Tidak menampilkan roster, nilai atau riwayat kehadiran pribadi.
## Menu pengelola
Dashboard, Data Praktikan, Sesi & Jadwal, Pertemuan & Modul, Presensi, Pengumpulan Cetak/Digital, Penilaian (lab dan rekap), Laporan Akhir, Pengajuan, Susulan, Remidi, Pengumuman, Rekap & Ekspor, Log Aktivitas, Backup, Pengaturan. Susulan/remidi dapat menjadi tab pengajuan tetapi memiliki alur lengkap.

## Presensi
Tanda tangan fisik, aslab rekap. Hadir/Izin/Sakit/Alpa/Belum dicatat. Default belum dicatat, bukan alpa. Bulk hadir hanya baris dipilih. Scan opsional privat. Cetak A4 dengan identitas praktikum semester sesi pertemuan tanggal jam ruangan. Kolom No NBI Nama Sesi TTD. TTD satu kolom tanpa divider tengah: odd nomor urut kiri, even sekitar tengah-kanan pada barisnya. Nomor bukan paritas NBI. Nama lebih lebar dari TTD, sekitar No 6%, NBI 20%, Nama 39%, Sesi 10%, TTD 25%; sesuaikan setelah QA. Ulang header dan jangan pecah baris antarlaman.

## Data master
No otomatis; NBI teks; Nama; Simpraktikum (kelas SIM); Kelas (misalnya pagi/sore); Sesi; Dosen Pembimbing. Ikon Tt spreadsheet bukan kolom.
## Belum ditentukan
Bobot, komponen tambahan, pembulatan, batas kelulusan, penalti terlambat, aturan remidi dan jadwal nyata menunggu SBD. Jangan mengambil aturan MJK / 13 komponen. Modul belum diberikan. Aplikasi harus dapat beroperasi tanpa aturan nilai final dan mencegah finalisasi jika konfigurasi wajib belum lengkap.
## Di luar scope awal
Ujian lockdown Windows, AI koreksi SQL otomatis, Telegram, inventaris PC dan login mahasiswa. Tidak dijanjikan dalam paket.

## Dosen pembimbing — keputusan tambahan
Dosen pembimbing boleh menyusul. Field opsional pada impor dan input awal, tidak menghalangi penyimpanan. Tampilkan Belum ditentukan jika kosong. Satu dosen dapat membimbing banyak praktikan. Pengelola berizin dapat menetapkan dosen secara massal melalui checkbox mahasiswa, bukan input satu per satu.
