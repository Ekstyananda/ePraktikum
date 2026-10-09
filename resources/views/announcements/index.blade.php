@extends('layouts.app')
@section('title', 'Pengumuman — Portal Praktikum')
@section('context', $o->semester.' · '.$o->name)
@section('content')
@php $tones = ['draft' => 'neutral', 'published' => 'ok', 'archived' => 'warn']; $states = \App\Http\Controllers\AnnouncementController::STATES; @endphp
<x-page-header title="Pengumuman" subtitle="Tulis, terbitkan dan arsipkan pengumuman praktikum. Pengumuman publik tampil di beranda portal." :crumbs="['Dashboard' => route('dashboard'), 'Pengumuman' => null]">
    <x-slot:actions><a class="btn btn-primary" href="{{ route('announcements.create', $o->id) }}"><i class="bi bi-plus-lg" aria-hidden="true"></i> Tulis pengumuman</a></x-slot:actions>
</x-page-header>
<nav class="nav nav-tabs mb-3" aria-label="Status pengumuman">
    @foreach(['' => 'Semua'] + $states as $key => $label)<a class="nav-link {{ (string) request('status') === (string) $key ? 'active' : '' }}" href="{{ route('announcements.index', array_filter([$o->id, 'status' => $key ?: null, 'per_page' => request('per_page')])) }}">{{ $label }}</a>@endforeach
</nav>
<form method="get" class="toolbar"><input type="hidden" name="status" value="{{ request('status') }}"><x-per-page data-autosubmit /><noscript><button class="btn btn-outline-primary">Terapkan</button></noscript></form>
<section class="card"><div class="table-responsive"><table class="table table-stack">
    <thead><tr><th>Judul</th><th>Lingkup</th><th>Audiens</th><th>Status</th><th>Diperbarui · WIB</th><th><span class="visually-hidden">Aksi</span></th></tr></thead>
    <tbody>
    @forelse($rows as $row)
    <tr>
        <td>{{ $row->title }}<div class="small text-secondary">{{ $row->author }}</div></td>
        <td>{{ $row->offering_id ? 'Praktikum ini' : 'Umum' }}</td>
        <td>{{ $row->audience === 'public' ? 'Publik' : 'Internal' }}</td>
        <td><span class="status-badge status-{{ $tones[$row->status] ?? 'neutral' }}">{{ $states[$row->status] ?? $row->status }}</span></td>
        <td>{{ \Carbon\CarbonImmutable::parse($row->updated_at, 'UTC')->setTimezone('Asia/Jakarta')->format('d/m/Y H:i') }}</td>
        <td class="text-end">@if($row->offering_id || auth()->user()->role === 'admin')<a class="btn btn-sm btn-outline-primary" href="{{ route('announcements.edit', [$o->id, $row->id]) }}">{{ $row->status === 'archived' ? 'Lihat' : 'Edit' }}</a>@endif</td>
    </tr>
    @empty
    <tr><td colspan="6" class="empty">Belum ada pengumuman.</td></tr>
    @endforelse
    </tbody>
</table></div></section>
<div class="mt-3">{{ $rows->links() }}</div>
@endsection
