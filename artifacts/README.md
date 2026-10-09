# Bukti verifikasi aktual M0–M6

- phpunit.xml: 23 tes / 128 assertion, MySQL terisolasi.
- browser-results.json: browser smoke melalui Nginx/PHP-FPM Docker final.
- deployment-results.json: build, health, nonroot, persistensi, restore manual terisolasi.
- print-results.json dan aslab-a4.pdf: ukuran A4 layout dasar, bukan presensi M3.
- public/login/dashboard/aslab PNG: screenshot implementasi desktop 1440 px dan mobile 390 px.

Semua akun/praktikum pada screenshot berlabel Data contoh. Tidak ada data produksi, password atau dump dalam direktori ini. Berkas dump/arsip uji hanya dibuat di /tmp, tidak dikomit.

Bukti tambahan M2:

- phpunit-m2.xml: 49 tes / 275 assertion, termasuk regresi M1 dan otorisasi roster/impor/bulk/export.
- browser-m2-results.json: CRUD master, sesi dan praktikan; dosen massal; impor CSV; unduh XLSX; otorisasi aslab dan kapasitas sesi pada dua request bersamaan.
- deployment-m2-results.json: build image final, health, migrasi dan scheduler di lingkungan terisolasi.
- m2-*.png: screenshot desktop/mobile aktual, termasuk NBI/nama sticky setelah scroll horizontal.
- m2-export-example.xlsx: ekspor data contoh buatan melalui UI pengujian.

Bukti restore/PDF M1 adalah verifikasi terdahulu, bukan pengujian restore skema M2 atau penerimaan presensi M3.

Bukti tambahan M3:

- phpunit-m3.xml: 71 tes / 442 assertion, termasuk regresi M1–M2 dan integrasi snapshot/attendance/files/materials.
- browser-m3-results.json: alur UI melalui image final dan konflik admin/aslab simultan 302/409.
- print-m3-results.json, m3-attendance-a4.pdf dan m3-pdf-page-*.png: 67 peserta/5 halaman A4 aktual, header repeat, row stable dan TTD alternating.
- m3-*.png: halaman pertemuan, snapshot, presensi desktop/mobile, modul published dan katalog publik.
- deployment-m3-results.json dan private-files-m3-before/after.json: deployment test, UID 33, checksum/persistensi file setelah recreate.

Seluruh nama/roster/modul M3 berlabel Data contoh dan buatan pengujian. Tidak ada syllabus/nilai/presensi resmi produksi. Persistensi M3 bukan klaim restore seluruh skema atau backup aplikasi M6.

Bukti tambahan M4:

- phpunit-m4.xml: 97 tes / 761 assertion, termasuk regresi M1–M3 dan otorisasi pengumpulan/penilaian/finalisasi.
- browser-m4-results.json: alur UI tugas hingga finalisasi, berkas digital berversi privat, aslab aturan 403, konflik simultan 302/409 dan desktop/mobile.
- m4-*.png: screenshot aktual tugas, jadwal, penerimaan, checklist, revisi digital, nilai lab, aturan dan arsip final.
- deployment-m4-results.json dan private-files-m4-before/after.json: UID 33, delapan migrasi, health/Nginx dan persistensi dua versi file privat setelah recreate.

Aturan dan nilai M4 hanya data QA, bukan ketentuan resmi SBD. Bukti persistensi bukan restore seluruh skema M4. Skrip reproduksi UI ada pada scripts/browser-m4-check.cjs; gunakan hanya database pengujian yang baru di-seed.

Bukti tambahan tampilan dan M5 (4 Oktober 2026):

- ui-*.png: 15 halaman pengelola/publik pada 1440, 1100, 768 dan 390 px, termasuk sidebar ciut dan menu HP.
- phpunit-m5.xml: 117 tes / 1092 assertion, termasuk regresi M1–M4, ShellTest, PublicWorkflowTest dan sapuan otorisasi seluruh route pengelola.
- browser-results.json, browser-m2/m3/m4-results.json, print-m3-results.json: skrip M1–M4 dijalankan ulang terhadap image Docker yang dibangun ulang setelah perubahan tampilan dan M5.
- browser-m5-results.json dan m5-*.png: alur publik lewat UI, error identitas generik, token sekali tampil, dua pengelola menyetujui pindah sesi bersamaan (tepat satu disetujui), kiriman digital, remidi, Cek Status dan tampilan HP.

Semua data pada bukti ini berlabel Data contoh.

Bukti M6:

- phpunit-m6.xml: 128 tes / 1240 assertion (seluruh milestone).
- browser-m6-results.json, m6-*.png, m6-report-a4.pdf: pengumuman, rekap dan ekspor, log aktivitas, backup dari UI yang diproses scheduler, kunci/buka semester, tampilan HP.
- Skrip M1–M5 dijalankan ulang pada image yang sama dan lulus.
