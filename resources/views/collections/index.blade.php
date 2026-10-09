@extends('layouts.app')
@section('title', 'Pengumpulan — Portal Praktikum')
@section('context', $o->semester.' · '.$o->name)
@section('content')
@php
$tones = ['received' => 'ok', 'submitted' => 'info', 'revision_requested' => 'warn', 'revised' => 'info', 'complete' => 'ok', 'missing_decided' => 'danger'];
$base = array_filter(['meeting_id' => $meeting?->id, 'session_id' => request('session_id'), 'per_page' => request('per_page')]);
@endphp
<x-page-header title="Pengumpulan" subtitle="Penerimaan per pertemuan sesuai jadwal pengumpulan setiap sesi." :crumbs="['Dashboard' => route('dashboard'), 'Pengumpulan' => null]">
    <x-slot:actions>
        <a class="btn btn-outline-primary" href="{{ route('final-reports.index', $o->id) }}"><i class="bi bi-journal-check" aria-hidden="true"></i> Laporan akhir</a>
        <a class="btn btn-outline-primary" href="{{ route('deliveries.index', $o->id) }}"><i class="bi bi-cloud-download" aria-hidden="true"></i> Kiriman digital</a>
        <a class="btn btn-outline-primary" href="{{ route('assignments.index', $o->id) }}"><i class="bi bi-list-task" aria-hidden="true"></i> Kelola tugas & jadwal</a>
    </x-slot:actions>
</x-page-header>
<nav class="nav nav-tabs mb-3" aria-label="Jenis pengumpulan">
    <a class="nav-link {{ $mode === 'print' ? 'active' : '' }}" href="{{ route('collections.index', [$o->id] + $base + ['mode' => 'print']) }}" @if($mode === 'print') aria-current="page" @endif><i class="bi bi-printer" aria-hidden="true"></i> Cetak</a>
    <a class="nav-link {{ $mode === 'digital' ? 'active' : '' }}" href="{{ route('collections.index', [$o->id] + $base + ['mode' => 'digital']) }}" @if($mode === 'digital') aria-current="page" @endif><i class="bi bi-cloud-arrow-up" aria-hidden="true"></i> Digital</a>
</nav>
<form method="get" class="toolbar">
    <input type="hidden" name="mode" value="{{ $mode }}">
    <label class="visually-hidden" for="meeting_id">Pertemuan</label>
    <select id="meeting_id" name="meeting_id" class="form-select">@forelse($meetings as $m)<option value="{{ $m->id }}" @selected($meeting?->id === $m->id)>Pertemuan {{ $m->number }} — {{ $m->title }}</option>@empty<option value="">Belum ada pertemuan</option>@endforelse</select>
    <label class="visually-hidden" for="session_id">Sesi</label>
    <select id="session_id" name="session_id" class="form-select"><option value="">Semua sesi dalam lingkup</option>@foreach($sessions as $s)<option value="{{ $s->id }}" @selected((int) request('session_id') === $s->id)>{{ $s->label }}</option>@endforeach</select>
    <label class="visually-hidden" for="q">Cari NBI/nama</label><input id="q" name="q" class="form-control" value="{{ request('q') }}" placeholder="Cari NBI/nama">
    <x-per-page />
    <button class="btn btn-outline-primary"><i class="bi bi-funnel" aria-hidden="true"></i> Tampilkan</button>
</form>
@if($meeting && $summary->total)
<p class="small text-secondary mb-2">Pertemuan {{ $meeting->number }}: {{ $summary->tasks }} tugas {{ $mode === 'print' ? 'cetak' : 'digital' }} · {{ (int) $summary->received }} dari {{ $summary->total }} penerimaan tercatat.</p>
@endif
<section class="card"><div class="table-responsive"><table class="table table-compact roster-table">
    <thead><tr><th>No</th><th class="sticky-nbi">NBI</th><th class="sticky-student">Nama</th><th>Tugas</th><th>Sesi</th><th>Tanggal terima · WIB</th><th>Status</th><th>Nilai</th></tr></thead>
    <tbody>
    @forelse($rows as $row)
    <tr>
        <td>{{ $rows->firstItem() + $loop->index }}</td>
        <td class="sticky-nbi">{{ $row->nbi }}</td>
        <td class="sticky-student"><a href="{{ route('submissions.form', [$o->id, $row->assignment_id, $row->enrollment_id]) }}">{{ $row->name }}</a></td>
        <td>{{ $row->title }}</td>
        <td>{{ $row->session_label }}</td>
        <td>{{ $row->received_at ? \Carbon\CarbonImmutable::parse($row->received_at, 'UTC')->setTimezone('Asia/Jakarta')->format('d/m/Y H:i') : '—' }}</td>
        <td><span class="status-badge status-{{ $tones[$row->status] ?? 'danger' }}">{{ $states[$row->status] ?? 'Belum diterima' }}</span>@if($row->is_late) <span class="status-badge status-warn">Terlambat</span>@endif</td>
        <td>@if($row->grade_status === 'missing_zero')0 (diputuskan)@elseif($row->score !== null){{ (float) $row->score }}@elseif($row->max_score !== null)<span class="text-secondary">Belum dinilai</span>@else<span class="text-secondary">—</span>@endif</td>
    </tr>
    @empty
    <tr><td colspan="8" class="empty">@if(!$meeting)Belum ada pertemuan.@else Belum ada tugas {{ $mode === 'print' ? 'cetak' : 'digital' }} yang dijadwalkan dikumpulkan pada pertemuan ini dalam lingkup Anda. Atur di <a href="{{ route('assignments.index', $o->id) }}">Kelola tugas & jadwal</a>.@endif</td></tr>
    @endforelse
    </tbody>
</table></div></section>
<div class="mt-3">{{ $rows->links() }}</div>
@endsection
