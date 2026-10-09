# BUILD_STATUS — Portal Praktikum

Tanggal verifikasi: 4 Oktober 2026 (WIB). Cakupan implementasi: **M0–M6** (seluruh milestone docs/06). Tidak ada akses ke server produksi.

## M0 — selesai dan diverifikasi lokal

- Laravel 13.34.0 dengan composer.lock, PHP 8.4 FPM, MySQL, Blade, Bootstrap 5.3.8 lokal beserta lisensi/checksum.
- Compose utama: app, Nginx, satu scheduler; worker opsional melalui profile jobs. Tidak ada layanan MySQL dalam deployment utama. Network MySQL eksternal dan alias configurable, DB_DATABASE=Lab pada .env.example, password/APP_KEY kosong.
- PHP nonroot UID 33, storage persisten, healthcheck app/Nginx, endpoint DB readiness dengan respons generik, Nginx hanya mengeksekusi index.php.
- Layout awal M0–M4 hanya memakai palet, sidebar navy dan kartu putih; komponen mockup belum dibangun. Ini dikoreksi pada bagian *UI fidelity pass* di bawah. Portal publik tetap tanpa sidebar/avatar/login praktikan, termasuk ketika pengelola sudah login.
- Petunjuk instalasi, proxy HTTPS, jaringan, migrasi non-destructive, bootstrap admin, update dan backup/restore manual tersedia di README.md dan docs/08_OPERATIONS.md.
- CI lint/test/asset check/Blade/build disediakan di .github/workflows/ci.yml. Pemeriksaan setara dijalankan lokal; workflow belum dijalankan di GitHub karena repo belum dihubungkan.

## M1 — selesai dan diverifikasi

- Login/logout admin dan aslab, error login generic, pembatasan percobaan, CSRF, regenerasi session, dan penolakan akun nonaktif.
- Bootstrap admin pertama melalui command dengan kata sandi tersembunyi; tidak ada akun/password default dan tidak ada registrasi publik.
- Daftar aslab dengan search/page size/pagination server. Klik nama membuka Profil, Praktikum, Sesi, Hak Akses; semua kontrol menyimpan ke MySQL dengan validasi backend.
- Admin membuat aslab, mengubah profil/status, mereset password, menyimpan penugasan offering, scope sesi dan izin individual. Kelola akun khusus admin; role/assignment owner dari payload tidak dapat memberikan privilege tambahan.
- Semua sesi offering ditugaskan menjadi default, termasuk sesi baru. Pembatasan sesi opsional. Scope kosong menolak akses operasional. Offering lain dan sesi lintas offering ditolak server.
- Preset standar tersedia. Bobot/kelulusan dan backup nonaktif default. Izin tidak dicentang adalah deny eksplisit; grant/deny dibaca terbaru tanpa cache permission lintas request.
- Perubahan transaksi all-or-nothing dengan audit old/new/actor/reason. Password/token tidak masuk audit. Version + row lock mencegah overwrite edit lama, menghasilkan halaman konflik 409. Uji konflik menggunakan dua payload dengan versi lama, bukan load test simultan.
- Reset password/nonaktif menghapus sesi DB aslab; middleware auth.session juga mendeteksi perubahan password. Success/error, validasi field, konfirmasi simpan, dirty-state, loading simpan dan sidebar mobile berfungsi.
- StaffAccess menjadi pemeriksaan bersama untuk modul berikutnya; route baca sesi saat ini memeriksa sessions.manage. Izin modul masa depan disimpan dan telah diuji pada service, tetapi modul tersebut belum diimplementasikan.

## M2 — selesai dan diverifikasi

- CRUD semester, master praktikum, offering dan sesi/jadwal mingguan WIB melalui UI. Master khusus admin; sesi mengikuti izin backend. Version, alasan perubahan, audit dan foreign key melindungi perubahan/penghapusan data terpakai.
- Master students dengan NBI teks, enrollment per offering, daftar praktikan berfilter/search/pagination server, tambah/edit/nonaktif, serta histori membership sesi. Scope sesi asal/tujuan dan kapasitas diperiksa dalam transaksi; perubahan langsung berlaku hari ini.
- Dosen pembimbing master dan FK nullable. Tambah/impor praktikan tanpa dosen berfungsi, tampil Belum ditentukan; tidak membuat akun praktikan. Nama dosen ambigu perlu identity code, bukan dipilih otomatis.
- Bulk dosen dari checkbox halaman atau seluruh hasil filter secara eksplisit. Preview membekukan ID/version/NBI/nama sasaran dan terikat pemilik/offering. Default isi kosong mempertahankan dosen existing; replace membutuhkan alasan dan konfirmasi. Commit satu transaksi, recheck izin/version, audit per perubahan dengan batch UUID; sasaran invalid membatalkan seluruh batch.
- Impor CSV/XLSX unggah → mapping → validasi/error per baris → konfirmasi → commit. Preview tidak mengubah data akademik; staging terenkripsi, kedaluwarsa 30 menit dan dipangkas tiap jam. Duplikat, NBI numerik XLSX, formula, sesi/dosen ambigu atau di luar izin ditolak. Dosen optional; commit memeriksa ulang izin, master, duplicate dan kapasitas.
- Ekspor master CSV/XLSX memakai filter dan reports.export. NBI XLSX sel teks, formula-like text dinetralisasi; CSV perlu dibuka dengan kolom NBI teks di spreadsheet.
- Tampilan Data Praktikan mengikuti kolom tertulis, NBI/nama sticky, layout navy/kartu putih dan logo asli. Tidak ada statistik akademik atau syllabus/bobot resmi yang dibuat-buat.
- Panduan lengkap dan batas format/alur: docs/09_M2_GUIDE.md. Main Compose tetap memakai MySQL eksternal; PHP upload/post ditata untuk batas impor 5 MB. Worker tetap optional.

## M3 — selesai dan diverifikasi

