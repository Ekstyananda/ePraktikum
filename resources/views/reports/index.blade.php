@extends('layouts.app')
@section('title', 'Rekap & Ekspor — Portal Praktikum')
@section('context', $o->semester.' · '.$o->name)
@section('content')
@php $types = \App\Services\Reports::TYPES; $query = array_filter(['type' => $type, 'session_id' => $session, 'meeting_id' => $meeting]); @endphp
<x-page-header title="Rekap & Ekspor" subtitle="Rekap akademik dalam lingkup sesi Anda. Ekspor memakai data yang sama dengan tabel di halaman ini." :crumbs="['Dashboard' => route('dashboard'), 'Rekap' => null]">
    <x-slot:actions>
        <a class="btn btn-outline-primary" href="{{ route('reports.index', [$o->id] + $query + ['format' => 'print']) }}" target="_blank" rel="noopener"><i class="bi bi-printer" aria-hidden="true"></i> Cetak</a>
        <a class="btn btn-outline-primary" href="{{ route('reports.index', [$o->id] + $query + ['format' => 'csv']) }}"><i class="bi bi-filetype-csv" aria-hidden="true"></i> CSV</a>
        <a class="btn btn-primary" href="{{ route('reports.index', [$o->id] + $query + ['format' => 'xlsx']) }}"><i class="bi bi-file-earmark-spreadsheet" aria-hidden="true"></i> XLSX</a>
    </x-slot:actions>
</x-page-header>
<nav class="nav nav-tabs mb-3" aria-label="Jenis rekap">
    @foreach($types as $key => $label)<a class="nav-link {{ $type === $key ? 'active' : '' }}" href="{{ route('reports.index', array_filter([$o->id, 'type' => $key, 'session_id' => $session])) }}">{{ $label }}</a>@endforeach
</nav>
<form method="get" class="toolbar">
    <input type="hidden" name="type" value="{{ $type }}">
    <label class="visually-hidden" for="session_id">Sesi</label><select id="session_id" name="session_id" class="form-select"><option value="">Semua sesi dalam lingkup</option>@foreach($sessions as $s)<option value="{{ $s->id }}" @selected($session === $s->id)>{{ $s->label }}</option>@endforeach</select>
    @if(in_array($type, ['presensi', 'pengumpulan'], true))<label class="visually-hidden" for="meeting_id">Pertemuan</label><select id="meeting_id" name="meeting_id" class="form-select"><option value="">Semua pertemuan</option>@foreach($meetings as $m)<option value="{{ $m->id }}" @selected($meeting === $m->id)>Pertemuan {{ $m->number }}</option>@endforeach</select>@endif
    <button class="btn btn-outline-primary"><i class="bi bi-funnel" aria-hidden="true"></i> Terapkan</button>
</form>
<section class="card">
    <div class="card-body pb-2"><p class="small text-secondary mb-0">{{ $data['note'] }} · {{ count($data['rows']) }} baris.</p></div>
    <div class="table-responsive"><table class="table table-compact report-table">
        <thead><tr>@foreach($data['headers'] as $h)<th>{{ $h }}</th>@endforeach</tr></thead>
        <tbody>
        @forelse(array_slice($data['rows'], 0, 300) as $row)
        <tr>@foreach($row as $cell)<td>{{ $cell }}</td>@endforeach</tr>
        @empty
        <tr><td colspan="{{ count($data['headers']) }}" class="empty">Belum ada data untuk filter ini.</td></tr>
        @endforelse
        </tbody>
    </table></div>
    @if(count($data['rows']) > 300)<div class="card-body pt-2"><p class="small text-secondary mb-0">Menampilkan 300 baris pertama. Unduh CSV/XLSX untuk data lengkap.</p></div>@endif
</section>
@endsection
