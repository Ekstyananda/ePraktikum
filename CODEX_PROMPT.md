# Prompt pembangunan
Bangun aplikasi Portal Praktikum berdasarkan seluruh dokumen paket ini. Ini proyek Laravel + MySQL, Blade + Bootstrap, deployment Docker Compose pada Ubuntu VM milik pengguna. Bukan React dan bukan Sites. Pilih versi Laravel/PHP yang masih didukung dan kompatibel setelah memeriksa dokumentasi resmi pada saat pembangunan; pin image dependency dan sertakan lockfile.

Baca README.md, docs/* dan design/INDEX.md. Implementasikan seluruh menu yang dicakup, bertahap M0–M6 dalam docs/06_IMPLEMENTATION.md. Jangan berhenti pada UI statis. Setiap formulir harus memiliki validasi backend, otorisasi dan persistence. Jangan menyebut fitur selesai jika belum teruji. Jika satu sesi pembangunan hanya cukup untuk milestone tertentu, nyatakan scope aktual di BUILD_STATUS.md.

Praktikan tidak memiliki akun/login. Pengelola login. Aslab default mengelola semua sesi praktikum yang ditugaskan. Admin klik nama aslab untuk mengatur izin individual. Server harus menegakkan izin dan scope, bukan hanya menyembunyikan tombol.
Gunakan layout desain pertama. PNG terpisah adalah crop referensi; jangan menyalin kesalahan teks, avatar pada portal publik, bobot atau tanggalnya. Aturan halaman tertulis adalah otoritatif.

Jangan mengakses server pengguna dari workspace ini atau memakai credential chat. Konfigurasi produksi diisi pengguna pada server. Gunakan MySQL nyata untuk pengujian integrasi dalam lingkungan development terisolasi; jangan mengganti DB aplikasi dengan SQLite. Container DB untuk tes boleh terpisah dan tidak masuk Compose deployment utama.
Jangan menjalankan migrate:fresh atau operasi penghapusan database pada server produksi. Dokumentasikan migrasi, backup, restore, trusted proxy dan konfigurasi network eksternal.
Sertakan README install, .env.example tanpa rahasia, dokumentasi operasi, tes kritis, screenshot hasil UI desktop/mobile dan verifikasi cetak A4. Ikuti kriteria penerimaan docs/07_ACCEPTANCE.md.
