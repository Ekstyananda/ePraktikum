# Portal multi-praktikum

Satu situs melayani beberapa praktikum (misalnya SBD dan Pengolahan Citra Digital). Praktikan tetap **tanpa akun**: mereka membuka link praktikum dari aslab atau memilih praktikum di beranda.

## Alamat

| Alamat | Isi |
|---|---|
| `/` | Halaman **Pilih praktikum**: kartu tiap praktikum yang sedang dibuka, pengumuman umum, Cek Status. |
| `/{slug}` | Beranda praktikum: identitas, warna, sampul, layanan aktif, pengumuman praktikum + umum, jadwal dan modul praktikum itu saja. |
| `/{slug}/jadwal`, `/modul`, `/pengumpulan`, `/pengajuan`, `/remidi`, `/pengumuman` | Layanan praktikum. Pelaksanaan (offering) ditentukan dari slug, tanpa dropdown. |
| `/cek-status` | Global; token berlaku untuk praktikum mana pun dan hasilnya menyebut nama praktikum. |
| `/pengumuman`, `/pengumuman/{id}` | Pengumuman umum. Pengumuman praktikum dialihkan ke portal praktikumnya. |

**URL lama** (`/jadwal`, `/modul`, `/pengajuan`, …) tetap bekerja: dialihkan ke `/{slug}/…` bila tepat satu praktikum dibuka, selain itu ke `/`. Link unduhan modul lama dialihkan ke versi berslug. POST lama ke `/pengumpulan` dan `/pengajuan` masih diterima dan diperiksa dengan aturan yang sama.

## Praktikum mana yang tampil

Praktikum tampil bila punya pelaksanaan berstatus **Aktif** pada semester yang **tidak terkunci**. Bila ada lebih dari satu, yang dipakai adalah semester dengan tanggal mulai terbaru, lalu ID pelaksanaan terbesar. Praktikum tanpa pelaksanaan aktif menampilkan halaman "sedang tidak dibuka" (status 404, tanpa form).

## Tampilan Portal (pengelola)

Menu **Tampilan Portal** di sidebar. Admin selalu boleh; aslab memerlukan izin **Kelola tampilan portal praktikum** (`portal.manage`, tidak termasuk preset standar) dengan cakupan **seluruh sesi** pada pelaksanaan praktikum itu.

- Singkatan, nama tampil (kosong = nama master), deskripsi singkat, kontak dan tautan kontak (hanya `https`).
- Warna aksen dari 6 palet (teks dan tombol tetap kontras); tidak ada CSS bebas.
- Sampul: ilustrasi bawaan (basis data, citra, jaringan, kode, sirkuit) atau unggah PNG/JPG/WebP **maks. 1 MB, 600×300 sampai 2400×1200 px**. Gambar dibaca lalu **disimpan ulang sebagai WebP** (metadata dan isi tersisip terbuang); SVG dan berkas lain ditolak.
- Layanan aktif: Jadwal, Modul & Soal, Pengumpulan, Pengajuan, Remidi. Layanan nonaktif hilang dari menu dan **ditolak untuk GET maupun POST** (404, tidak ada data tersimpan).
- Panel **Bagikan**: link portal, tombol salin, kode QR (unduh SVG) untuk grup kelas atau ditempel di lab.
- Pengaturan melekat pada **praktikum**, sehingga berlaku untuk **semua semester**. Tenggat dan periode tugas tetap diatur per pelaksanaan dan sesi.
- Simpan memerlukan centang konfirmasi; versi mencegah dua pengelola saling menimpa (409).

### Alamat (slug), khusus admin

- Huruf kecil, angka dan strip, 2–50 karakter. Kata sistem (`login`, `dashboard`, `pengumuman`, `modul`, …) ditolak.
- Mengubah slug **wajib alasan** (min. 10 karakter) dan tercatat di Log Aktivitas.
- Slug lama menjadi **alias**: GET/HEAD dialihkan 301 ke slug baru (query tetap), POST dari form yang dibuka sebelum perubahan tetap diproses untuk praktikum yang sama. Alias tetap dicadangkan dan tidak bisa dipakai praktikum lain.
- Slug aktif dan alias berada di satu tabel `practicum_slugs` dengan constraint unik, sehingga dua admin yang menyimpan slug sama bersamaan: tepat satu berhasil, yang lain mendapat pesan validasi.

## Isolasi data

