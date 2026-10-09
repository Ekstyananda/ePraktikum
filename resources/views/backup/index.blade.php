@extends('layouts.app')
@section('title', 'Backup — Portal Praktikum')
@section('content')
@php $w = fn ($t) => \App\Services\Backup::wib($t); $tones = ['success' => 'ok', 'failed' => 'danger', 'running' => 'info', 'queued' => 'warn']; $labels = ['success' => 'Berhasil', 'failed' => 'Gagal', 'running' => 'Berjalan', 'queued' => 'Antre']; @endphp
@if($active)<meta http-equiv="refresh" content="15">@endif
<x-page-header title="Backup" subtitle="Backup database Lab dan berkas privat dalam satu arsip ber-checksum. Berhasil hanya bila dump dan seluruh berkas tersimpan." :crumbs="['Pengaturan' => route('settings.index'), 'Backup' => null]">
    <x-slot:actions>
        <form method="post" action="{{ route('backup.run') }}" data-confirm="Jalankan backup sekarang? Proses berjalan di latar belakang.">@csrf<input type="hidden" name="confirmed" value="1"><button class="btn btn-primary" @disabled($problems || $active)><i class="bi bi-cloud-arrow-up" aria-hidden="true"></i> Jalankan Backup</button></form>
    </x-slot:actions>
</x-page-header>
@if($problems)<div class="alert alert-warning" role="alert"><strong>Backup belum dapat dijalankan.</strong> {{ implode(' ', $problems) }} Lihat docs/08_OPERATIONS.md bagian Backup.</div>@endif
@if($active)<div class="alert alert-info" role="status"><i class="bi bi-arrow-repeat" aria-hidden="true"></i> Backup sedang diantrekan/berjalan. Halaman diperbarui otomatis.</div>@endif
<div class="row g-3 mb-3">
    <div class="col-sm-6 col-xl-3"><section class="card stat-card"><div class="card-body d-flex flex-column"><span class="stat-icon {{ $lastSuccess ? 'green' : 'orange' }}"><i class="bi bi-shield-check" aria-hidden="true"></i></span><div class="stat-label mt-0">Backup berhasil terakhir</div><div class="fw-bold">{{ $lastSuccess ? $w($lastSuccess->finished_at).' WIB' : 'Belum ada' }}</div></div></section></div>
    <div class="col-sm-6 col-xl-3"><section class="card stat-card"><div class="card-body d-flex flex-column"><span class="stat-icon {{ $enabled ? 'blue' : 'orange' }}"><i class="bi bi-calendar-check" aria-hidden="true"></i></span><div class="stat-label mt-0">Jadwal harian</div><div class="fw-bold">{{ $enabled ? $time.' WIB' : 'Nonaktif (BACKUP_ENABLED=false)' }}</div>@if($enabled && $changeThreshold)<div class="small text-secondary">+ tiap {{ $changeThreshold }} perubahan (jeda min. {{ $changeInterval }} menit) · {{ $pendingChanges }} perubahan belum dibackup</div>@endif</div></section></div>
    <div class="col-sm-6 col-xl-3"><section class="card stat-card"><div class="card-body d-flex flex-column"><span class="stat-icon cyan"><i class="bi bi-archive" aria-hidden="true"></i></span><div class="stat-label mt-0">Retensi</div><div class="fw-bold">{{ $retention }} arsip terbaru</div></div></section></div>
    <div class="col-sm-6 col-xl-3"><section class="card stat-card"><div class="card-body d-flex flex-column"><span class="stat-icon {{ $encrypted ? 'green' : 'orange' }}"><i class="bi bi-hdd" aria-hidden="true"></i></span><div class="stat-label mt-0">Tujuan</div><div class="fw-bold">{{ $label }}</div><div class="small text-secondary">{{ $encrypted ? 'Arsip terenkripsi AES-256' : 'Tanpa enkripsi — wajib diisi bila tujuan remote' }}</div></div></section></div>
