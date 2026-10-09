@extends('layouts.app')
@section('title', 'Laporan Akhir — Portal Praktikum')
@section('context', $o->semester.' · '.$o->name)
@section('content')
@php $tones = ['received' => 'ok', 'submitted' => 'info', 'revision_requested' => 'warn', 'revised' => 'info', 'complete' => 'ok', 'missing_decided' => 'danger']; @endphp
<x-page-header title="Laporan Akhir" subtitle="Penerimaan laporan akhir per praktikan dan checklist bagian laporan (Cover, Pendahuluan, Daftar Pustaka dan Aktivitas 1–5)." :crumbs="['Pengumpulan' => route('collections.index', $o->id), 'Laporan akhir' => null]" />
@if(!$assignment)
<section class="card"><div class="empty"><i class="bi bi-journal-x fs-3 d-block mb-2" aria-hidden="true"></i>Belum ada tugas bertipe Laporan akhir. Buat di <a href="{{ route('assignments.index', $o->id) }}">Kelola tugas & jadwal</a> dengan jenis "Laporan akhir".</div></section>
@else
<form method="get" class="toolbar">
    @if($finals->count() > 1)<label class="visually-hidden" for="assignment_id">Tugas</label><select id="assignment_id" name="assignment_id" class="form-select">@foreach($finals as $f)<option value="{{ $f->id }}" @selected($assignment->id === $f->id)>{{ $f->title }}</option>@endforeach</select>@endif
    <label class="visually-hidden" for="session_id">Sesi</label>
    <select id="session_id" name="session_id" class="form-select"><option value="">Semua sesi dalam lingkup</option>@foreach($sessions as $s)<option value="{{ $s->id }}" @selected((int) request('session_id') === $s->id)>{{ $s->label }}</option>@endforeach</select>
    <label class="visually-hidden" for="q">Cari NBI/nama</label><input id="q" name="q" class="form-control" value="{{ request('q') }}" placeholder="Cari NBI/nama">
    <x-per-page />
    <button class="btn btn-outline-primary"><i class="bi bi-funnel" aria-hidden="true"></i> Tampilkan</button>
</form>
<section class="card"><div class="table-responsive"><table class="table table-compact roster-table">
    <thead><tr><th>No</th><th class="sticky-nbi">NBI</th><th class="sticky-student">Nama</th><th>Sesi</th><th>Tanggal terima · WIB</th><th>Status</th><th>Checklist</th></tr></thead>
    <tbody>
    @forelse($rows as $row)
    @php $done = (int) $row->checked; $complete = $items && $done >= $items; @endphp
    <tr>
        <td>{{ $rows->firstItem() + $loop->index }}</td>
        <td class="sticky-nbi">{{ $row->nbi }}</td>
        <td class="sticky-student"><a href="{{ route('submissions.form', [$o->id, $assignment->id, $row->id]) }}">{{ $row->name }}</a></td>
        <td>{{ $row->session_label }}</td>
        <td>{{ $row->received_at ? \Carbon\CarbonImmutable::parse($row->received_at, 'UTC')->setTimezone('Asia/Jakarta')->format('d/m/Y H:i') : '—' }}</td>
        <td><span class="status-badge status-{{ $tones[$row->status] ?? 'danger' }}">{{ $states[$row->status] ?? 'Belum diterima' }}</span>@if($row->is_late) <span class="status-badge status-warn">Terlambat</span>@endif</td>
        <td>@if($row->status)<span class="status-badge status-{{ $complete ? 'ok' : 'neutral' }}">{{ $done }}/{{ $items }} bagian</span>@else<span class="text-secondary">—</span>@endif</td>
    </tr>
    @empty
    <tr><td colspan="7" class="empty">Tidak ada praktikan sesuai filter dalam lingkup Anda.</td></tr>
    @endforelse
    </tbody>
</table></div></section>
<div class="mt-3">{{ $rows->links() }}</div>
@endif
@endsection