Pelaksanaan dari URL adalah acuan server. `offering_id` dari form hanya dicocokkan; bila berbeda ditolak. Sesi, pelaksanaan, jadwal tugas dan program remidi dari praktikum lain ditolak. Modul dan pengumuman yang dibuka langsung lewat ID hanya dilayani bila milik pelaksanaan portal itu (pengumuman umum tetap boleh); selain itu 404.

Bila periode praktikum berganti saat form masih terbuka, pengiriman ditolak dengan pesan "Periode praktikum ini sudah berganti…", isian tetap di form, dan tidak ada data tersimpan ke pelaksanaan lama maupun baru. Pemeriksaan diulang di dalam transaksi penyimpanan.

## Konfirmasi perubahan (tiga tingkat)

| Tingkat | Contoh | Yang diminta |
|---|---|---|
| Input pertama | penerimaan tugas, presensi pertama, nilai pertama, pemeriksaan kiriman diterima, tambah praktikan/sesi/pertemuan | cukup **Simpan** |
| Berdampak pada praktikan | terbit/arsip pengumuman, terbit/tarik modul, Tampilan Portal, periode pengajuan & program remidi, jadwal pengumpulan, ubah sesi/pertemuan/pelaksanaan/praktikan/master, hapus data | **centang konfirmasi**, catatan opsional |
| Koreksi krusial | koreksi presensi/nilai/penerimaan/checklist yang sudah tersimpan, keputusan nol, tolak kiriman, tolak pengajuan, hasil susulan/remidi, publikasi aturan nilai, buka koreksi final, kunci/buka semester, izin/akun aslab, ganti dosen massal mode replace, ubah slug | **alasan wajib** |

Bila suatu aksi masuk dua tingkat, aturan krusial yang berlaku. Semua perubahan tetap tercatat di audit.

## Pembaruan dari versi satu praktikum

Migrasi `2026_10_07_000000_add_portal_identity_to_practicums` hanya menambah kolom dan tabel. Setiap praktikum yang ada otomatis mendapat slug dari **kode** praktikum (misalnya `SBD` → `/sbd`). Kode kosong, terlalu panjang atau berupa kata sistem memakai `praktikum-<id>`; slug bentrok diberi akhiran `-2`, `-3`. Setelah deploy, admin membuka **Tampilan Portal** untuk mengisi identitas dan, bila perlu, mengganti slug.

Image aplikasi kini memuat ekstensi PHP **GD** (JPEG/WebP) untuk memproses sampul, jadi image perlu dibangun ulang.

## Cek Status: token di perangkat, rincian, token pengganti

**Untuk praktikan**
- Di bukti kiriman ada centang **"Simpan token di perangkat ini"**, default **tidak aktif**. Bila dicentang, token disimpan hanya di browser itu dan pilihan ini diingat untuk kiriman berikutnya. Jangan dipakai di komputer lab/umum.
- `/cek-status` menampilkan **Kiriman tersimpan di perangkat ini** dengan tombol Cek, Salin, Hapus per item, **Cek semua**, dan **Hapus semua** (menghapus daftar sekaligus pilihan "ingat", sehingga perangkat kembali tidak menyimpan otomatis).
- Hasil cek tampil sebagai **pop-up rincian**: praktikum, jenis, tugas/pertemuan/sesi, tenggat, jenis berkas ("Berkas kiriman PDF", bukan nama berkas asli), tahapan dengan waktu yang memang tercatat, catatan aslab, dan langkah berikutnya. Tidak ada nilai, nama, NBI, atau berkas.
- Token yang salah, tidak dikenal, atau sudah diganti mendapat jawaban yang sama: "Bukti tidak ditemukan".

**Untuk aslab**
- **Catatan untuk praktikan** adalah kolom terpisah di keputusan pengajuan, hasil susulan/remidi, pemeriksaan kiriman digital, dan penerimaan. Hanya kolom ini yang terlihat praktikan; alasan audit dan catatan internal tidak pernah ditampilkan. Jangan tulis nama, NBI, atau nilai.
- **Token hilang**: di detail pengajuan ("Praktikan kehilangan token?") atau di Kiriman digital ("Token hilang?"). Cocokkan identitas praktikan dulu (tatap muka atau kanal pribadi yang sudah dikenal), pilih cara verifikasi, isi alasan, centang pernyataan. Token lama langsung mati; token baru tampil **sekali** dan hanya diberikan kepada pemiliknya. Tercatat di Log Aktivitas sebagai `token.reissued` tanpa tokennya.
- Token tidak pernah masuk URL, log, audit, atau session.
