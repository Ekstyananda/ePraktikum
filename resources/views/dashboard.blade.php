@extends('layouts.app')
@section('title','Dashboard — Portal Praktikum')
@section('content')
<x-page-header :title="'Halo, '.auth()->user()->name" :subtitle="$current ? 'Ringkasan kegiatan '.$current->name.' · '.$current->semester.'.' : 'Praktikum dan semester dalam lingkup akses Anda.'" />

@if($current && $summary)
@php $s = $summary; @endphp
<div class="row g-3 mb-3">
    <div class="col-md-4">
        <section class="card stat-card"><div class="card-body d-flex flex-column">
            <span class="stat-icon blue"><i class="bi bi-people-fill" aria-hidden="true"></i></span>
            <div class="stat-value">{{ $s['students'] }}</div>
            <div class="stat-label">Total praktikan aktif</div>
            @if($s['can']['students'])<a class="card-link" href="{{ route('students.index',$current->id) }}">Lihat detail <i class="bi bi-arrow-right" aria-hidden="true"></i></a>@endif
        </div></section>
    </div>
    <div class="col-md-4">
        <section class="card stat-card"><div class="card-body d-flex flex-column">
            <span class="stat-icon orange"><i class="bi bi-file-earmark-text-fill" aria-hidden="true"></i></span>
            <div class="stat-value">{{ $s['can']['submissions'] ? $s['to_review'] : '—' }}</div>
            <div class="stat-label">Pengumpulan perlu diperiksa</div>
            @if($s['can']['submissions'])<a class="card-link" href="{{ route('collections.index',$current->id) }}">Lihat tugas <i class="bi bi-arrow-right" aria-hidden="true"></i></a>@else<span class="small text-secondary">Di luar izin Anda</span>@endif
        </div></section>
    </div>
    <div class="col-md-4">
        <section class="card stat-card"><div class="card-body d-flex flex-column">
            <span class="stat-icon cyan"><i class="bi bi-clipboard-check-fill" aria-hidden="true"></i></span>
            <div class="stat-value">{{ $s['can']['attendance'] ? $s['unrecorded'] : '—' }}</div>
            <div class="stat-label">Presensi belum dicatat</div>
            @if($s['can']['attendance'])<a class="card-link" href="{{ route('attendance.overview',$current->id) }}">Lihat presensi <i class="bi bi-arrow-right" aria-hidden="true"></i></a>@else<span class="small text-secondary">Di luar izin Anda</span>@endif
        </div></section>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-xl-7">
        <section class="card h-100"><div class="card-body">
            <div class="card-title-row">
                <div><h2>Jadwal Hari Ini</h2><div class="small text-secondary">{{ $s['today']->locale('id')->translatedFormat('l, d F Y') }}</div></div>
                @if($s['can']['meetings'])<a class="card-link" href="{{ route('meetings.index',$current->id) }}">Lihat semua</a>@endif
            </div>
            <div class="table-responsive">
                <table class="table table-compact table-stack">
                    <thead><tr><th>Waktu · WIB</th><th>Sesi</th><th>Pertemuan</th><th>Ruang</th><th><span class="visually-hidden">Aksi</span></th></tr></thead>
                    <tbody>
                        @forelse($s['schedule'] as $row)
                        <tr>
                            <td class="text-nowrap">{{ \Carbon\CarbonImmutable::parse($row->starts_at,'UTC')->setTimezone('Asia/Jakarta')->format('H:i') }}–{{ \Carbon\CarbonImmutable::parse($row->ends_at,'UTC')->setTimezone('Asia/Jakarta')->format('H:i') }}</td>
                            <td>{{ $row->session_label }}</td>
                            <td>Pertemuan {{ $row->number }}</td>
                            <td>{{ $row->room }}</td>
                            <td class="text-end">@if($row->can_attend)<a class="btn btn-sm btn-outline-primary" href="{{ route('attendance.index',[$current->id,$row->id]) }}">Buka presensi</a>@endif</td>
                        </tr>
                        @empty
                        <tr><td colspan="5" class="empty">Tidak ada pelaksanaan terjadwal hari ini dalam lingkup Anda.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div></section>
    </div>
    <div class="col-xl-5">
        <section class="card h-100"><div class="card-body">
            <div class="card-title-row"><h2>Tugas &amp; Tindak Lanjut</h2></div>
            @php
            $items = array_filter([
                $s['can']['requests'] && $s['pending_requests'] ? ['orange','envelope-paper',$s['pending_requests'].' pengajuan menunggu keputusan','Izin, pindah sesi, susulan atau remidi',route('requests.index',[$current->id,'status'=>'pending'])] : null,
                $s['can']['submissions'] && $s['pending_deliveries'] ? ['blue','cloud-download',$s['pending_deliveries'].' kiriman digital menunggu pemeriksaan','Dikirim praktikan melalui portal publik',route('deliveries.index',[$current->id,'status'=>'pending'])] : null,
                $s['can']['submissions'] && $s['to_review'] ? ['blue','file-earmark-check',$s['to_review'].' pengumpulan menunggu pemeriksaan','Diterima/dikirim, belum selesai',route('collections.index',$current->id)] : null,
                $s['can']['submissions'] && $s['revisions'] ? ['orange','arrow-repeat',$s['revisions'].' pengumpulan menunggu revisi','Revisi telah diminta',route('collections.index',$current->id)] : null,
                $s['can']['submissions'] && $s['late_decisions'] ? ['orange','hourglass-split',$s['late_decisions'].' keterlambatan belum diputuskan','Keputusan terlambat masih pending',route('collections.index',$current->id)] : null,
                $s['can']['attendance'] && $s['need_snapshot'] ? ['cyan','snow',$s['need_snapshot'].' pelaksanaan belum dibekukan','Snapshot peserta diperlukan sebelum presensi',route('attendance.overview',$current->id)] : null,
            ]);
            @endphp
            @if($items)
            <ul class="pending-list">
                @foreach($items as [$tone,$icon,$title,$hint,$url])
                <li><a href="{{ $url }}"><span class="stat-icon {{ $tone }}"><i class="bi bi-{{ $icon }}" aria-hidden="true"></i></span><span><strong>{{ $title }}</strong><small>{{ $hint }}</small></span><i class="bi bi-chevron-right" aria-hidden="true"></i></a></li>
                @endforeach
            </ul>
            @else
            <div class="empty"><i class="bi bi-check2-circle fs-3 d-block mb-2" aria-hidden="true"></i>Tidak ada tindak lanjut tertunda.</div>
            @endif
        </div></section>
    </div>
