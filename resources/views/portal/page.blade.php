@extends('layouts.app')
@section('title', $title.' — Portal Praktikum')
@section('content')
@php
$wib = fn ($utc, $format = 'd/m/Y H:i') => \Carbon\CarbonImmutable::parse($utc, 'UTC')->setTimezone('Asia/Jakarta')->format($format);
$days = [1 => 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'];
$subtitles = ['jadwal' => 'Jadwal sesi dan pelaksanaan pertemuan. Waktu dalam WIB.', 'pengumpulan' => 'Kirim tugas digital sesuai jadwal. Tugas cetak tetap diserahkan langsung ke aslab.', 'pengajuan' => 'Ajukan izin, pindah sesi atau susulan. Setiap pengajuan diperiksa pengelola.', 'remidi' => 'Program remidi yang dibuka pengelola. Kelayakan diperiksa per pengajuan.'];
@endphp
@php $slug = $portal['current_slug']; @endphp
<x-page-header :title="$title" :subtitle="$subtitles[$page]" :crumbs="[$portal['name'] => route('portal.home', $slug), $title => null]" />

@if($page === 'jadwal')
@php $sessionFilter = (int) request('session'); @endphp
<form method="get" class="toolbar">
    <label for="session" class="visually-hidden">Filter sesi</label>
    <select id="session" name="session" class="form-select" data-autosubmit><option value="">Semua sesi</option>@foreach($sessions as $session)<option value="{{ $session->id }}" @selected($sessionFilter === $session->id)>{{ $session->label }}</option>@endforeach</select>
    <noscript><button class="btn btn-outline-primary">Filter</button></noscript>
</form>
<section class="card mb-3"><div class="card-body"><div class="card-title-row"><h2>Sesi praktikum</h2></div></div>
    <div class="table-responsive"><table class="table table-stack"><thead><tr><th>Sesi</th><th>Hari</th><th>Jam · WIB</th><th>Ruang</th><th>Aslab</th></tr></thead><tbody>
        @forelse($sessions->filter(fn ($x) => !$sessionFilter || $x->id === $sessionFilter) as $session)
        <tr><td>{{ $session->label }}</td><td>{{ $days[$session->weekday] ?? 'Belum ditentukan' }}</td><td>{{ $session->start_time ? substr($session->start_time, 0, 5).'–'.substr($session->end_time, 0, 5) : 'Belum ditentukan' }}</td><td>{{ $session->room ?? 'Belum ditentukan' }}</td><td>{{ $session->aslab ?? 'Belum ditentukan' }}</td></tr>
        @empty<tr><td colspan="5" class="empty">Belum ada sesi.</td></tr>@endforelse
    </tbody></table></div>
</section>
<section class="card"><div class="card-body"><div class="card-title-row"><h2>Pelaksanaan pertemuan</h2></div></div>
    <div class="table-responsive"><table class="table table-stack"><thead><tr><th>Tanggal · WIB</th><th>Sesi</th><th>Pertemuan</th><th>Ruang</th></tr></thead><tbody>
        @forelse($executions->filter(fn ($sm) => !$sessionFilter || $sm->session_id === $sessionFilter) as $sm)
        <tr><td>{{ $wib($sm->starts_at, 'd/m/Y') }} · {{ $wib($sm->starts_at, 'H:i') }}–{{ $wib($sm->ends_at, 'H:i') }}</td><td>{{ $sm->label }}</td><td>Pertemuan {{ $sm->number }} · {{ $sm->title }}</td><td>{{ $sm->room }}</td></tr>
        @empty<tr><td colspan="4" class="empty">Belum ada pelaksanaan terjadwal.</td></tr>@endforelse
    </tbody></table></div>
</section>

@elseif($page === 'pengumpulan')
@if($schedules->isEmpty())
<section class="card"><div class="empty"><i class="bi bi-inbox fs-3 d-block mb-2" aria-hidden="true"></i>Belum ada tugas digital aktif. Tugas cetak diserahkan langsung kepada aslab.</div></section>
@else
<div class="row g-3 mb-3">
    @foreach($schedules as $sc)
    @php $open = app(\App\Services\PublicWorkflow::class)->isOpen($sc->opens_at, app(\App\Services\PublicWorkflow::class)->scheduleCloses($sc)); @endphp
    <div class="col-md-6"><section class="card h-100"><div class="card-body">
        <div class="d-flex justify-content-between gap-2 mb-2"><h2 class="mb-0">{{ $sc->title }}</h2><span class="status-badge {{ $open ? 'status-ok' : 'status-neutral' }}">{{ $open ? 'Dibuka' : 'Ditutup' }}</span></div>
        <p class="small text-secondary mb-2">{{ $sc->label }}@if($sc->instructions) · {{ $sc->instructions }}@endif</p>
        <ul class="page-meta"><li><i class="bi bi-door-open" aria-hidden="true"></i>Buka {{ $wib($sc->opens_at) }} WIB</li><li><i class="bi bi-alarm" aria-hidden="true"></i>Tenggat {{ $wib($sc->due_at) }} WIB</li><li><i class="bi bi-lock" aria-hidden="true"></i>{{ $sc->allow_late ? 'Terlambat diterima sampai '.$wib($sc->closes_at).' WIB' : 'Tidak menerima setelah tenggat' }}</li></ul>
    </div></section></div>
    @endforeach
</div>
<form class="card" method="post" action="{{ route('portal.submit', $slug) }}" enctype="multipart/form-data" data-dirty-form data-confirm="Periksa tugas, sesi, identitas dan file. Kirim untuk pemeriksaan pengelola?">@csrf<div class="card-body">
    <h2 class="mb-3"><i class="bi bi-upload text-primary" aria-hidden="true"></i> Kirim tugas</h2>
    @include('portal.identity')
    <div class="row g-3">
        <div class="col-md-6"><label for="schedule_id" class="form-label">Tugas dan sesi pengumpulan</label><select id="schedule_id" name="schedule_id" class="form-select" required><option value="">Pilih tugas</option>@foreach($schedules as $sc)<option value="{{ $sc->id }}" @selected(old('schedule_id') == $sc->id) @disabled(!app(\App\Services\PublicWorkflow::class)->isOpen($sc->opens_at, app(\App\Services\PublicWorkflow::class)->scheduleCloses($sc)))>{{ $sc->title }} · {{ $sc->label }}</option>@endforeach</select></div>
        <div class="col-md-6"><label for="file" class="form-label">File PDF/DOCX/ZIP/TXT/SQL · maks. {{ config('academic.upload_max_kb') / 1024 }} MB</label><input id="file" name="file" type="file" class="form-control" required accept=".pdf,.docx,.zip,.txt,.sql"></div>
    </div>
    <label class="d-block my-3"><input type="checkbox" name="confirmed" value="1" required> Tugas, identitas, sesi dan file sudah saya periksa.</label>
    <button type="submit" class="btn btn-primary"><i class="bi bi-send" aria-hidden="true"></i> Kirim tugas</button>
</div></form>
@endif

@else
@if($page === 'remidi')
<div class="row g-3 mb-3">
    @forelse($programs as $p)
    @php $open = app(\App\Services\PublicWorkflow::class)->isOpen($p->opens_at, $p->closes_at); @endphp
    <div class="col-md-6"><section class="card h-100"><div class="card-body">
        <div class="d-flex justify-content-between gap-2 mb-2"><h2 class="mb-0">{{ $p->title }}</h2><span class="status-badge {{ $open ? 'status-ok' : 'status-neutral' }}">{{ $open ? 'Form dibuka' : 'Form ditutup' }}</span></div>
        <p class="mb-2">{{ $p->instructions }}</p><p class="small text-secondary mb-2">Kelayakan: {{ $p->eligibility_rule }}</p>
        <ul class="page-meta"><li><i class="bi bi-calendar-event" aria-hidden="true"></i>Pelaksanaan {{ $wib($p->scheduled_at) }} WIB · {{ $p->room }}</li><li><i class="bi bi-door-open" aria-hidden="true"></i>Form {{ $wib($p->opens_at) }}–{{ $wib($p->closes_at) }} WIB</li><li><i class="bi bi-paperclip" aria-hidden="true"></i>{{ $p->requires_file ? 'Berkas wajib' : 'Berkas opsional' }}</li></ul>
    </div></section></div>
    @empty
    <div class="col-12"><section class="card"><div class="empty">Belum ada program remidi aktif.</div></section></div>
    @endforelse
</div>
@php $formOpen = $programs->contains(fn ($p) => app(\App\Services\PublicWorkflow::class)->isOpen($p->opens_at, $p->closes_at)); @endphp
@else
@if($window)
<section class="card mb-3"><div class="card-body d-flex gap-3 align-items-start"><span class="stat-icon blue"><i class="bi bi-info-circle" aria-hidden="true"></i></span><div><p class="mb-1">{{ $window->instructions }}</p><p class="small text-secondary mb-0">Form dibuka {{ $wib($window->opens_at) }} sampai {{ $wib($window->closes_at) }} WIB.</p></div></div></section>
@else
<section class="card mb-3"><div class="empty">Periode pengajuan belum ditetapkan pengelola.</div></section>
@endif
@php $formOpen = $window && app(\App\Services\PublicWorkflow::class)->isOpen($window->opens_at, $window->closes_at); @endphp
@endif

@if($formOpen)
<form class="card" method="post" action="{{ $page === 'remidi' ? route('portal.remedial', $slug) : route('portal.request', $slug) }}" enctype="multipart/form-data" data-dirty-form data-confirm="Periksa ringkasan isian Anda. Kirim pengajuan untuk pemeriksaan?" id="request-form">@csrf<div class="card-body">
    @if($page === 'remidi')
    <h2 class="mb-3">Ajukan remidi</h2><input type="hidden" name="type" value="remidi">
    @include('portal.identity')
    <label for="program_id" class="form-label">Program remidi</label><select id="program_id" name="program_id" class="form-select mb-3" required><option value="">Pilih program</option>@foreach($programs as $p)<option value="{{ $p->id }}" @selected(old('program_id') == $p->id) @disabled(!app(\App\Services\PublicWorkflow::class)->isOpen($p->opens_at, $p->closes_at))>{{ $p->title }}</option>@endforeach</select>
    @else
    @php $type = old('type', 'izin'); @endphp
    <div class="nav nav-tabs mb-3" role="radiogroup" aria-label="Jenis pengajuan">
        @foreach(['izin' => 'Izin', 'temporary' => 'Pindah', 'susulan' => 'Susulan'] as $key => $label)
        <label class="nav-link {{ in_array($type, $key === 'temporary' ? ['temporary', 'permanent'] : [$key], true) ? 'active' : '' }}" role="presentation"><input type="radio" class="visually-hidden request-tab" name="tab" value="{{ $key }}" @checked(in_array($type, $key === 'temporary' ? ['temporary', 'permanent'] : [$key], true))>{{ $label }}</label>
        @endforeach
    </div>
    <input type="hidden" name="type" id="type" value="{{ $type }}">
    @include('portal.identity')
    <div class="row g-3 mb-3">
        <div class="col-12" data-for="temporary permanent"><span class="form-label d-block">Jenis pindah</span>
            <label class="me-3"><input type="radio" name="move" value="temporary" class="move-kind" @checked($type !== 'permanent')> Satu pertemuan</label>
            <label><input type="radio" name="move" value="permanent" class="move-kind" @checked($type === 'permanent')> Permanen mulai tanggal tertentu</label></div>
        <div class="col-md-6" data-for="izin temporary susulan"><label for="source_execution_id" class="form-label">Pelaksanaan asal</label><select id="source_execution_id" name="source_execution_id" class="form-select"><option value="">Pilih pelaksanaan pada sesi Anda</option>@foreach($executions as $sm)<option value="{{ $sm->id }}" data-session="{{ $sm->session_id }}" data-meeting="{{ $sm->meeting_id }}" @selected(old('source_execution_id') == $sm->id)>{{ $sm->label }} · Pertemuan {{ $sm->number }} · {{ $wib($sm->starts_at) }}</option>@endforeach</select></div>
        <div class="col-md-6" data-for="temporary permanent"><label for="target_session_id" class="form-label">Sesi tujuan</label><select id="target_session_id" name="target_session_id" class="form-select"><option value="">Pilih sesi tujuan</option>@foreach($sessions as $session)<option value="{{ $session->id }}" @selected(old('target_session_id') == $session->id)>{{ $session->label }}</option>@endforeach</select></div>
        <div class="col-md-6" data-for="temporary"><label for="target_execution_id" class="form-label">Pelaksanaan tujuan (pertemuan yang sama)</label><select id="target_execution_id" name="target_execution_id" class="form-select"><option value="">Pilih pelaksanaan tujuan</option>@foreach($executions as $sm)<option value="{{ $sm->id }}" data-session="{{ $sm->session_id }}" data-meeting="{{ $sm->meeting_id }}" @selected(old('target_execution_id') == $sm->id)>{{ $sm->label }} · Pertemuan {{ $sm->number }} · {{ $wib($sm->starts_at) }}</option>@endforeach</select></div>
        <div class="col-md-6" data-for="permanent"><label for="effective_date" class="form-label">Tanggal efektif</label><input id="effective_date" type="date" name="effective_date" class="form-control" min="{{ now('Asia/Jakarta')->toDateString() }}" value="{{ old('effective_date') }}"></div>
    </div>
    @endif
    <div class="row g-3">
        <div class="col-md-7"><label for="reason" class="form-label">Alasan pengajuan</label><textarea id="reason" name="reason" class="form-control" rows="3" required minlength="5" maxlength="2000">{{ old('reason') }}</textarea></div>
        <div class="col-md-5"><label for="file" class="form-label">Bukti/berkas · maks. {{ config('academic.upload_max_kb') / 1024 }} MB</label><input type="file" id="file" name="file" class="form-control" accept=".pdf,.jpg,.jpeg,.png,.docx,.zip,.txt,.sql"><div class="form-text">PDF, gambar, DOCX, ZIP, TXT atau SQL.</div></div>
    </div>
    <label class="d-block my-3"><input name="confirmed" type="checkbox" value="1" required> Saya telah memeriksa identitas, jenis dan tujuan pengajuan.</label>
    <button type="submit" class="btn btn-primary"><i class="bi bi-send" aria-hidden="true"></i> Kirim pengajuan</button>
</div></form>
@elseif($page === 'pengajuan' && $window)
<section class="card"><div class="empty">Form pengajuan sedang ditutup.</div></section>
@endif
@endif
@endsection
