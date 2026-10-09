@extends('layouts.app')
@section('title', 'Pengajuan — Portal Praktikum')
@section('context', $o->semester.' · '.$o->name)
@section('content')
@php
$types = \App\Services\PublicWorkflow::TYPES;
$states = \App\Services\PublicWorkflow::STATES;
$tones = ['pending' => 'warn', 'approved' => 'ok', 'rejected' => 'danger', 'completed' => 'info'];
@endphp
<x-page-header title="Pengajuan, Susulan & Remidi" subtitle="Periksa pengajuan praktikan. Persetujuan tidak otomatis mengubah presensi atau nilai awal." :crumbs="['Dashboard' => route('dashboard'), 'Pengajuan' => null]">
    <x-slot:actions>@if($canSettings)<a class="btn btn-outline-primary" href="{{ route('requests.settings', $o->id) }}"><i class="bi bi-gear" aria-hidden="true"></i> Periode & program remidi</a>@endif</x-slot:actions>
</x-page-header>
<nav class="nav nav-tabs mb-3" aria-label="Status pengajuan">
    @foreach(['' => 'Semua'] + array_intersect_key($states, $tones) as $key => $label)
    <a class="nav-link {{ (string) request('status') === (string) $key ? 'active' : '' }}" href="{{ route('requests.index', array_filter([$o->id, 'status' => $key ?: null, 'type' => request('type'), 'q' => request('q'), 'per_page' => request('per_page')])) }}">{{ $label }}@if($key && ($counts[$key] ?? 0)) <span class="badge rounded-pill text-bg-light">{{ $counts[$key] }}</span>@endif</a>
    @endforeach
</nav>
<form method="get" class="toolbar">
    <input type="hidden" name="status" value="{{ request('status') }}">
    <label for="type" class="visually-hidden">Jenis</label><select id="type" name="type" class="form-select"><option value="">Semua jenis</option>@foreach($types as $key => $label)<option value="{{ $key }}" @selected(request('type') === $key)>{{ $label }}</option>@endforeach</select>
    <label for="q" class="visually-hidden">Cari NBI/nama</label><input id="q" name="q" class="form-control" value="{{ request('q') }}" placeholder="Cari NBI/nama">
    <x-per-page />
    <button class="btn btn-outline-primary"><i class="bi bi-funnel" aria-hidden="true"></i> Filter</button>
</form>
<section class="card"><div class="table-responsive"><table class="table table-stack">
    <thead><tr><th>Diajukan · WIB</th><th>NBI</th><th>Nama</th><th>Jenis</th><th>Sesi</th><th>Status</th><th><span class="visually-hidden">Aksi</span></th></tr></thead>
    <tbody>
    @forelse($rows as $row)
    <tr>
        <td>{{ \Carbon\CarbonImmutable::parse($row->created_at, 'UTC')->setTimezone('Asia/Jakarta')->format('d/m/Y H:i') }}</td>
        <td>{{ $row->nbi }}</td>
        <td>{{ $row->name }}</td>
        <td>{{ $types[$row->type] }}</td>
        <td>{{ $row->source_label }}@if($row->target_label) <i class="bi bi-arrow-right" aria-label="ke"></i> {{ $row->target_label }}@endif</td>
        <td><span class="status-badge status-{{ $tones[$row->status] ?? 'neutral' }}">{{ $states[$row->status] ?? $row->status }}</span></td>
        <td class="text-end"><a class="btn btn-sm {{ $row->status === 'pending' ? 'btn-primary' : 'btn-outline-primary' }}" href="{{ route('requests.show', [$o->id, $row->id]) }}">{{ $row->status === 'pending' ? 'Periksa' : 'Detail' }}</a></td>
    </tr>
    @empty
    <tr><td colspan="7" class="empty">Belum ada pengajuan sesuai filter dalam lingkup Anda.</td></tr>
    @endforelse
    </tbody>
</table></div></section>
<div class="mt-3">{{ $rows->links() }}</div>
@endsection
