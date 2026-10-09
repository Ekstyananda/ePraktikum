@extends('layouts.app')
@section('title', $portal['name'].' — Portal Praktikum')
@section('content')
@php $slug = $portal['current_slug']; $svc = $portal['services']; $pr = $portal['practicum']; @endphp
<section class="hero">
    <div>
        <div class="eyebrow">{{ $pr->short_name ?: $pr->code }} · {{ $portal['offering']->semester }}</div>
        <h1>{{ $portal['name'] }}</h1>
        <p class="lead mb-2">{{ $pr->tagline ?: 'Akses modul, kumpulkan tugas, ajukan keperluan, dan pantau status praktikum dengan mudah.' }}</p>
        <p class="small text-secondary mb-0">Teknik Informatika · Universitas 17 Agustus 1945 Surabaya. Portal praktikan tanpa akun.</p>
        @if($pr->contact)<p class="small mb-0 mt-2"><i class="bi bi-person-lines-fill" aria-hidden="true"></i> {{ $pr->contact }}@if($pr->contact_url) · <a href="{{ $pr->contact_url }}" rel="noopener nofollow" target="_blank">Hubungi</a>@endif</p>@endif
    </div>
    <div class="d-none d-sm-block">@include('portal.cover', ['practicum' => $pr, 'class' => 'hero-cover'])</div>
</section>

@php
$cards = array_filter([
    $svc['modul'] ? ['blue', 'book-half', 'Modul & Soal', 'Unduh modul praktikum dan soal latihan yang telah diterbitkan.', route('portal.materials', $slug), 'Lihat modul'] : null,
    $svc['pengumpulan'] ? ['green', 'upload', 'Pengumpulan Tugas', 'Kumpulkan tugas digital sesuai sesi dan pertemuan.', route('portal.pengumpulan', $slug), 'Kumpulkan'] : null,
    $svc['pengajuan'] ? ['orange', 'file-earmark-text', 'Pengajuan', 'Ajukan izin, pindah sesi atau susulan.', route('portal.pengajuan', $slug), 'Ajukan'] : null,
    $svc['remidi'] ? ['purple', 'arrow-repeat', 'Remidi', 'Lihat program remidi yang sedang dibuka.', route('portal.remidi', $slug), 'Lihat remidi'] : null,
    ['cyan', 'search', 'Cek Status', 'Pantau status tugas dan pengajuan dengan token bukti.', route('public.status'), 'Cek status'],
]);
@endphp
<div class="row g-3 mt-1">
    @foreach($cards as [$tone, $icon, $title, $text, $url, $cta])
    <div class="col-6 col-lg">
        <a class="card quick-card" href="{{ $url }}">
            <span class="stat-icon {{ $tone }}"><i class="bi bi-{{ $icon }}" aria-hidden="true"></i></span>
            <h2>{{ $title }}</h2><p>{{ $text }}</p>
            <span class="card-link">{{ $cta }} <i class="bi bi-arrow-right" aria-hidden="true"></i></span>
        </a>
    </div>
    @endforeach
</div>

<div class="row g-3 mt-1">
    <div class="col-lg-4">
        <section class="card h-100"><div class="card-body">
            <div class="card-title-row"><h2><i class="bi bi-megaphone-fill text-primary" aria-hidden="true"></i> Pengumuman</h2><a class="card-link" href="{{ route('portal.announcements', $slug) }}">Lihat semua</a></div>
            @if($announcements->isNotEmpty())
            <ul class="list-plain">
                @foreach($announcements as $a)<li><i class="bi bi-dot" aria-hidden="true"></i><span><a href="{{ route('portal.announcement', [$slug, $a->id]) }}">{{ $a->title }}</a><span class="small text-secondary d-block">{{ \Carbon\CarbonImmutable::parse($a->published_at,'UTC')->setTimezone('Asia/Jakarta')->format('d M Y') }}</span></span></li>@endforeach
            </ul>
            @else
            <div class="empty py-4">Belum ada pengumuman yang diterbitkan.</div>
            @endif
        </div></section>
    </div>
    <div class="col-lg-4">
        <section class="card h-100"><div class="card-body">
            <div class="card-title-row"><h2><i class="bi bi-calendar-week text-primary" aria-hidden="true"></i> Jadwal Terdekat</h2>@if($svc['jadwal'])<a class="card-link" href="{{ route('portal.jadwal', $slug) }}">Lihat jadwal</a>@endif</div>
            @if($upcoming->isNotEmpty())
            <ul class="list-plain">
                @foreach($upcoming as $sm)<li><i class="bi bi-clock" aria-hidden="true"></i><span>{{ \Carbon\CarbonImmutable::parse($sm->starts_at,'UTC')->setTimezone('Asia/Jakarta')->locale('id')->translatedFormat('D, d M · H:i') }} WIB<span class="small text-secondary d-block">{{ $sm->label }} · Pertemuan {{ $sm->number }} · {{ $sm->room }}</span></span></li>@endforeach
            </ul>
            @else
            <div class="empty py-4">Belum ada jadwal terdekat.</div>
            @endif
        </div></section>
    </div>
    <div class="col-lg-4">
        <section class="card h-100"><div class="card-body">
            <div class="card-title-row"><h2><i class="bi bi-journal-text text-primary" aria-hidden="true"></i> Modul Praktikum</h2>@if($svc['modul'])<a class="card-link" href="{{ route('portal.materials', $slug) }}">Lihat semua</a>@endif</div>
            @if($materials->isNotEmpty())
            <ul class="list-plain">
                @foreach($materials as $m)<li><i class="bi bi-file-earmark-pdf" aria-hidden="true"></i><span>Pertemuan {{ $m->number }} — {{ $m->title }}<span class="small text-secondary d-block">{{ $m->practicum }}</span></span><a class="ms-auto small" href="{{ route('portal.material.download', [$slug, $m->id]) }}">Unduh</a></li>@endforeach
            </ul>
            @else
            <div class="empty py-4">Belum ada modul yang diterbitkan.</div>
            @endif
        </div></section>
    </div>
</div>
@endsection
