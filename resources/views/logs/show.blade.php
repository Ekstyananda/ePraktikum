@extends('layouts.app')
@section('title', 'Detail log — Portal Praktikum')
@if($o)@section('context', $o->semester.' · '.$o->name)@endif
@section('content')
@php $fmt = fn ($v) => is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : (is_bool($v) ? ($v ? 'true' : 'false') : ($v === null ? '—' : (string) $v)); @endphp
<x-page-header :title="$row->action" :subtitle="$row->entity_type.' #'.$row->entity_id" :crumbs="$o ? ['Log aktivitas' => route('logs.offering', $o->id), '#'.$row->id => null] : ['Pengaturan' => route('settings.index'), 'Log aktivitas' => route('logs.index'), '#'.$row->id => null]">
    <x-slot:actions><span class="status-badge status-neutral"><i class="bi bi-lock" aria-hidden="true"></i> Read-only</span></x-slot:actions>
</x-page-header>
<section class="card mb-3"><div class="card-body"><dl class="row mb-0 small-dl">
    <dt class="col-sm-3">Waktu</dt><dd class="col-sm-9">{{ \Carbon\CarbonImmutable::parse($row->created_at, 'UTC')->setTimezone('Asia/Jakarta')->format('d/m/Y H:i:s') }} WIB</dd>
    <dt class="col-sm-3">Pelaku</dt><dd class="col-sm-9">{{ $row->actor ?? 'Sistem / portal publik' }}</dd>
    <dt class="col-sm-3">Praktikum</dt><dd class="col-sm-9">{{ $row->practicum ?? '—' }}</dd>
    <dt class="col-sm-3">Alasan</dt><dd class="col-sm-9">{{ $row->reason ?? '—' }}</dd>
    <dt class="col-sm-3">Batch</dt><dd class="col-sm-9"><code>{{ $row->request_id }}</code>@if($batch) · {{ $batch }} perubahan lain dalam batch yang sama @endif</dd>
</dl></div></section>
<section class="card"><div class="card-body pb-0"><h2>Sebelum / sesudah</h2><p class="small text-secondary">Baris yang berubah ditandai. Nilai rahasia disamarkan.</p></div>
<div class="table-responsive"><table class="table table-compact diff-table">
    <thead><tr><th>Field</th><th>Sebelum</th><th>Sesudah</th></tr></thead>
    <tbody>
    @forelse($keys as $key)
    @php
    $inBefore = is_array($before) && array_key_exists($key, $before);
    $inAfter = is_array($after) && array_key_exists($key, $after);
    $b = $inBefore ? $before[$key] : null; $a = $inAfter ? $after[$key] : null;
    // A key missing from "after" while "before" is a full row means the field was not part of the change.
    $untouched = $inBefore && ! $inAfter && is_array($after);
    $changed = ! $untouched && $fmt($b) !== $fmt($a);
    @endphp
    <tr class="{{ $changed ? 'changed' : '' }}"><td><code>{{ $key }}</code>@if($changed)<span class="visually-hidden"> (berubah)</span>@endif</td><td>{{ $inBefore ? $fmt($b) : '—' }}</td><td>@if($untouched)<span class="text-secondary">tidak diubah</span>@else{{ $fmt($a) }}@endif</td></tr>
    @empty
    <tr><td colspan="3" class="empty">Tidak ada data sebelum/sesudah.</td></tr>
    @endforelse
    </tbody>
</table></div></section>
@endsection
