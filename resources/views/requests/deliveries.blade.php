@extends('layouts.app')
@section('title', 'Kiriman digital — Portal Praktikum')
@section('context', $o->semester.' · '.$o->name)
@section('content')
@php $tones = ['pending' => 'warn', 'accepted' => 'ok', 'rejected' => 'danger']; $labels = ['pending' => 'Menunggu', 'accepted' => 'Diterima', 'rejected' => 'Ditolak']; @endphp
<x-page-header title="Kiriman digital dari portal" subtitle="Periksa file yang dikirim praktikan tanpa login. Kiriman yang diterima menjadi penerimaan digital versi 1." :crumbs="['Pengumpulan' => route('collections.index', $o->id), 'Kiriman digital' => null]" />
<nav class="nav nav-tabs mb-3" aria-label="Status kiriman">
    @foreach(['' => 'Semua'] + $labels as $key => $label)<a class="nav-link {{ (string) request('status') === (string) $key ? 'active' : '' }}" href="{{ route('deliveries.index', array_filter([$o->id, 'status' => $key ?: null, 'per_page' => request('per_page')])) }}">{{ $label }}</a>@endforeach
</nav>
<form method="get" class="toolbar"><input type="hidden" name="status" value="{{ request('status') }}"><x-per-page data-autosubmit /><noscript><button class="btn btn-outline-primary">Terapkan</button></noscript></form>
<section class="card"><div class="table-responsive"><table class="table table-stack">
    <thead><tr><th>Dikirim · WIB</th><th>NBI</th><th>Nama</th><th>Tugas</th><th>File</th><th>Status</th><th>Pemeriksaan</th></tr></thead>
    <tbody>
    @forelse($rows as $row)
    <tr>
        <td>{{ \Carbon\CarbonImmutable::parse($row->received_at, 'UTC')->setTimezone('Asia/Jakarta')->format('d/m/Y H:i') }}@if($row->received_at > $row->due_at) <span class="status-badge status-warn">Terlambat</span>@endif</td>
        <td>{{ $row->nbi }}</td><td>{{ $row->name }}</td>
        <td>{{ $row->title }}<div class="small text-secondary">{{ $row->session_label }}</div></td>
        <td><a href="{{ route('deliveries.download', [$o->id, $row->id]) }}"><i class="bi bi-file-earmark-arrow-down" aria-hidden="true"></i> {{ $row->original_name }}</a><div class="small text-secondary">{{ number_format($row->size / 1024, 1) }} KB</div></td>
        <td><span class="status-badge status-{{ $tones[$row->status] ?? 'neutral' }}">{{ $labels[$row->status] ?? $row->status }}</span></td>
        <td>
            @if($row->status === 'pending')
            <form method="post" action="{{ route('deliveries.decide', [$o->id, $row->id]) }}" class="delivery-form" data-dirty-form>@csrf
                <input type="hidden" name="version" value="{{ $row->version }}">
                <label class="visually-hidden" for="decision-{{ $row->id }}">Keputusan {{ $row->name }}</label>
                <select id="decision-{{ $row->id }}" name="decision" class="form-select form-select-sm mb-1" required><option value="">Pilih</option><option value="accepted">Terima</option><option value="rejected">Tolak</option></select>
                <label class="visually-hidden" for="reason-{{ $row->id }}">Catatan {{ $row->name }}</label>
                <input id="reason-{{ $row->id }}" name="reason" class="form-control form-control-sm mb-1" placeholder="Alasan internal (wajib bila ditolak)" maxlength="1000">
                <label class="visually-hidden" for="note-{{ $row->id }}">Catatan untuk praktikan {{ $row->name }}</label>
                <input id="note-{{ $row->id }}" name="student_note" class="form-control form-control-sm mb-1" placeholder="Catatan untuk praktikan (tanpa nama/NBI/nilai)" maxlength="1000">
                
                <button type="submit" class="btn btn-sm btn-primary">Simpan</button>
            </form>
            @else
            <span class="small text-secondary">Sudah diperiksa</span>
            @endif
            <a class="small d-inline-block mt-1" href="{{ route('deliveries.reissue.form', [$o->id, $row->id]) }}"><i class="bi bi-key" aria-hidden="true"></i> Token hilang?</a>
        </td>
    </tr>
    @empty
    <tr><td colspan="7" class="empty">Belum ada kiriman digital dalam lingkup Anda.</td></tr>
    @endforelse
    </tbody>
</table></div></section>
<div class="mt-3">{{ $rows->links() }}</div>
@endsection