</div>
@endif

@if($current && $summary && ($summary['announcements']->isNotEmpty() || $summary['can']['announcements']))
<section class="card mb-4"><div class="card-body">
    <div class="card-title-row"><h2><i class="bi bi-megaphone text-primary" aria-hidden="true"></i> Pengumuman terbaru</h2>@if($summary['can']['announcements'])<a class="card-link" href="{{ route('announcements.index', $current->id) }}">Kelola</a>@endif</div>
    @forelse($summary['announcements'] as $a)
    <div class="history-item">@if($summary['can']['announcements'] && ($a->offering_id || auth()->user()->role === 'admin'))<a href="{{ route('announcements.edit', [$current->id, $a->id]) }}">{{ $a->title }}</a>@else{{ $a->title }}@endif <span class="status-badge status-{{ $a->audience === 'staff' ? 'info' : 'ok' }}">{{ $a->audience === 'staff' ? 'Internal' : 'Publik' }}</span><div class="small text-secondary">{{ \Carbon\CarbonImmutable::parse($a->published_at, 'UTC')->setTimezone('Asia/Jakarta')->format('d/m/Y H:i') }} WIB</div></div>
    @empty
    <p class="text-secondary mb-0">Belum ada pengumuman terbit.</p>
    @endforelse
</div></section>
@endif
<div class="card-title-row"><h2>Praktikum dalam lingkup akses</h2></div>
<div class="row g-3">@forelse($offerings as $o)<div class="col-md-6 col-xl-4">
        <section class="card h-100 {{ $current && $current->id === $o->id ? 'border-primary' : '' }}">
            <div class="card-body"><span class="status-badge status-{{ ['active'=>'ok','locked'=>'danger'][$o->status] ?? 'neutral' }} mb-3">{{ $o->semester }} · {{ $o->status }}</span>
                <h2>{{ $o->name }}</h2>
                <a href="{{ route('offering',$o->id) }}" class="btn btn-sm btn-outline-primary mt-2">Lihat sesi →</a>
            </div>
        </section>
    </div>@empty<div class="col">
        <div class="card">
            <div class="empty">
                <h2>Belum ada praktikum</h2>
                <p class="mb-0">{{ auth()->user()->role==='admin'?'Semester, praktikum, dan sesi belum tersedia.':'Hubungi administrator untuk penugasan praktikum.' }}</p>
            </div>
        </div>
    </div>@endforelse</div>
@endsection
