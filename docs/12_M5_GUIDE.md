# Panduan M5 — Portal publik, pengajuan, susulan dan remidi

M5 membuka layanan praktikan tanpa login dan alur pemeriksaan pengelola. Praktikan tetap tidak memiliki akun. Setiap kiriman publik berstatus **menunggu pemeriksaan** sampai pengelola memutuskan.

## Portal publik

Menu: Beranda, Jadwal, Modul, Pengumpulan, Pengajuan, Remidi, Cek Status, serta tombol kecil Login Aslab. Di HP, menu dilipat ke tombol ☰.

- Hanya praktikum berstatus **active** pada semester yang tidak terkunci yang tampil di portal. Praktikum draf tidak terlihat publik. Ubah status di **Administrasi → Pelaksanaan Praktikum**.
- **Jadwal**: sesi (hari, jam WIB, ruang, aslab penanggung jawab) dan pelaksanaan pertemuan. Tidak menampilkan roster.
- **Pengumpulan**: tugas digital aktif beserta buka/tenggat/tutup. File PDF/DOCX/ZIP/TXT/SQL, maksimal 10 MB (`ACADEMIC_UPLOAD_MAX_KB`). Tugas cetak tetap diserahkan langsung ke aslab.
- **Pengajuan**: tab Izin, Pindah (satu pertemuan atau permanen dengan tanggal efektif) dan Susulan. Form hanya terbuka di dalam periode yang ditetapkan pengelola.
- **Remidi**: program aktif dengan instruksi, ketentuan kelayakan, jadwal pelaksanaan dan periode form.
- **Cek Status**: masukkan token dari bukti kiriman. Hanya status minimum dan jadwal (bila disetujui) yang ditampilkan; tidak ada nilai, daftar nama atau file.

### Identitas dan token

- Form meminta NBI, nama sesuai pendaftaran dan sesi saat ini. Ketiganya dicocokkan dengan enrollment aktif. Kesalahan apa pun menghasilkan satu pesan generik yang sama, sehingga form tidak bisa dipakai untuk menebak roster. Pencocokan bukan autentikasi; pengelola tetap memeriksa.
- Setelah kirim, halaman bukti menampilkan token acak 64 karakter heksadesimal **satu kali**, dengan tombol salin dan cetak. Database hanya menyimpan hash SHA-256. Token tidak masuk log audit, tidak di-flash ke sesi, dan Cek Status memakai POST sehingga token tidak muncul di URL atau log akses.
- Revisi tugas digital hanya bisa dikirim lewat Cek Status dengan token, setelah pengelola meminta revisi dan selama periode tugas masih terbuka. Setiap revisi menjadi versi baru; versi lama tetap tersimpan.

### Batas laju

Didefinisikan di `AppServiceProvider`:

| Limiter | Batas | Dipakai di |
|---|---|---|
| `public-submit` | 60/menit per IP **dan** 6/menit per NBI (atau token untuk revisi) | Pengumpulan, Pengajuan, Remidi, Revisi |
| `public-status` | 30/menit per IP | Cek Status |

Batas per IP sengaja longgar karena satu jaringan kampus biasanya memakai satu IP publik (NAT). Batas ketat ada pada identitas. **Wajib** isi `TRUSTED_PROXIES` bila aplikasi berada di belakang reverse proxy atau Cloudflare Tunnel (untuk cloudflared di host: `TRUSTED_PROXIES=10.231.0.1`, lihat docs/08_OPERATIONS.md bagian Cloudflare Tunnel). Tanpa itu semua pengunjung tampak dari satu IP dan batas per IP berlaku untuk seluruh pengguna sekaligus. Rate limiter memakai cache store aplikasi; produksi memakai `CACHE_STORE=database` agar konsisten antarproses PHP-FPM.

## Pengelola

### Periode dan program remidi

**Pengajuan → Periode & program remidi** (butuh izin `requests.manage` untuk seluruh sesi):

- Periode form pengajuan: waktu buka/tutup WIB dan instruksi untuk praktikan.
- Program remidi: komponen nilai, judul, instruksi, ketentuan kelayakan, periode form, jadwal dan ruang pelaksanaan, berkas wajib/opsional, status aktif. Program yang sudah dipakai pengajuan **dibekukan**; hanya status aktif yang dapat diubah. Buat program baru untuk ketentuan berbeda.

