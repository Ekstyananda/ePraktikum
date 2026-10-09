@extends('layouts.app')
@section('context',$o->semester.' · '.$o->name)
@section('content')
@php
$meta = $sm->snapshot_meta ? json_decode($sm->snapshot_meta,true) : null;
$sessionLabel = $meta ? $meta['session'] : DB::table('practicum_sessions')->where('id',$sm->session_id)->value('label');
$number = $meta ? $meta['number'] : $meeting->number;
$start = \Carbon\CarbonImmutable::parse($sm->starts_at,'UTC')->setTimezone('Asia/Jakarta');
$end = \Carbon\CarbonImmutable::parse($sm->ends_at,'UTC')->setTimezone('Asia/Jakarta');
$access = app(\App\Services\StaffAccess::class);
$siblings = DB::table('session_meetings as x')->join('practicum_sessions as s','s.id','=','x.session_id')->join('meetings as m','m.id','=','x.meeting_id')->where('x.offering_id',$o->id)->orderBy('m.number')->orderBy('s.label')->select('x.id','x.session_id','s.label','m.number')->get()->filter(fn($x)=>$access->allowed(auth()->user(),$o->id,'attendance.manage',$x->session_id));
@endphp
<x-page-header :title="$sessionLabel.' / Pertemuan '.$number" :subtitle="'Presensi Praktikum · '.($meta ? $meta['title'] : $meeting->title)" :crumbs="['Presensi' => route('attendance.overview',$o->id), $sessionLabel => null, 'Pertemuan '.$number => null]">
    <x-slot:meta><ul class="page-meta">
        <li><i class="bi bi-calendar3" aria-hidden="true"></i>{{ $start->locale('id')->translatedFormat('l, d F Y') }}</li>
        <li><i class="bi bi-clock" aria-hidden="true"></i>{{ $start->format('H:i') }}–{{ $end->format('H:i') }} WIB</li>
        <li><i class="bi bi-geo-alt" aria-hidden="true"></i>{{ $sm->room }}</li>
    </ul></x-slot:meta>
</x-page-header>
<div class="toolbar">
    @if($siblings->count() > 1)
    <label for="execution-jump" class="small text-secondary">Pindah pelaksanaan</label>
    <select id="execution-jump" class="form-select form-select-sm w-auto" data-navigate>
        @foreach($siblings as $x)<option value="{{ route('attendance.index',[$o->id,$x->id]) }}" @selected($x->id===$sm->id)>Pertemuan {{ $x->number }} · {{ $x->label }}</option>@endforeach
    </select>
    @endif
    @if($sm->snapshot_created_at)
    <span class="spacer"></span>
    <span class="status-badge status-info">Snapshot dibekukan</span>
    <a href="{{ route('attendance.print',[$o->id,$sm->id]) }}" class="btn btn-outline-primary" target="_blank" rel="noopener"><i class="bi bi-printer" aria-hidden="true"></i> Cetak TTD A4</a>
    <a href="#scan" class="btn btn-outline-primary"><i class="bi bi-upload" aria-hidden="true"></i> Unggah scan</a>
    @endif
</div>
@if(!$sm->snapshot_created_at)
<form method="post" action="{{ route('attendance.snapshot',[$o->id,$sm->id]) }}" data-dirty-form data-confirm="Bekukan peserta dan identitas untuk arsip presensi/cetak?" class="card"><div class="card-body">@csrf<input type="hidden" name="version" value="{{ $sm->version }}">
    <div class="d-flex gap-3 align-items-start"><span class="stat-icon cyan"><i class="bi bi-snow" aria-hidden="true"></i></span><div>
    <h2>Siapkan snapshot peserta</h2><p>Peserta aktif ditentukan dari membership pada tanggal pertemuan. Setelah dibekukan, perubahan nama, kelas, sesi dan jadwal tidak mengubah arsip ini. Presensi awal Belum dicatat.</p><label class="d-block mb-3"><input name="confirmed" type="checkbox" value="1" required> Saya telah memeriksa roster dan jadwal pelaksanaan.</label><button type="submit" class="btn btn-primary">Bekukan peserta</button></div></div></div></form>
@else
<form method="get" class="toolbar">
    <label class="visually-hidden" for="q">Cari NBI/nama snapshot</label><input id="q" class="form-control" name="q" placeholder="Cari NBI/nama" value="{{ request('q') }}">
    <label class="visually-hidden" for="status">Status</label><select name="status" id="status" class="form-select"><option value="">Semua status</option>@foreach($statuses as $key=>$label)<option value="{{ $key }}" @selected(request('status')===$key)>{{ $label }}</option>@endforeach</select>
    <label class="visually-hidden" for="per_page">Per halaman</label><select id="per_page" name="per_page" class="form-select">@foreach([10,25,50,100] as $n)<option value="{{ $n }}" @selected(request('per_page',25)==$n)>{{ $n }} per halaman</option>@endforeach</select>
    <button class="btn btn-outline-primary"><i class="bi bi-funnel" aria-hidden="true"></i> Filter</button>
