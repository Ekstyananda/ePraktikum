@extends('layouts.app')
@section('title', 'Portal Praktikum — Teknik Informatika')
@section('content')
<section class="hero mb-3">
    <div>
        <div class="eyebrow">Portal Praktikum</div>
        <h1>Pilih praktikum Anda</h1>
        <p class="lead mb-2">Akses modul, jadwal, pengumpulan tugas, pengajuan dan status praktikum. Tanpa akun.</p>
        <p class="small text-secondary mb-0">Teknik Informatika · Universitas 17 Agustus 1945 Surabaya. Portal praktikan tanpa akun: simpan link praktikum dari aslab agar langsung masuk ke halaman praktikum Anda.</p>
    </div>
</section>
<div class="row g-3">
    @forelse($practicums as $p)
    @php $c = \App\Services\PracticumPortal::palette($p); @endphp
    <div class="col-sm-6 col-lg-4">
        <a class="card practicum-card" href="{{ route('portal.home', $p->slug) }}" style="--card-accent: {{ $c['accent'] }}; --card-soft: {{ $c['soft'] }};">
            <div class="cover">@include('portal.cover', ['practicum' => $p])</div>
            <div class="body">
                <span class="code">{{ $p->short_name ?: $p->code }}</span>
                <h2>{{ \App\Services\PracticumPortal::displayName($p) }}</h2>
                <p>{{ $p->tagline ?: $p->offering->semester }}</p>
                <span class="go">Masuk <i class="bi bi-arrow-right" aria-hidden="true"></i></span>
            </div>
        </a>
    </div>
    @empty
    <div class="col-12"><section class="card"><div class="empty"><i class="bi bi-calendar-x fs-3 d-block mb-2" aria-hidden="true"></i>Belum ada praktikum yang dibuka untuk umum.</div></section></div>
    @endforelse
</div>
<div class="row g-3 mt-1">
    <div class="col-lg-8">
        <section class="card h-100"><div class="card-body">
            <div class="card-title-row"><h2><i class="bi bi-megaphone-fill text-primary" aria-hidden="true"></i> Pengumuman umum</h2><a class="card-link" href="{{ route('public.announcements.index') }}">Lihat semua</a></div>
            @if($announcements->isNotEmpty())
            <ul class="list-plain">@foreach($announcements as $a)<li><i class="bi bi-dot" aria-hidden="true"></i><span><a href="{{ route('public.announcements.show', $a->id) }}">{{ $a->title }}</a><span class="small text-secondary d-block">{{ \Carbon\CarbonImmutable::parse($a->published_at,'UTC')->setTimezone('Asia/Jakarta')->format('d M Y') }}</span></span></li>@endforeach</ul>
            @else
            <div class="empty py-4">Belum ada pengumuman umum.</div>
            @endif
        </div></section>
    </div>
    <div class="col-lg-4">
        <a class="card quick-card h-100" href="{{ route('public.status') }}"><span class="stat-icon cyan"><i class="bi bi-search" aria-hidden="true"></i></span><h2>Cek Status</h2><p>Pantau kiriman tugas dan pengajuan dari praktikum mana pun dengan token bukti.</p><span class="card-link">Cek status <i class="bi bi-arrow-right" aria-hidden="true"></i></span></a>
    </div>
</div>
@endsection