- Pertemuan bersama per offering: jumlah awal default 5 editable dan dikonfirmasi, tambah/edit nomor/judul dengan version/alasan/audit. Tidak membuat syllabus, materi atau tanggal fiktif.
- Pelaksanaan per sesi: tanggal/jam WIB disimpan UTC, room, unique sesi/pertemuan, penolakan jadwal tumpang tindih. Backend scope materials.manage per sesi; perubahan pertemuan/modul bersama memerlukan izin semua sesi.
- Snapshot peserta eksplisit sebelum presensi/cetak: effective membership pada tanggal pelaksanaan WIB, enrollment aktif saat pembekuan, NBI/nama/kelas/sesi/print_order dan metadata pelaksanaan tersalin. Perubahan master/membership berikutnya tidak mengubah arsip. Database memastikan offering cocok, satu peserta per pertemuan logis, satu presensi per peserta. Jadwal snapshot tidak dapat diubah M3.
- Presensi awal Belum dicatat; Hadir/Izin/Sakit/Alpa/Belum dicatat. Filter/pagination server; simpan atau bulk Hadir hanya selected halaman aktif. Koreksi data tersimpan termasuk catatan wajib alasan. Perubahan, actor, waktu, before/after dan batch audit dalam transaksi; stale versi 409 tanpa partial write.
- Scan TTD opsional PDF/JPG/PNG, privat, checksum/uploader, penambahan mempertahankan file lama. Auth aktif + attendance.manage scope sesi pada baca/unggah/unduh, bukan URL file publik. Transaksi gagal membersihkan staged file.
- Modul PDF/DOCX/ZIP/TXT/SQL draf privat, terbit/tarik eksplisit beralasan+konfirmasi+version. Route katalog/unduh publik hanya materi published; tidak memuat roster/scan/nilai. SQL tidak dieksekusi. File lama tidak ditimpa; revisi via draf baru.
- Cetak khusus A4 portrait tanpa sidebar, metadata snapshot, No/NBI/Nama/Sesi/TTD, proporsi 6/20/39/10/25, satu kolom TTD dengan nomor urut odd kiri/even tengah-kanan. Header berulang, row avoid split; PDF aktual 67 peserta/5 halaman diverifikasi dan ditinjau.
- Form dirty-state, loading, konfirmasi, error field/conflict dan sidebar mobile. Form Edit presensi per praktikan menyediakan input penuh di HP dengan backend yang sama. Label aksesibilitas di tabel scroll diberi containing block agar tidak membuat overflow body; input status/catatan cukup lebar untuk dioperasikan saat scroll.
- Panduan lengkap: docs/10_M3_GUIDE.md. Main Compose tetap tanpa MySQL; runtime upload/post 11M/12M, Nginx 16M; validasi M3 default 10 MB dapat diturunkan. Impor M2 tetap 5 MB.

## M4 — selesai dan diverifikasi

- Tugas bertipe Pendahuluan/Aktivitas/Lab/Laporan akhir/Lainnya, mode cetak/digital/direct. Pertemuan asal terpisah dari pelaksanaan pengumpulan per sesi: Aktivitas 1 dikumpulkan pada pertemuan 2 tetap berorigin 1. Jadwal buka/tenggat/tutup WIB, izin terlambat, version dan audit; jadwal terpakai dibekukan.
- Penerimaan cetak tanpa attachment, actual received_at terpisah dari recorded_at/pengelola. Status penerimaan/revisi/selesai dan keputusan tidak dikumpulkan beralasan; keterlambatan mengikuti actual receipt. Riwayat perubahan dapat dibaca pada laporan penerimaan.
- Digital oleh pengelola login dengan versi file privat, waktu/actor/checksum dan versi lama tetap tersedia. Revisi baru hanya setelah diminta. Validasi extension/MIME/ukuran, periode, CSRF dan throttle; gagal transaksi membersihkan staged file. Form publik/token merupakan M5.
- Checklist laporan akhir per praktikan: Cover, Pendahuluan 1–5, Pustaka masing-masing dan Aktivitas 1–5 (16 item). Checklist tidak mengubah laporan lain, tidak memberi nilai otomatis dan tidak membuat komponen berbobot.
- Nilai lab langsung tanpa submission. Komponen dinamis per tugas, maksimum skor, mandatory/active dan bobot nullable. NULL tetap Belum dinilai; keputusan nol eksplisit beralasan. Simpan hanya komponen terpilih, koreksi wajib alasan/version, audit atomik dan stale batch tanpa partial write.
- Draf aturan berversi tanpa bobot/ambang resmi bawaan. Publish memeriksa total bobot 100%, batas huruf mencakup 0, pembulatan/presisi/ambang/kebijakan terlambat lengkap dan komponen sama dengan draf. Aktivitas 5 + laporan berbobot terpisah memerlukan konfirmasi ketentuan resmi. grading_rules.manage default deny, konfigurasi bersama perlu scope semua sesi.
- Pratinjau finalisasi memeriksa nilai/kewajiban/late decision/checklist dan aturan published valid. Digest pratinjau mencegah finalisasi data yang berubah setelah ditinjau. Arsip menyimpan identitas, aturan, rincian dan hasil; nilai/pengumpulan/checklist praktikan final terkunci. Satu final juga mengunci konfigurasi bersama offering. Belum ada reopen/admin correction M6.
- Scope backend current roster dan jadwal arsip, termasuk setelah perpindahan sesi; read/write/download lintas scope ditolak. Semester/offering locked menolak mutation. Daftar, form penuh desktop/mobile dan navigasi mengikuti layout/logo yang sama.
- Panduan operasional dan batasan: docs/11_M4_GUIDE.md. Seeder khusus portal_test dan skrip browser memakai Data contoh; tidak ada aturan resmi SBD yang diasumsikan.

## Pelengkap spesifikasi halaman setelah M6

