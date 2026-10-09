@extends('layouts.app')
@section('context',$o->semester.' · '.$o->name)
@section('content')
@php
$states = $a->mode==='print' ? \App\Services\Assessment::PRINT_STATES : \App\Services\Assessment::DIGITAL_STATES;
$tones = ['received'=>'ok','submitted'=>'info','revision_requested'=>'warn','revised'=>'info','complete'=>'ok','missing_decided'=>'danger'];
@endphp
<x-page-header :title="$a->type==='final'?'Laporan Akhir':'Penerimaan '.\App\Services\Assessment::MODES[$a->mode]" :subtitle="$a->title" :crumbs="$a->type === 'final' ? ['Laporan akhir' => route('final-reports.index', $o->id), $a->title => null] : ['Pengumpulan' => route('collections.index', $o->id), 'Kelola tugas' => route('assignments.index', $o->id), $a->title => null]">
    <x-slot:actions><span class="status-badge status-info"><i class="bi bi-{{ $a->mode==='print' ? 'printer' : 'cloud-arrow-up' }}" aria-hidden="true"></i> {{ \App\Services\Assessment::MODES[$a->mode] }}</span><a class="btn btn-outline-primary" href="{{ route('assignments.schedules',[$o->id,$a->id]) }}"><i class="bi bi-calendar-event" aria-hidden="true"></i> Jadwal</a></x-slot:actions>
</x-page-header>
<form class="toolbar"><input name="q" class="form-control" aria-label="Cari NBI/nama" placeholder="Cari NBI/nama" value="{{ request('q') }}"><x-per-page /><button class="btn btn-outline-primary"><i class="bi bi-search" aria-hidden="true"></i> Cari</button></form>
<section class="card"><div class="table-responsive"><table class="table table-compact roster-table"><thead><tr><th>No</th><th class="sticky-nbi">NBI</th><th class="sticky-student">Nama</th><th>Sesi</th><th>Status</th><th>Waktu terima · WIB</th><th>Waktu input · WIB</th></tr></thead><tbody>
@forelse($rows as $e)<tr>
    <td>{{ $rows->firstItem()+$loop->index }}</td>
    <td class="sticky-nbi">{{ $e->nbi }}</td>
    <td class="sticky-student"><a href="{{ route('submissions.form',[$o->id,$a->id,$e->id]) }}">{{ $e->name }}</a></td>
    <td>{{ $e->session_label }}</td>
    <td><span class="status-badge status-{{ $tones[$e->receipt_status] ?? 'danger' }}">{{ $states[$e->receipt_status]??'Belum diterima' }}</span>@if($e->is_late) <span class="status-badge status-warn">Terlambat</span>@endif</td>
    @foreach(['received_at','recorded_at'] as $field)<td>{{ $e->$field?\Carbon\CarbonImmutable::parse($e->$field,'UTC')->setTimezone('Asia/Jakarta')->format('d/m/Y H:i'):'—' }}</td>@endforeach
</tr>@empty<tr><td colspan="7" class="empty">Belum ada praktikan sesuai scope/filter.</td></tr>@endforelse
</tbody></table></div></section>
<div class="mt-3">{{ $rows->links() }}</div>
@endsection
