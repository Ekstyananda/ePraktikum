@extends('layouts.app')
@section('context',$o->semester.' · '.$o->name)
@section('content')
@php $modeIcons = ['print'=>'printer','digital'=>'cloud-arrow-up','direct'=>'person-check']; @endphp
<x-page-header title="Kelola Tugas & Jadwal" subtitle="Daftar tugas, jadwal pengumpulan per sesi dan penerimaan per tugas." :crumbs="['Pengumpulan' => route('collections.index', $o->id), 'Kelola tugas' => null]">
    <x-slot:actions><a class="btn btn-outline-primary" href="{{ route('deliveries.index',$o->id) }}"><i class="bi bi-cloud-download" aria-hidden="true"></i> Kiriman digital</a>@if($shared)<a class="btn btn-primary" href="{{ route('assignments.create',$o->id) }}"><i class="bi bi-plus-lg" aria-hidden="true"></i> Tambah tugas</a>@endif</x-slot:actions>
</x-page-header>
<form class="toolbar" method="get">
    <input class="form-control" name="q" aria-label="Cari tugas" value="{{ request('q') }}" placeholder="Cari tugas">
    <select class="form-select" name="mode" aria-label="Mode tugas"><option value="">Semua mode</option>@foreach(\App\Services\Assessment::MODES as $key=>$label)<option value="{{ $key }}" @selected(request('mode')===$key)>{{ $label }}</option>@endforeach</select>
    <x-per-page />
    <button class="btn btn-outline-primary"><i class="bi bi-funnel" aria-hidden="true"></i> Filter</button>
</form>
<section class="card"><div class="table-responsive"><table class="table table-stack"><thead><tr><th>Tugas</th><th>Jenis</th><th>Mode</th><th>Status</th><th class="text-end">Aksi</th></tr></thead><tbody>
@forelse($rows as $a)<tr>
    <td><strong class="text-body">{{ $a->title }}</strong></td>
    <td>{{ \App\Services\Assessment::TYPES[$a->type] }}</td>
    <td><i class="bi bi-{{ $modeIcons[$a->mode] ?? 'dot' }} text-secondary" aria-hidden="true"></i> {{ \App\Services\Assessment::MODES[$a->mode] }}</td>
    <td><span class="status-badge {{ $a->active ? 'status-ok' : 'status-neutral' }}">{{ $a->active?'Aktif':'Nonaktif' }}</span> <span class="status-badge {{ $a->mandatory ? 'status-info' : 'status-neutral' }}">{{ $a->mandatory?'Wajib':'Opsional' }}</span></td>
    <td class="text-end"><div class="d-inline-flex gap-1 flex-wrap justify-content-end">
        @if($a->mode!=='direct')<a class="btn btn-sm btn-primary" href="{{ route('submissions.index',[$o->id,$a->id]) }}"><i class="bi bi-inbox" aria-hidden="true"></i> Penerimaan</a><a class="btn btn-sm btn-outline-primary" href="{{ route('assignments.schedules',[$o->id,$a->id]) }}"><i class="bi bi-calendar-event" aria-hidden="true"></i> Jadwal pengumpulan</a>@else<span class="small text-secondary align-self-center me-1">Nilai melalui Penilaian; tanpa submission/file.</span>@endif
        @if($shared)<a class="btn btn-sm btn-outline-secondary" href="{{ route('assignments.edit',[$o->id,$a->id]) }}"><i class="bi bi-pencil" aria-hidden="true"></i> Edit tugas</a>@endif
    </div></td>
</tr>@empty<tr><td colspan="5" class="empty">Belum ada tugas. Buat sesuai ketentuan resmi praktikum.</td></tr>@endforelse
</tbody></table></div></section>
<div class="mt-3">{{ $rows->links() }}</div>
@endsection
