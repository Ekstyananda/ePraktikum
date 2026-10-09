# Alur operasional
## Persiapan
Buat semester > offering SBD > jadwal sesi dan aslab > impor praktikan pratinjau > set pertemuan (awal 5) > terbitkan modul/tugas. Offering adalah pelaksanaan satu praktikum pada satu semester.

## Tugas pertemuan
| Pertemuan | Cetak diterima | Diperiksa langsung |
|---|---|---|
|1|Pendahuluan 1 + daftar pustaka|Praktik Lab 1|
|2|Pendahuluan 2 + daftar pustaka; Aktivitas 1|Praktik Lab 2|
|3|Pendahuluan 3 + daftar pustaka; Aktivitas 2|Praktik Lab 3|
|4|Pendahuluan 4 + daftar pustaka; Aktivitas 3|Praktik Lab 4|
|5|Pendahuluan 5 + daftar pustaka; Aktivitas 4|Praktik Lab 5|
Aktivitas 5 masuk laporan akhir setelah seluruh praktikum selesai. Tugas punya pertemuan asal dan jadwal pengumpulan terpisah. Penerimaan Aktivitas 1 pada pertemuan 2 tidak mengubah asalnya.

## Penerimaan vs penilaian
Cetak: belum diterima > diterima > perlu revisi > revisi diterima > selesai. Nilai/status pemeriksaan terpisah. Simpan waktu penerimaan sebenarnya dan waktu input aslab, plus penerima. Dokumen cetak tidak wajib ada attachment. Digital: submitted/versioned/revision requested/revised; versi sebelumnya tidak hilang. Lab: belum diperiksa > sudah dinilai, tidak wajib submission/file.
Laporan akhir cetak: Cover lalu Pendahuluan 1/Daftar Pustaka/Aktivitas 1 hingga bagian 5. Checklist per laporan, bukan checklist global. Nilai Aktivitas 5 dan nilai laporan akhir hanya aktif terpisah bila ketentuan menetapkan keduanya; cegah bobot ganda yang tidak disengaja.

## Presensi dan koreksi
Snapshot peserta saat pelaksanaan/cetak diperlukan agar pindah sesi tidak mengubah arsip. Rekap berdasarkan TTD. Input awal tanpa alasan; koreksi nilai/presensi/keputusan tersimpan wajib alasan, old/new actor timestamp. Log immutable lewat UI. Untuk concurrent edit gunakan version/updated_at dan konflik yang terlihat, jangan silently overwrite.

## Form publik
Isi NBI nama sesi dan bidang relevan. Cocokkan terhadap enrollment tanpa menyediakan endpoint enumerasi nama mahasiswa berdasarkan NBI. Pencocokan bukan autentikasi; kiriman berstatus menunggu pemeriksaan. Token bukti acak kuat, simpan hash, jangan kode sequential sebagai kunci akses. Halaman bukti beri opsi salin/cetak; jangan log token mentah. Cek status menampilkan status minimum, tidak membocorkan grade/roster/file privat. Versi ulang melalui token hanya saat periode mengizinkan, tidak mengganti kiriman orang lain dengan NBI.
Rate limit, validasi payload, CSRF, size MIME extension restrictions, private upload nonexecuting. Form tertutup jika belum buka/sudah tutup; allowance terlambat dikonfigurasi aslab. Default proposal 10MB/file editable.

## Pindah sesi
temporary: satu session_meeting tujuan, session_meeting asal, tidak ubah default membership. permanent: tanggal efektif, tutup membership lama dan buat baru tanpa ubah histori. Scope offering sama. Validasi kapasitas tujuan dengan transaksi/locking pada saat approval agar 2 approver tidak oversubscribe. Mahasiswa temporary tidak muncul dua kali sebagai attendance aktif per meeting. Approval izin/susulan tidak otomatis Hadir. Catat pelaksanaan susulan dan hasil aktual.

## Remidi dan akhir semester
Pengelola menetapkan kelayakan sesuai aturan, jadwal, instruksi, submit bila diperlukan. Nilai awal immutable history, hasil perbaikan disimpan terpisah. Finalisasi memerlukan rules valid dan seluruh kewajiban telah ditangani termasuk missing yang diputuskan eksplisit. Blank bukan zero. Arsip read-only; admin membuka koreksi dengan alasan dan log. Ekspor nilai berdasarkan config yang berlaku saat finalisasi.

## Penetapan dosen pembimbing massal
Data Praktikan > filter sesi/kelas/status dosen > pilih beberapa praktikan > Tetapkan Dosen Pembimbing > pilih dosen > pratinjau jumlah dan nama sasaran > Simpan. Satu dosen untuk seluruh praktikan terpilih. Default hanya mengisi yang belum ditentukan; mengganti dosen yang sudah terisi harus dipilih eksplisit, dikonfirmasi dan diberi alasan koreksi. Jangan mengubah mahasiswa di luar seleksi/scope. Pilih semua halaman harus eksplisit membedakan halaman aktif vs seluruh hasil filter dan tampilkan jumlah total. Backend validasi setiap enrollment dan permission, transaction all-or-nothing, log old/new per praktikan dengan batch identifier. Menambahkan nama dosen tersedia untuk pengelola berizin, dengan cegah duplikasi dan gunakan nama master yang sama.