</div>
@if($lastFailure && (!$lastSuccess || $lastFailure->finished_at > $lastSuccess->finished_at))<div class="alert alert-danger" role="alert"><strong>Backup terakhir gagal</strong> ({{ $w($lastFailure->finished_at) }} WIB): {{ $lastFailure->error_summary }}</div>@endif
<section class="card mb-3"><div class="table-responsive"><table class="table table-stack table-compact">
    <thead><tr><th>#</th><th>Status</th><th>Pemicu</th><th>Mulai · WIB</th><th>Selesai</th><th>Ukuran</th><th>Isi</th><th>SHA-256</th><th>Keterangan</th></tr></thead>
    <tbody>
    @forelse($runs as $run)
    @php $m = $run->manifest_json ? json_decode($run->manifest_json, true) : null; @endphp
    <tr>
        <td>{{ $run->id }}</td>
        <td><span class="status-badge status-{{ $tones[$run->status] ?? 'neutral' }}">{{ $labels[$run->status] ?? $run->status }}</span></td>
        <td>{{ ['manual' => 'Manual', 'scheduled' => 'Terjadwal', 'changes' => 'Perubahan data', 'cli' => 'CLI'][$run->trigger] ?? $run->trigger }}@if($run->initiator)<div class="small text-secondary">{{ $run->initiator }}</div>@endif</td>
        <td>{{ $w($run->started_at ?? $run->queued_at) }}</td>
        <td>{{ $w($run->finished_at) }}</td>
        <td>{{ $run->size ? number_format($run->size / 1048576, 2).' MB' : '—' }}</td>
        <td>@if($m){{ $m['tables'] }} tabel · {{ $m['rows'] }} baris · {{ $m['files'] }} berkas @else — @endif</td>
        <td>@if($run->checksum)<code title="{{ $run->checksum }}">{{ substr($run->checksum, 0, 12) }}…</code>@else — @endif</td>
        <td class="small">@if($run->status === 'failed'){{ $run->error_summary }}@elseif($run->pruned_at)Dihapus oleh retensi @elseif($run->artifact_ref){{ $run->artifact_ref }}@endif</td>
    </tr>
    @empty
    <tr><td colspan="9" class="empty">Belum ada riwayat backup.</td></tr>
    @endforelse
    </tbody>
</table></div></section>
<div class="mb-3">{{ $runs->links() }}</div>
<div class="row g-3">
    @if(auth()->user()->role === 'admin')
    <div class="col-lg-6"><form method="post" action="{{ route('backup.settings') }}" class="card h-100" data-dirty-form data-confirm="Simpan jadwal dan retensi backup?">@csrf<div class="card-body">
        <h2>Jadwal & retensi</h2><input type="hidden" name="version" value="{{ $settingsVersion }}">
        <div class="row g-3"><div class="col-sm-6"><label class="form-label" for="time">Jam harian (WIB)</label><input type="time" id="time" name="time" class="form-control" required value="{{ old('time', $time) }}"></div><div class="col-sm-6"><label class="form-label" for="retention">Simpan arsip terbaru</label><input type="number" id="retention" name="retention" min="1" max="90" class="form-control" required value="{{ old('retention', $retention) }}"></div>
        <div class="col-12"><label class="form-label" for="reason">Catatan (opsional)</label><input id="reason" name="reason" class="form-control" maxlength="1000"></div></div>
        <label class="d-block my-3"><input type="checkbox" name="confirmed" value="1" required> Konfirmasi</label><button class="btn btn-outline-primary">Simpan</button>
        <p class="small text-secondary mt-2 mb-0">Usulan awal: harian 02:00 WIB, 7 arsip. Sesuaikan dengan kebijakan Lab.</p>
    </div></form></div>
    @endif
    <div class="col-lg-6"><section class="card h-100"><div class="card-body">
        <h2>Restore</h2>
        <p class="small">Restore tidak tersedia sebagai tombol. Uji restore ke database terisolasi, tinjau hasilnya, baru lakukan restore produksi sesuai prosedur:</p>
        <pre class="small bg-light p-2 rounded mb-2">docker compose exec app php artisan portal:backup-verify &lt;arsip&gt; --restore</pre>
        <p class="small text-secondary mb-0">Prosedur lengkap: docs/08_OPERATIONS.md bagian Backup dan restore.</p>
    </div></section></div>
</div>
@endsection
