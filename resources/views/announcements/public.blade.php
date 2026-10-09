@extends('layouts.app')
@section('title', ($row ? $row->title : 'Pengumuman').' — Portal Praktikum')
@section('content')
@php $pp = $portal ?? null; $home = $pp ? [$pp['name'] => route('portal.home', $pp['current_slug'])] : ['Pilih praktikum' => route('home')]; $list = $pp ? route('portal.announcements', $pp['current_slug']) : route('public.announcements.index'); $show = fn ($id) => $pp ? route('portal.announcement', [$pp['current_slug'], $id]) : route('public.announcements.show', $id); @endphp
@if($row)
<x-page-header :title="$row->title" :subtitle="\Carbon\CarbonImmutable::parse($row->published_at, 'UTC')->setTimezone('Asia/Jakarta')->locale('id')->translatedFormat('d F Y, H:i').' WIB'.($row->practicum ? ' · '.$row->practicum : ' · Umum')" :crumbs="$home + ['Pengumuman' => $list, 'Detail' => null]" />
<article class="card"><div class="card-body announcement-body">{!! \App\Http\Controllers\AnnouncementController::render($row->body) !!}</div></article>
@else
<x-page-header title="Pengumuman" subtitle="Informasi resmi dari pengelola praktikum." :crumbs="$home + ['Pengumuman' => null]" />
<section class="card"><div class="card-body">
    @forelse($rows as $a)
    <div class="history-item"><a href="{{ $show($a->id) }}" class="fw-semibold">{{ $a->title }}</a><div class="small text-secondary">{{ \Carbon\CarbonImmutable::parse($a->published_at, 'UTC')->setTimezone('Asia/Jakarta')->format('d/m/Y H:i') }} WIB · {{ $a->practicum ?? 'Umum' }}</div><div class="small">{{ \Illuminate\Support\Str::limit(strip_tags(\App\Http\Controllers\AnnouncementController::render($a->body)), 160) }}</div></div>
    @empty
    <div class="empty">Belum ada pengumuman yang diterbitkan.</div>
    @endforelse
</div></section>
<div class="mt-3">{{ $rows->links() }}</div>
@endif
@endsection