Hasil pemeriksaan ulang terhadap design/*.md dan docs/01/04:

- **Pengumpulan per pertemuan** (admin/07): menu Pengumpulan kini membuka tab **Cetak/Digital** dengan filter pertemuan, sesi, NBI/nama dan jumlah baris. Tabel berisi setiap tugas yang dijadwalkan dikumpulkan pada pertemuan itu untuk tiap sesi (Pendahuluan *n* bersama Aktivitas *n−1*), dengan tanggal terima, status, terlambat dan nilai komponen bila ada. Default pertemuan terakhir yang sudah dimulai. Daftar tugas pindah ke "Kelola tugas & jadwal".
- **Menu Laporan Akhir** (docs/01, admin/09): daftar praktikan dengan status penerimaan, tanggal terima dan progres checklist individual (mis. 16/16 bagian), menuju form penerimaan/checklist. Sidebar menyorot menu ini juga pada form laporan akhir.
- **Jumlah baris per halaman** (docs/04) pada pengajuan, kiriman digital, pengumuman, kelola tugas, penerimaan, nilai, sesi, master serta dua halaman baru: pilihan 10/25/50/100 (default 25) melalui helper `App\Support\PerPage`, nilai lain ditolak validasi.
- Tes: `CollectionViewTest` (4 tes) untuk pengelompokan per jadwal, tab mode, scope sesi, laporan akhir dan jumlah baris. PHPUnit 132 tes / 1314 assertion; skrip browser M1–M6 dan PDF A4 lulus pada stack QA terisolasi (image `:qa`, subnet terpisah, `.env.testing`), tanpa menyentuh stack produksi.

## M6 — selesai dan diverifikasi

- **Pengumuman**: editor per praktikum (izin `announcements.manage` seluruh sesi), audiens publik/internal, umum khusus admin, draf/terbit/arsip dengan version, alasan dan audit. Markdown dirender tanpa HTML mentah dan tanpa tautan tidak aman. Publik: beranda (3 terbaru), `/pengumuman` dan detail; draf, arsip, internal dan praktikum nonaktif/terkunci tidak terlihat. Dashboard menampilkan pengumuman publik dan internal.
- **Rekap & Ekspor** (`reports.export`, scope sesi): presensi (snapshot + TTD), pengumpulan, nilai (praktikan final dari snapshot finalisasi beserta versi aturan; remidi di kolom terpisah) dan pengajuan. Filter sesi/pertemuan, CSV/XLSX dari sumber data yang sama dan aman formula, cetak A4 landscape, setiap ekspor diaudit.
- **Log Aktivitas** read-only: admin seluruh log termasuk akun; aslab dengan izin baru `logs.view` (default ditolak, wajib seluruh sesi) hanya log praktikumnya. Filter pelaku/entitas/aksi/sesi/praktikum/tanggal WIB; detail sebelum/sesudah dengan penanda perubahan, batch dan alasan; kunci rahasia disamarkan; tidak ada route ubah/hapus.
- **Backup**: dump logis dalam consistent snapshot (tanpa mysqldump/password di argumen) + seluruh berkas privat + manifest SHA-256 dalam satu ZIP, opsional AES-256. Ditulis `.partial`, diverifikasi, lalu diberi nama final dan `.sha256`; berhasil hanya bila semuanya sukses, gagal tanpa sisa arsip dan tanpa rahasia/path di error. Manual dari UI diantrekan dan diproses container scheduler (tanpa worker, satu runner dengan cache lock); jadwal harian dan retensi diatur admin; retensi hanya menghapus arsip aplikasi. Folder tujuan di-mount terpisah dari volume storage. `portal:backup-verify` memeriksa checksum dan menguji restore ke koneksi `restore` terisolasi, menolak database aplikasi/Lab. Halaman admin, atau aslab dengan `backup.run` eksplisit.
- **Kunci semester/pelaksanaan dan buka kembali** oleh admin dengan alasan, konfirmasi, version dan audit (`master.locked`/`master.reopened`). Status terkunci tidak bisa dipilih dari form biasa; praktikum tidak bisa dibuka selama semesternya terkunci.
- **Buka koreksi nilai final** oleh admin dengan alasan: `final_results` kini berversi, arsip lama ditandai digantikan dan tetap terlihat sebagai riwayat, kolom generated + unique menjamin satu arsip aktif; finalisasi ulang membuat versi baru.
- **Pengaturan** (admin/12): pusat semester, master, pelaksanaan, akun aslab, log dan backup. Sidebar memuat Pengumuman, Rekap dan Log Aktivitas per praktikum; menu "Segera" tidak ada lagi.
- Ditemukan dan diperbaiki saat pengujian M6: parameter route dipetakan berdasarkan posisi sehingga detail log per praktikum memeriksa praktikum yang salah (kini method terpisah), daftar pelaku log memakai kolom yang salah, dan detail log menandai field yang tidak diubah.
- Panduan: docs/13_M6_GUIDE.md; prosedur backup/restore di docs/08_OPERATIONS.md.

## M5 — selesai dan diverifikasi

Draf M5 yang ditinggalkan sesi Codex ditinjau, ditulis ulang, diberi route dan diuji. Struktur datanya dipertahankan.

- Portal publik tanpa login: Jadwal (sesi mingguan, pelaksanaan, aslab), Pengumpulan digital, Pengajuan (tab Izin/Pindah/Susulan; pindah satu pertemuan atau permanen dengan tanggal efektif), Remidi dan Cek Status. Beranda: empat menu cepat aktif, jadwal terdekat dan modul terbit. Hanya praktikum berstatus active pada semester tidak terkunci yang tampil.
- Identitas NBI + nama + sesi dicocokkan dengan enrollment aktif; setiap kegagalan menghasilkan satu pesan generik. Tidak ada endpoint pencarian nama. Token acak 256 bit ditampilkan sekali (salin/cetak), disimpan sebagai hash SHA-256, tidak masuk audit, tidak di-flash, dan Cek Status memakai POST dengan `Cache-Control: no-store` serta `Referrer-Policy: no-referrer`. Status hanya menampilkan keadaan minimum dan jadwal yang disetujui.
- Batas laju bernama: 60/menit per IP dan 6/menit per NBI/token untuk form, 30/menit per IP untuk Cek Status. Batas per IP sengaja longgar untuk NAT kampus. Upload PDF/DOCX/ZIP/TXT/SQL (bukti juga gambar) maksimal 10 MB, privat, berkas executable ditolak, staged file dibersihkan saat gagal.
- Pengelola: daftar pengajuan dengan tab status dan jumlah, filter jenis/NBI, badge di sidebar dan dashboard; detail dengan bukti privat, kapasitas sesi tujuan, keputusan beralasan dan riwayat hasil. Scope `requests.manage` diperiksa pada sesi asal dan tujuan; versi lama 409.
- Persetujuan dalam satu transaksi dengan kunci offering, baris pengajuan dan sesi tujuan. Pindah satu pertemuan: masuk snapshot tujuan dan keluar dari snapshot asal pertemuan itu saja, ditolak setelah snapshot atau saat penuh. Pindah permanen: membership lama ditutup pada tanggal efektif dan membership baru dibuat, kapasitas diperiksa di setiap batas tanggal dan pertemuan mendatang. Izin/susulan tidak pernah membuat Hadir. Susulan wajib jadwal dan ruang.
- Remidi: program (komponen, instruksi, kelayakan, periode, jadwal, berkas) dibekukan setelah dipakai. Persetujuan mewajibkan nilai awal dan membekukannya pada pengajuan. Hasil susulan/remidi berupa versi baru; nilai dan presensi asli tidak ditimpa, dan hasil remidi tidak otomatis menggantikan nilai. Finalisasi ditolak selama masih ada susulan/remidi yang menunggu atau disetujui.
- Kiriman digital publik diperiksa di Pengumpulan → Kiriman digital; diterima menjadi penerimaan digital versi 1 (M4). Revisi via token hanya setelah diminta pengelola dan selama periode terbuka; setiap revisi versi baru, file lama tetap.
- Pint kini lulus untuk seluruh repo. Kode draf yang mengubah M2/M3 (`Roster::reservedCount`, snapshot `MeetingRoster`) sudah dirapikan dan dicakup tes M5 serta regresi M2/M3.
- Panduan: docs/12_M5_GUIDE.md. Preview: `PublicPreviewSeeder` khusus portal_test.

## Review M0–M5 sebelum M6 (4 Oktober 2026)

Temuan dan perbaikan:

- **Otorisasi setelah validasi (5 route, diperbaiki).** Daftar pengajuan, daftar dan keputusan kiriman digital (M5), simpan jadwal pengumpulan (M4) dan commit dosen massal (M2) menjalankan validasi sebelum memeriksa izin, sehingga pengguna tanpa akses menerima umpan balik validasi alih-alih 403. Tidak ada data yang bocor atau berubah. Kini izin diperiksa lebih dulu. Tes baru `RouteAuthorizationSweepTest` memanggil seluruh 66 route pengelola dengan ID nyata sebagai tamu dan sebagai aslab praktikum lain: semua ditolak, tidak ada data rahasia di respons, dan tidak ada satu baris pun di seluruh tabel yang berubah.
- **File upload lokal ikut masuk image Docker (diperbaiki).** `storage/app/private` tidak dikecualikan, sehingga file uji dari preview ikut tersalin ke image dan dapat mengisi volume `portal-storage` baru. `.dockerignore` kini mengecualikan upload lokal, tests, scripts, design dan berkas compose uji. Image dibangun ulang dan diperiksa: hanya `.gitignore` di storage.
- **Kunci semester pada alur M5 (tes ditambahkan).** Semester terkunci menyembunyikan layanan publik, menolak pengajuan baru dan mengembalikan 423 untuk keputusan pengelola tanpa perubahan data.

Catatan yang tidak diubah:

- Performa: 30–76 query per halaman pengelola (20–40 ms) karena izin dibaca ulang per item menu dan per sesi demi pencabutan izin seketika. Jumlahnya tumbuh dengan jumlah sesi. Bisa dioptimalkan dengan memo per request bila praktikum memiliki banyak sesi.
- **Cloudflare Tunnel di host (diperbaiki dan diverifikasi).** Port Nginx kini default hanya `127.0.0.1` (`APP_BIND`), sehingga VM tidak bisa diakses langsung melewati tunnel/UFW. Network `web` memakai subnet tetap (`WEB_SUBNET`/`WEB_GATEWAY`, default `10.231.0.0/24`/`10.231.0.1`) agar IP proxy tidak berubah setelah `down`/`up`. `.env.example` mengisi `TRUSTED_PROXIES=10.231.0.1`. Diuji melalui stack Docker: pengunjung A dibatasi pada request ke-31, pengunjung B tetap 200, A yang memalsukan `X-Forwarded-For` sebagai B tetap 429, tanpa `TRUSTED_PROXIES` B ikut terblokir, akses dari LAN ke port ditolak, dan `X-Forwarded-Proto: https` menghasilkan URL https. Langkah operator: docs/08_OPERATIONS.md bagian Cloudflare Tunnel.

## UI fidelity pass (setelah M4) — selesai dan diverifikasi lokal

Review menemukan tampilan M0–M4 jauh dari desain pertama. Perubahan berikut hanya menyentuh lapisan tampilan, kecuali dua route baca dan scope dashboard yang dicatat di bawah. Logika tulis M1–M4 tidak berubah.

- Aset lokal baru: Bootstrap Icons 1.13.1 dan font Inter variable, beserta lisensi dan SHA256SUMS (dicek di CI). `public/assets/portal.css` ditulis ulang tanpa minify, memakai token warna docs/04 dan komponen: stat card, status badge (teks + warna), select status berwarna, page header dengan breadcrumb/meta, toolbar, tabel padat.
- Shell pengelola (`App\View\Composers\ManagerShell`): sidebar berikon dengan urutan mockup (Dashboard, Praktikan, Sesi, Pertemuan, Presensi, Pengumpulan, Penilaian, Aturan Nilai, Pengajuan, Rekap) yang selalu tampil untuk praktikum aktif, bukan hanya saat membuka praktikum. Praktikum aktif diambil dari route → sesi → praktikum pertama dan selalu dicek ulang terhadap StaffAccess. Pengajuan/Rekap tampil nonaktif berlabel "Segera", bukan link. Topbar memiliki pemilih Semester · Praktikum (hanya praktikum dalam lingkup), avatar inisial dan peran.
- Dashboard memakai data nyata yang mengikuti scope sesi per izin: praktikan aktif, pengumpulan perlu diperiksa, presensi belum dicatat, jadwal hari ini (WIB) dan daftar tindak lanjut (revisi, keterlambatan pending, pelaksanaan belum dibekukan). `?praktikum=` di luar lingkup ditolak 403.
- Route baru baca saja `GET /praktikum/{offering}/presensi` (attendance.overview): daftar pelaksanaan dalam scope attendance.manage beserta rekap hadir. Halaman presensi kini memiliki breadcrumb, judul "Sesi / Pertemuan" dengan tanggal/jam/ruang, pemilih pelaksanaan lain, aksi Cetak/Unggah di kanan, select status berwarna dan baris padat.
- Pengumpulan dan penerimaan memakai badge status (Diterima, Perlu revisi, Selesai, Belum diterima, Terlambat) dan tombol berikon.
- Beranda publik: hero, empat menu cepat, jadwal terdekat dan daftar modul yang **sudah terbit** melalui scope `MaterialController::published()` yang sama dengan unduhan publik. Login memakai ikon input dan latar dekoratif.
- Teks pagination Laravel diterjemahkan ke Bahasa Indonesia.

### Penyatuan tampilan dan responsif PC/HP (putaran kedua)

- Komponen `<x-page-header>` (breadcrumb, judul, subjudul, meta, aksi) kini dipakai di semua halaman pengelola dan modul publik, menggantikan pola lama "← Kembali" + judul + tombol lepas. Filter memakai `.toolbar` yang seragam.
- Shell: area logo sidebar berupa bar putih setinggi topbar sehingga keduanya terbaca sebagai satu header. Padding transparan bawaan file logo dipotong lewat clipping, tanpa mengubah proporsi. Di PC, sidebar dapat diciutkan menjadi ikon saja (tombol di topbar, diingat per browser). Di tablet/HP, sidebar menjadi off-canvas dengan backdrop, topbar menampilkan logo, avatar dan tombol keluar berikon, dan pemilih praktikum mengambil satu baris penuh.
- Tabel daftar non-roster (dashboard, presensi, tugas, pertemuan, modul, sesi, aslab, master, praktikum) tampil sebagai kartu bertumpuk di HP, dengan label kolom diambil otomatis dari header. Tabel roster (praktikan, presensi, penerimaan, nilai) tetap scroll horizontal dengan NBI/nama sticky sesuai docs/04, dan kini diberi bayangan tepi sebagai petunjuk scroll.
- Kolom presensi diurutkan seperti mockup (No, NBI, Nama, Status, Rekap, Catatan, Sesi) supaya Status langsung terlihat di HP.
- Diverifikasi dengan Playwright pada 1440, 1100, 768 dan 390 px: 15 halaman per ukuran ditambah sidebar ciut (sebelum/sesudah reload) dan menu HP terbuka. Tidak ada overflow body dan tidak ada error JS/console. Screenshot: `artifacts/ui-<halaman>-{desktop,laptop,tablet,mobile}.png`.

Screenshot memakai data contoh di portal_test.

## Hasil pengujian

| Pemeriksaan | Hasil |
|---|---|
| PHPUnit final M0–M6 + pelengkap halaman | **132 tes, 1314 assertion, 0 failure/error** — artifacts/phpunit-m6.xml (termasuk OperationsTest 11 tes dan sapuan otorisasi seluruh route pengelola, kini 80+ route) |
| Kriteria M6 (PHPUnit) | Lulus: kunci semester menghentikan tulis (423) dan buka kembali admin beralasan tercatat; buka koreksi final admin dengan riwayat dan satu arsip aktif di database; pengumuman tersanitasi, draf/arsip/internal tidak publik, umum khusus admin, 409 versi lama; rekap mengikuti scope, CSV/XLSX aman formula, nilai dari snapshot, ekspor diaudit; log read-only, scope logs.view, penyamaran rahasia; backup berhasil hanya lengkap, terenkripsi, gagal tanpa arsip/rahasia, retensi aman, restore terisolasi cocok dan menolak database aplikasi, arsip dimodifikasi ditolak, akses halaman dan antrean tanpa tumpukan, jadwal harian |
| Browser M1–M6 pada image Docker final | Lulus semua; PDF A4 presensi lulus. M6: pengumuman draf 404/terbit/tersanitasi, rekap + unduh CSV/XLSX + PDF A4 landscape, log dan detail tanpa form, backup dari UI selesai oleh container scheduler, kunci semester menutup portal publik dan 423, buka kembali tercatat, HP 390 px — artifacts/browser-m6-results.json, m6-*.png, m6-report-a4.pdf |
| Backup/restore di image produksi | `portal:backup-verify` valid (47 tabel, checksum berkas cocok); `--restore` ke database terisolasi cocok; restore ke database aplikasi ditolak; arsip terenkripsi dapat diekstrak `7z` dengan password |
| PHPUnit setelah review M0–M5 | **117 tes, 1092 assertion, 0 failure/error** — artifacts/phpunit-m5.xml (regresi M1–M4, ShellTest, PublicWorkflowTest 14 tes, RouteAuthorizationSweepTest) |
| Kriteria M5 (PHPUnit) | Lulus: halaman publik tanpa login/roster/draf; error identitas generik tanpa data dibuat; token sekali tampil, hash, tidak di log, token salah/NBI generik; periode tertutup, duplikat, eksekusi lintas sesi; izin/susulan tidak membuat Hadir dan hasil berversi; pindah satu pertemuan dengan kapasitas, tanpa presence ganda, ditolak setelah snapshot; pindah permanen dengan histori dan kapasitas; scope sesi/izin dicabut/login/409/audit tanpa token; remidi menjaga nilai awal, program beku, finalisasi tertahan; kiriman digital, unduh butuh login, revisi token berversi dan periode; executable/oversize/sesi salah ditolak tanpa sisa file; batas laju per NBI dan per IP |
| Browser M1–M5 pada image Docker final setelah review (4 Okt 2026) | Lulus semua: M1, M2, M3, M4, M5; PDF A4 presensi lulus verify-m3-pdf.py. M5: alur publik lewat UI, dua pengelola menyetujui bersamaan pada sesi dengan sisa 1 kursi → tepat satu disetujui, unduh tamu 302, nilai remidi 45 tetap dan hasil 78 terpisah, tanpa error JS, HP 390 px tanpa overflow — artifacts/browser-*-results.json, m5-*.png |
| Pint seluruh repo | Lulus |
| PHPUnit setelah UI fidelity pass | **102 tes, 800 assertion, 0 failure/error** — termasuk ShellTest: scope statistik dashboard, penolakan pemilih praktikum di luar lingkup, overview presensi berizin/scope, menu mengikuti izin, beranda hanya modul terbit |
| Verifikasi ulang M1–M4 setelah perubahan tampilan (3 Okt 2026) | Lulus: image Docker dibangun ulang, stack Nginx/PHP-FPM sehat terhadap portal_test; skrip browser M1, M2, M3, M4 lulus; PDF A4 presensi lulus verify-m3-pdf.py (5 halaman, 67 peserta, header berulang, parity nomor). Bukti di artifacts/ diperbarui |
| Playwright UI fidelity pass | Lulus: 15 halaman × 4 ukuran (1440/1100/768/390 px), sidebar ciut dan menu HP; tanpa overflow body dan tanpa error JS — artifacts/ui-*.png |
| PHPUnit final M4 pada MySQL nyata terisolasi | 97 tes, 761 assertion, 0 failure/error — artifacts/phpunit-m4.xml (termasuk regresi M1–M3) |
| PHPUnit M3, bukti sebelumnya | 71 tes / 442 assertion — artifacts/phpunit-m3.xml |
| PHPUnit M2, bukti sebelumnya | 49 tes / 275 assertion — artifacts/phpunit-m2.xml |
| Login admin/aslab, inactive, generic error, rate limit | Lulus |
| Direct URL/PUT/POST terlarang admin-only | 403, lulus |
| Offering lain, sesi di luar scope, sesi beda offering | 403, lulus |
| Default semua sesi termasuk sesi baru; sensitive default denied | Lulus |
| Explicit deny/grant, revokasi penugasan, scope kosong | Lulus |
| Validasi payload, privilege escalation, audit atomik, reset/revokasi sesi | Lulus |
| Foreign key gabungan mencegah cross-offering scope | Lulus pada MySQL |
| Konflik versi akun | 409 dan tidak overwrite, lulus |
| Playwright M1, bukti verifikasi sebelumnya | Lulus: CSRF real 419, login/logout, buat akun, persistence sesi/izin, admin-only 403, mobile, tanpa exception JS — artifacts/browser-results.json |
| Browser M2 pada image final | Lulus: CRUD master/sesi/praktikan, dosen tepat 3 sasaran, CSV mapping/commit, unduh XLSX, aslab roster/master 403, mobile sticky dan tanpa exception JS — artifacts/browser-m2-results.json |
| Scope roster/impor/ekspor dan bulk | Lulus: sesi luar scope, izin dicabut, stale/replay preview, target filter membeku, default dosen dipertahankan dan rollback batch |
| CSV/XLSX dan integrity | Lulus: leading zero, duplikat, error baris, blank/header berulang, numeric/formula XLSX, actual XLSX upload/commit dan export safe text |
| Kapasitas dan semester locked | Lulus backend; dua request browser admin/aslab bersamaan hanya memasukkan 1 ke sesi kapasitas 1 |
| MySQL membership constraints dan cleanup staging | Lulus: satu membership terbuka, FK offering, expiry prune |
| Snapshot M3 | Lulus: GET tidak mutasi, replay/stale, tanggal efektif/active, arsip nama/metadata/pindah tetap stabil, FK cross-offering dan no duplicate presence |
| Presensi/audit M3 | Lulus: selected-only, koreksi wajib alasan, stale target membatalkan seluruh batch, forged participant 403, audit failure rollback, locked write 423 |
| Berkas/modul M3 | Lulus: private scan + retained files, MIME/extension/size, unauthorized/download deny, rollback cleanup, publish/unpublish/revoked/stale |
| Browser M3 pada image final | Lulus: 67 snapshot, selected-only, koreksi beralasan, dua pengelola concurrent hasil 302/409, scan privat, publikasi/penarikan SQL, desktop/mobile — artifacts/browser-m3-results.json |
| PDF TTD aktual M3 | 67 peserta/5 halaman A4; header setiap halaman, pasangan NBI/nama/nomor TTD satu baris/halaman, odd/even berlanjut, tanpa divider tengah — artifacts/print-m3-results.json |
| Docker/persistensi berkas M3 | Build/health/nginx/migrasi/UID 33 dan checksum file setelah recreate di jaringan tes — artifacts/deployment-m3-results.json |
| Backend M4 | Lulus: scope/revokasi/transfer, periode/late, status/revisi/version, private file rollback, direct/null/zero/range, atomic grade correction, checklist target/stale, draft/publish/final guards, stale preview, final/semester lock dan FK MySQL |
| Browser M4 pada image final | Lulus: tugas/jadwal/penerimaan cetak/checklist16/digital2versi/lab/aturan/final, guest download 302, aslab rules 403, dua koreksi simultan 302/409, desktop/mobile tanpa JS error — artifacts/browser-m4-results.json |
| Docker/persistensi M4 | Build/health/nginx/migrasi/UID 33; dua versi privat dengan checksum cocok setelah recreate — artifacts/deployment-m4-results.json |
| Composer validate --strict, Pint --test, checksum Bootstrap | Lulus |
| Blade compilation dan syntax compiled PHP | Lulus |
| Docker app/Nginx build; nginx -t | Lulus |
| Compose utama/smoke; app/Nginx health, scheduler/worker optional | Lulus di jaringan pengujian saja |
| UID nonroot dan storage setelah recreate app | UID 33, file privat tetap tersedia |
| Dump+storage restore manual M1, bukti sebelumnya | Checksum cocok; 17 tabel, 3 akun, 2 audit cocok pada snapshot; file sampel cocok — artifacts/deployment-results.json |
| Desktop 1440 dan mobile 390 | Screenshot aktual ditinjau; tanpa overflow body; tabel boleh scroll horizontal |
| PDF layout dasar A4 portrait | MediaBox sekitar 595 × 842 pt (A4); artifacts/aslab-a4.pdf |

Database tes `portal_test` berada dalam Compose terpisah, tmpfs dan network `portal-praktikum-isolated-test`. TestCase menolak database/host lain sebelum RefreshDatabase. Tidak memakai SQLite, tidak melakukan operasi terhadap Lab produksi. Kredensial uji dibuat acak dalam env yang diabaikan Git, bukan .env.example.

Temuan yang sudah diperbaiki: panjang nama FK MySQL M3, overflow label aksesibilitas tabel presensi, pengiriman action submitter saat loading, sintaks JSON Blade, header/lebar kolom sticky mobile, pemisahan directive Blade, escaping $ pada healthcheck Compose, jaringan web agar published port Nginx bekerja, tombol tutup sidebar mobile, serta cache Blade pada volume persisten setelah update image. Entrypoint membersihkan hanya cache tampilan sebelum FPM berjalan.

## Keterbatasan dan pekerjaan berikutnya

- UI: tata letak isi formulir (edit praktikan, aslab, tugas, nilai, aturan) belum disesuaikan satu per satu dengan crop mockup; header dan gaya sudah seragam.
- M4: Aturan resmi belum tersedia; QA memakai bobot/ambang berlabel Data contoh. Finalisasi hanya dapat dibuka kembali oleh admin (M6); konfigurasi bersama terkunci sejak final pertama. Penguncian ini khusus nilai/pengumpulan, bukan seluruh data akademik. Jadwal terpakai/tugas tidak memiliki alur hapus atau migrasi konfigurasi historis melalui UI.
- Praktikan tetap tanpa akun. Produksi kosong setelah migrate/bootstrap; seeder guarded hanya portal_test. Materi dan jadwal contoh bukan syllabus/jadwal resmi SBD.
- Snapshot M3 permanen tanpa refresh/delete/add/remove peserta lewat UI. Pindah satu pertemuan hanya dapat disetujui sebelum snapshot pertemuan asal/tujuan dibekukan; setelah itu gunakan susulan. Status active enrollment diperiksa saat capture.
- Pelaksanaan yang sudah snapshot tidak dapat dipindah/edit jadwal. Belum ada UI pembatalan/reschedule beralasan untuk snapshot. Pertemuan/modul berlaku bersama sehingga perubahan memerlukan materials.manage seluruh sesi, aslab restricted hanya mengatur jadwal/read materi sesuai scope.
- Cetak HTML khusus + browser Save PDF, belum generator PDF server. QA 67 peserta/5 halaman tidak menjamin semua printer; operator memilih A4 portrait/100% dan mematikan header/footer. Materi revisi dibuat sebagai draf baru; belum aggregate revision numbering atau publikasi terjadwal.
- Upload M3 maksimal 10 MB, dapat diturunkan via env; tanpa antivirus/CDR. PDF/DOCX/ZIP/TXT/SQL modul dan PDF/JPG/PNG scan diunduh sebagai attachment, tidak dieksekusi/render inline. Modul yang telah diunduh tidak dapat ditarik dari perangkat pihak lain.
- M2: NBI existing tidak diubah lewat UI; rename master bersama hanya admin; master dosen tambah/pilih/bulk tanpa layar edit/nonaktif terpisah. Impor CSV UTF-8 koma dan XLSX sheet pertama 5 MB/2000 baris/30 kolom, tidak XLS/XLSM/ODS atau partial commit.
- Backup: pemindahan arsip ke NAS/Drive belum otomatis (folder host lokal, disalin operator); BACKUP_ENABLED=false sampai operator menyiapkan folder milik UID 33. Restore produksi manual sesuai docs/08. Dump logis cocok untuk ukuran database praktikum; untuk database sangat besar pertimbangkan mysqldump terjadwal di host.
- Tidak ada reset email/admin management lanjutan/MFA. Admin pertama CLI; aslab direset admin. CI disediakan dan checks lokal lulus, belum run GitHub; workspace belum menjadi repo Git.
- M6: rekap menampilkan maksimal 300 baris di halaman (ekspor lengkap). Filter sesi pada log mencocokkan entitas sesi dan field session_id di data sebelum/sesudah, sehingga log tanpa referensi sesi tidak tersaring. Pengumuman tanpa lampiran berkas dan tanpa penjadwalan terbit.
- M5: hasil remidi tidak otomatis dipakai finalisasi karena aturan remidi SBD belum ditetapkan; kebijakan penggabungan menunggu ketentuan resmi. Hasil susulan disimpan di riwayat pengajuan dan tidak mengubah presensi pertemuan asal. Kiriman digital duplikat untuk tugas yang sudah diterima harus ditolak manual. Batas laju bergantung pada IP asli; isi TRUSTED_PROXIES bila memakai proxy. Tidak ada notifikasi email/WA; praktikan memantau lewat Cek Status.
- Produksi/domain/proxy/jaringan eksternal tidak diakses atau diverifikasi. Operator mengisi kredensial sendiri dan meninjau migrasi/backup sebelum deploy. Container dan volume khusus tes ditutup setelah bukti disimpan.

Langkah berikutnya: jadikan workspace repo Git dan simpan kondisi M0–M6; jalankan CI di GitHub; deploy ke VM mengikuti README dan docs/08 (TRUSTED_PROXIES, APP_PORT 8095, folder backup UID 33); isi aturan nilai, jadwal dan modul resmi SBD saat tersedia.

## Portal multi-praktikum — selesai dan diverifikasi di QA terisolasi (7 Oktober 2026)

- Beranda `/` memilih praktikum; portal per praktikum di `/{slug}` dengan identitas, palet, sampul dan layanan sendiri. Registri slug tunggal `practicum_slugs` (aktif + alias, unik, satu slug aktif per praktikum lewat kolom generated unik). Alias: GET/HEAD 301, POST diproses untuk praktikum yang sama.
- Pelaksanaan aktif publik: Aktif + semester tidak terkunci, urut tanggal mulai semester lalu ID. Offering dari URL menjadi acuan; `offering_id` kiriman dicocokkan dan diperiksa ulang di dalam transaksi. Form yang dikirim setelah periode berganti ditolak dengan isian tetap.
- Layanan nonaktif ditolak untuk GET dan POST (termasuk endpoint lama). Modul/pengumuman lewat ID hanya untuk portal pemiliknya.
- Tampilan Portal dengan izin `portal.manage` (default deny, wajib seluruh sesi), versi/409, audit, slug khusus admin dengan alasan, sampul ≤1 MB 600×300–2400×1200 disimpan ulang WebP lewat GD, QR lokal (qrcode-generator 1.4.4, SHA256SUMS).
- Aturan konfirmasi tiga tingkat diterapkan di controller dan form (input pertama Simpan; berdampak pada praktikan centang; koreksi krusial alasan).
- Bug yang ditemukan oleh tes baru dan sudah diperbaiki: simpan Tampilan Portal mematikan semua layanan (operator `+` pada array) dan error 500 bila kolom opsional kosong; edit semester/sesi menulis kolom `confirmed` ke tabel; `nullable|accepted` tetap mewajibkan centang.
- Bukti: PHPUnit 147 tes / 1548 asersi (132 baseline + 13 MultiPracticumTest + tingkat konfirmasi + backfill migrasi). Browser: M1–M6 disesuaikan ke URL `/{slug}` dan `browser-multi-check.cjs` (dua praktikum beda warna, isolasi, layanan nonaktif, balapan slug dua admin nyata di MySQL, slug lama GET/POST, 390/1024/1280/1440 px, tanpa error JS).

## Cek Status lanjutan — diverifikasi di QA terisolasi (8 Oktober 2026)

- Simpan token di perangkat (opt-in, default nonaktif, diingat setelah dipilih; hapus per item; hapus semua juga menghapus preferensi), pop-up rincian lewat `POST /cek-status/rincian` dan `POST /cek-status/semua` (maks. 30), catatan untuk praktikan (`student_note`) terpisah dari alasan audit, `public_deliveries.decided_at`, token pengganti oleh aslab dengan verifikasi identitas dan alasan.
- Bug ditemukan dan diperbaiki: menerima kiriman digital tanpa catatan menghasilkan error 500 (sisa perubahan aturan konfirmasi).
- Bukti: PHPUnit 153 tes / 1712 asersi (termasuk `StatusDetailTest`), browser 9 skrip termasuk `browser-status-check.cjs`.

## Persiapan VPS + Nginx Proxy Manager — diverifikasi di QA terisolasi (9 Oktober 2026)

- `compose.npm.yaml`: Nginx bergabung ke network Docker NPM dengan alias `portal-praktikum`; diaktifkan lewat `COMPOSE_FILE=compose.yaml:compose.npm.yaml` dan `NPM_NETWORK`. `TRUSTED_PROXIES` wajib IP container NPM persis: QA membuktikan bahwa mempercayai seluruh subnet (termasuk gateway) membuat `X-Forwarded-For` palsu melewati batas laju.
- Batas laju portal publik diatur di `config/portal.php` / `.env` (`PORTAL_SUBMIT_PER_IP` 300, `PORTAL_SUBMIT_PER_IDENTITY` 6, `PORTAL_STATUS_PER_IP` 120, `PORTAL_DOWNLOAD_PER_IP` 300) karena satu lab berbagi IP publik. Unduh modul memakai limiter bernama `public-download`.
- Pool PHP-FPM `docker/php-fpm-pool.conf` (sebelumnya default 5 worker): default 16, diatur `FPM_MAX_CHILDREN` dkk., `pm.max_requests` 500, `request_terminate_timeout` 120 s.
- Bukti: PHPUnit 153 tes / 1718 asersi, Pint lulus. E2E dengan compose.yaml + compose.npm.yaml di belakang proxy ber-IP statis: halaman publik/login/health 200, link dan cookie `https`/`secure`, FPM membaca 12 dari `.env`, unduh 70×/menit dari satu IP lolos, Cek Status mengikuti limit `.env` dan XFF palsu tetap 429. Beban 300 serentak: 0 gagal, ±210 req/detik, p95 ±1,5 s, log app tanpa error.
- Panduan pindah ke VPS (backup → restore, NPM, DNS only): [docs/15_VPS_NPM.md](docs/15_VPS_NPM.md).

## Backup berpemicu perubahan + Rclone (9 Oktober 2026)

- `BACKUP_CHANGE_THRESHOLD` (0 = mati) dan `BACKUP_CHANGE_MIN_INTERVAL` (menit, min. 5, default 60): scheduler mengantrekan backup berpemicu `changes` bila baris Log Aktivitas (tanpa `backup_run`) sejak backup berhasil terakhir ≥ ambang, tidak ada run aktif, dan jeda sejak run terakhir sudah lewat. Halaman Backup menampilkan ambang dan jumlah perubahan tertunda. Migrasi `2026_10_09` menambah index `activity_logs.created_at`.
- Salinan cloud memakai Rclone di host (bukan container), `copy` + retensi cloud yang hanya berjalan bila ada unggahan baru: [docs/16_BACKUP_RCLONE.md](docs/16_BACKUP_RCLONE.md).
- Bukti: PHPUnit 154 tes / 1729 asersi (tes baru: ambang, pengecualian backup_run, jeda minimal, eksekusi lewat scheduler). Skrip Rclone diuji dengan remote lokal (rclone 1.68.2): hanya arsip final + .sha256 tersalin, arsip <2 menit/.partial/.work diabaikan, retensi 90 hari menghapus salinan lama, run ulang tanpa perubahan.
