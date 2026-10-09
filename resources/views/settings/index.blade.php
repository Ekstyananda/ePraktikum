@extends('layouts.app')
@section('title', 'Pengaturan — Portal Praktikum')
@section('content')
@php $states = ['draft' => ['Draf', 'neutral'], 'active' => ['Aktif', 'ok'], 'locked' => ['Terkunci', 'danger']]; @endphp
<x-page-header title="Pengaturan" subtitle="Identitas semester dan praktikum, akun pengelola, log aktivitas dan backup." :crumbs="['Dashboard' => route('dashboard'), 'Pengaturan' => null]" />
<div class="row g-3">
    @foreach([
        ['calendar3', 'blue', 'Semester', 'Identitas, periode, kunci dan buka kembali semester.', route('master.index', 'semester')],
        ['collection', 'cyan', 'Master Praktikum', 'Kode dan nama praktikum.', route('master.index', 'praktikum')],
        ['diagram-3', 'green', 'Pelaksanaan Praktikum', $counts['offerings'].' pelaksanaan. Status aktif menentukan tampil di portal publik.', route('master.index', 'offering')],
        ['person-gear', 'orange', 'Akun Aslab', $counts['aslab'].' aslab aktif. Klik nama untuk praktikum, sesi dan hak akses.', route('aslab.index')],
        ['journal-text', 'blue', 'Log Aktivitas', $counts['logs'].' aktivitas dalam 24 jam terakhir. Read-only.', route('logs.index')],
        ['cloud-arrow-up', $backupProblems ? 'orange' : 'green', 'Backup', $lastBackup ? 'Berhasil terakhir '.\App\Services\Backup::wib($lastBackup).' WIB.' : ($backupProblems ? 'Belum dikonfigurasi.' : 'Belum ada backup.'), route('backup.index')],
    ] as [$icon, $tone, $title, $text, $url])
    <div class="col-sm-6 col-xl-4"><a class="card quick-card" href="{{ $url }}"><span class="stat-icon {{ $tone }}"><i class="bi bi-{{ $icon }}" aria-hidden="true"></i></span><h2>{{ $title }}</h2><p>{{ $text }}</p><span class="card-link">Buka <i class="bi bi-arrow-right" aria-hidden="true"></i></span></a></div>
    @endforeach
</div>
<section class="card mt-3"><div class="card-body"><div class="card-title-row"><h2>Semester terbaru</h2><a class="card-link" href="{{ route('master.index', 'semester') }}">Kelola</a></div>
<ul class="list-plain">@forelse($semesters as $s)<li><i class="bi bi-calendar3" aria-hidden="true"></i><span>{{ $s->label }} <span class="small text-secondary">({{ $s->code }})</span></span><span class="ms-auto status-badge status-{{ $states[$s->status][1] ?? 'neutral' }}">{{ $states[$s->status][0] ?? $s->status }}</span></li>@empty<li class="text-secondary">Belum ada semester.</li>@endforelse</ul>
<p class="small text-secondary mb-0 mt-2">Aturan nilai dikelola per praktikum melalui menu Aturan Nilai.</p></div></section>
@endsection