Semua perubahan memerlukan alasan, konfirmasi dan versi terbaru; tercatat di log aktivitas.

### Memeriksa pengajuan

**Pengajuan** menampilkan tab status (badge jumlah) dan filter jenis/NBI/nama. Sidebar menampilkan jumlah pengajuan yang menunggu. Detail pengajuan memuat rincian, bukti privat (unduh hanya oleh pengelola berizin), kapasitas sesi tujuan, form keputusan dan riwayat hasil.

Efek persetujuan, dijalankan dalam satu transaksi dengan kunci baris:

| Jenis | Efek |
|---|---|
| Izin | Hanya keputusan. **Tidak** mengubah presensi; presensi tetap dicatat dari TTD. |
| Pindah satu pertemuan | Praktikan masuk snapshot pelaksanaan tujuan dan keluar dari snapshot asal untuk pertemuan itu saja. Membership tidak berubah. Ditolak bila snapshot sudah dibekukan, pelaksanaan tujuan sudah dimulai, atau kapasitas penuh. |
| Pindah permanen | Membership lama ditutup pada tanggal efektif dan membership baru dibuat; riwayat tetap utuh. Kapasitas diperiksa pada setiap batas tanggal dan pertemuan mendatang. |
| Susulan | Wajib jadwal dan ruang. Tidak membuat kehadiran otomatis. |
| Remidi | Wajib nilai awal sudah tercatat. Nilai awal dibekukan pada pengajuan. |

Kapasitas dihitung ulang setelah mengunci baris sesi tujuan, sehingga dua pengelola yang menyetujui bersamaan tidak dapat melebihi kapasitas. Persetujuan dengan versi lama menghasilkan konflik 409.

### Hasil susulan dan remidi

Setelah pelaksanaan, catat waktu sebenarnya beserta status (susulan) atau nilai (remidi), dengan alasan. Setiap pencatatan menjadi versi baru di `request_results`; status pengajuan menjadi Selesai. Hasil susulan butuh `attendance.manage`, hasil remidi butuh `grades.manage` pada sesi praktikan.

Nilai awal pada tabel nilai **tidak pernah ditimpa**. Hasil remidi tidak otomatis menggantikan nilai pada finalisasi, karena aturan remidi SBD belum ditetapkan. Pratinjau finalisasi menolak praktikan yang masih memiliki pengajuan susulan/remidi berstatus menunggu atau disetujui.

### Kiriman digital

**Pengumpulan → Kiriman digital** menampilkan file dari portal publik dalam lingkup `submissions.manage`. Terima atau tolak dengan catatan. Kiriman yang diterima menjadi penerimaan digital versi 1 pada modul M4. Bila penerimaan untuk tugas itu sudah ada, kiriman duplikat harus ditolak; revisi berikutnya memakai token kiriman yang diterima.

## Data dan migrasi

Migrasi `2026_10_04_010000_create_public_workflows` menambah `public_deliveries`, `request_windows`, `remedial_programs`, `academic_requests`, `request_results` dan kolom `meeting_participants.approved_request_id`. Migrasi juga membuat `files.uploaded_by`, `submissions.receiver_id` dan `submission_versions.recorded_by` nullable untuk kiriman publik tanpa akun. Migrasi bersifat additive; ambil dump database Lab sebelum `migrate` di produksi.

## Pengujian

- PHPUnit: `tests/Feature/PublicWorkflowTest.php` (13 tes) di MySQL `portal_test` terisolasi.
- Preview: `PublicPreviewSeeder` hanya untuk local/testing dan `portal_test`, butuh `PORTAL_DEMO_PASSWORD` acak, dan menolak dijalankan dua kali. Data berlabel Data contoh.
- Browser: `scripts/browser-m5-check.cjs` (Playwright 1.58.2) terhadap seeder M5 yang baru, dengan `PORTAL_BASE_URL`, `PORTAL_DEMO_PASSWORD` dan direktori `/artifacts`. Skrip menguji alur publik lewat UI, dua pengelola menyetujui pindah sesi bersamaan, kiriman digital, remidi, Cek Status dan tampilan HP.

## Lanjutan

Pengumuman, rekap/ekspor, log aktivitas, backup dan kunci semester: docs/13_M6_GUIDE.md.