</form>
<form method="post" action="{{ route('attendance.save',[$o->id,$sm->id]) }}" id="attendance-form" data-dirty-form class="card mb-3">@csrf
    <div class="table-responsive"><table class="table table-compact roster-table attendance-table"><thead><tr><th><input type="checkbox" id="attendance-page" aria-label="Pilih halaman presensi" class="form-check-input"></th><th>No</th><th class="sticky-nbi">NBI</th><th class="sticky-student">Nama</th><th>Status</th><th>Rekap</th><th>Catatan</th><th>Sesi</th></tr></thead><tbody>
    @forelse($rows as $p)<tr data-participant="{{ $p->id }}">
        <td><input class="form-check-input attendance-select" type="checkbox" name="selected[]" value="{{ $p->id }}" aria-label="Pilih {{ $p->name }}" @checked(in_array($p->id,old('selected',[])))><input type="hidden" name="rows[{{ $p->id }}][version]" value="{{ old('rows.'.$p->id.'.version',$p->attendance_version) }}"></td>
        <td>{{ $p->print_order }}</td>
        <td class="sticky-nbi">{{ $p->nbi }}</td>
        <td class="sticky-student">{{ $p->name }} <a class="row-link ms-1" href="{{ route('attendance.edit',[$o->id,$sm->id,$p->id]) }}" title="Edit presensi {{ $p->name }}"><i class="bi bi-pencil-square" aria-hidden="true"></i><span class="visually-hidden">Edit presensi</span></a></td>
        <td><label class="visually-hidden" for="status-{{ $p->id }}">Status {{ $p->name }}</label><select name="rows[{{ $p->id }}][status]" id="status-{{ $p->id }}" class="form-select form-select-sm status-select">@foreach($statuses as $key=>$label)<option value="{{ $key }}" @selected(old('rows.'.$p->id.'.status',$p->status)===$key)>{{ $label }}</option>@endforeach</select></td>
        <td><span class="small {{ $p->recorded_at ? 'text-success' : 'text-secondary' }}">{{ $p->recorded_at?'Tersimpan':'Belum direkap' }}</span></td>
        <td><label class="visually-hidden" for="note-{{ $p->id }}">Catatan {{ $p->name }}</label><input class="form-control form-control-sm" id="note-{{ $p->id }}" name="rows[{{ $p->id }}][note]" maxlength="1000" value="{{ old('rows.'.$p->id.'.note',$p->note) }}" placeholder="-"></td>
        <td>{{ $p->session_label }}</td>
    </tr>
    @empty<tr><td colspan="8" class="empty">Tidak ada peserta sesuai filter.</td></tr>@endforelse
    </tbody></table></div>
    <div class="card-body border-top">
        <p id="attendance-count" class="small text-secondary">0 baris dipilih pada halaman aktif.</p>
        <div class="row g-3 align-items-end">
            <div class="col-lg-7"><label class="form-label" for="reason">Alasan koreksi — wajib bila presensi sebelumnya sudah tersimpan</label><textarea class="form-control" rows="2" id="reason" name="reason" maxlength="1000">{{ old('reason') }}</textarea></div>
            <div class="col-lg-5"><div class="d-flex gap-2 flex-wrap"><button type="submit" name="action" value="save" class="btn btn-primary attendance-save"><i class="bi bi-floppy" aria-hidden="true"></i> Simpan pilihan</button><button type="submit" name="action" value="present" class="btn btn-outline-primary attendance-save">Hadir untuk pilihan</button></div></div>
        </div>
        <p class="small text-secondary mt-3 mb-0">Pilihan hanya halaman aktif. Baris yang tidak dicentang tidak berubah, termasuk ketika statusnya diedit. Tidak ada otomatis Alpa.</p>
    </div>
</form>
{{ $rows->links() }}
<section class="card mt-3" id="scan"><div class="card-body"><h2><i class="bi bi-file-earmark-lock" aria-hidden="true"></i> Scan TTD privat — opsional</h2><p class="small text-secondary">PDF/JPG/PNG, maksimum {{ config('academic.upload_max_kb')/1024 }} MB. Scan tambahan mempertahankan berkas sebelumnya.</p>
    <form method="post" action="{{ route('attendance.upload',[$o->id,$sm->id]) }}" enctype="multipart/form-data" data-dirty-form>@csrf
        <div class="row g-3"><div class="col-md-6"><label for="file" class="form-label">Berkas scan</label><input type="file" class="form-control" id="file" name="file" accept=".pdf,.jpg,.jpeg,.png" required></div><div class="col-md-6"><label class="form-label" for="scan-note">Catatan scan</label><textarea name="note" id="scan-note" class="form-control" rows="1" maxlength="1000">{{ old('note') }}</textarea></div></div>
        <button type="submit" class="btn btn-primary mt-3">Simpan scan</button></form>
    <ul class="list-plain mt-3">@forelse($documents as $doc)<li><i class="bi bi-paperclip" aria-hidden="true"></i><a href="{{ route('attendance.download',[$o->id,$sm->id,$doc->id]) }}">{{ $doc->original_name }}</a> · {{ \Carbon\CarbonImmutable::parse($doc->created_at,'UTC')->setTimezone('Asia/Jakarta')->format('d/m/Y H:i') }} WIB @if($doc->note)<span class="text-secondary">· {{ $doc->note }}</span>@endif</li>@empty<li class="text-secondary">Belum ada scan. Rekap presensi tidak mewajibkan attachment.</li>@endforelse</ul></div></section>
@endif
@endsection
