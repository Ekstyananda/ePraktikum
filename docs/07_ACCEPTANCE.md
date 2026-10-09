# Kriteria penerimaan
- Login admin/aslab; akun inactive ditolak; direct request terlarang 403 walau URL diketahui. Admin klik akun ubah permission, efek langsung sesuai cache invalidation.
- Aslab default seluruh sesi offering ditugaskan, tidak akses offering lain. Pembatasan sesi diterapkan API/backend juga.
- Impor NBI teks, no duplicate enrollment, preview tanpa mutation, errors row visible; repeated headers/empty Excel tidak menjadi mahasiswa. Export mencegah formula injection.
- Attendance initial belum dicatat; bulk hanya selected; scan privat; perubahan wajib alasan dan log atomik. Conflict dua aslab tidak silent overwrite.
- TTD A4 1/2/3/4 nomor alternating baris, single column, nama lebih lebar, multi-page header dan no split row, archived snapshot stable.
- Penerimaan cetak tanpa file, received_at berbeda recorded_at; late berdasar actual receipt. Lab grade tanpa submission. Blank tidak jadi zero. Aktivitas 1 received meeting 2 tetap origin 1. Aktivitas 5 dalam final report.
- Final grade tanpa rules atau unresolved wajib ditolak; bobot configurable valid, original remedial history preserved, laporan tidak otomatis double-count.
- Public no login; no student list/name lookup enumeration. Secret status cannot read others; logs redact token/credential. Upload rate limit type size nonexecuting private.
- Resubmit hanya authorized secret+period, version preserved. Files cannot download by unauthenticated predictable path. Published modules public by design.
- Temporary transfer satu meeting, permanent effective date; history intact, capacity concurrent tested, no duplicate presence. Izin/susulan bukan auto hadir.
- Semester lock stops write, admin reopen reason logged; readonly audit cannot edited/deleted UI.
- Backup DB+files only successful together; no secret in logs, configurable destination, test restore isolated documented.
- Docker deployment no bundled MySQL, existing network connectivity, volume persistence recreate app, no destructive production migrations.
- All menus functional not dead buttons; empty/error/success/mobile checked. Actual visual screenshots compared to doc-corrected references; data contoh labeled.

- Impor tanpa dosen sukses; tampilan Belum ditentukan. Satu dosen bisa terhubung banyak praktikan. Bulk assignment ke tiga praktikan hanya mengubah yang dipilih; default tidak overwrite existing. Ganti explicit requires reason+confirmation, setiap perubahan tercatat old/new actor batch id. Unauthorized enrollment menggagalkan batch tanpa partial write. Nilai filter/pagination tidak memperluas target diam-diam.
