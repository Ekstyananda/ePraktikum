@extends('layouts.app')
@section('title', 'Periode pengajuan & remidi — Portal Praktikum')
@section('context', $o->semester.' · '.$o->name)
@section('content')
@php $local = fn ($utc) => $utc ? \Carbon\CarbonImmutable::parse($utc, 'UTC')->setTimezone('Asia/Jakarta')->format('Y-m-d\TH:i') : ''; @endphp
<x-page-header title="Periode pengajuan & program remidi" subtitle="Atur kapan form publik dibuka dan program remidi yang tersedia. Semua waktu dalam WIB." :crumbs="['Pengajuan' => route('requests.index', $o->id), 'Pengaturan' => null]" />
<form method="post" action="{{ route('requests.window', $o->id) }}" class="card mb-4" data-dirty-form data-confirm="Simpan periode pengajuan?">@csrf<div class="card-body">
    <h2>Periode form pengajuan (izin, pindah, susulan)</h2>
    <input type="hidden" name="version" value="{{ $window->version ?? 0 }}">
    <div class="row g-3">
        <div class="col-md-6"><label for="opens_at" class="form-label">Dibuka</label><input type="datetime-local" id="opens_at" name="opens_at" class="form-control" required value="{{ old('opens_at', $local($window->opens_at ?? null)) }}"></div>
        <div class="col-md-6"><label for="closes_at" class="form-label">Ditutup</label><input type="datetime-local" id="closes_at" name="closes_at" class="form-control" required value="{{ old('closes_at', $local($window->closes_at ?? null)) }}"></div>
        <div class="col-12"><label for="instructions" class="form-label">Instruksi untuk praktikan</label><textarea id="instructions" name="instructions" class="form-control" rows="3" required maxlength="5000">{{ old('instructions', $window->instructions ?? '') }}</textarea></div>
        <div class="col-md-8"><label for="window-reason" class="form-label">Catatan (opsional)</label><input id="window-reason" name="reason" class="form-control" maxlength="1000"></div>
    </div>
    <label class="d-block my-3"><input type="checkbox" name="confirmed" value="1" required> Periode sudah sesuai ketentuan.</label>
    <button type="submit" class="btn btn-primary">Simpan periode</button>
</div></form>

<div class="card-title-row"><h2>Program remidi</h2></div>
@if($components->isEmpty())
<section class="card mb-3"><div class="empty">Belum ada komponen nilai aktif. Program remidi memerlukan komponen nilai sebagai nilai awal.</div></section>
@endif
@foreach($programs->concat($components->isEmpty() ? [] : [null]) as $p)
@php $locked = $p && in_array($p->id, $used, true); @endphp
<form method="post" action="{{ route('requests.program', $o->id) }}" class="card mb-3" data-dirty-form data-confirm="Simpan program remidi?">@csrf<div class="card-body">
    <div class="d-flex justify-content-between flex-wrap gap-2 mb-2"><h2 class="mb-0">{{ $p ? $p->title : 'Program baru' }}</h2>@if($p)<span class="status-badge {{ $p->active ? 'status-ok' : 'status-neutral' }}">{{ $p->active ? 'Aktif' : 'Nonaktif' }}</span>@endif</div>
    @if($locked)<p class="small text-secondary">Program sudah dipakai pengajuan; ketentuannya dibekukan. Hanya status aktif yang dapat diubah.</p>@endif
    <input type="hidden" name="id" value="{{ $p?->id }}"><input type="hidden" name="version" value="{{ $p?->version ?? 0 }}">
    @php $suffix = $p ? $p->id : 'new'; @endphp
    <fieldset class="row g-3" @disabled(false)>
        <div class="col-md-6"><label class="form-label" for="title-{{ $suffix }}">Judul</label><input id="title-{{ $suffix }}" name="title" class="form-control" required maxlength="180" value="{{ $p?->title }}" @readonly($locked)></div>
        <div class="col-md-6"><label class="form-label" for="component-{{ $suffix }}">Komponen nilai</label><select id="component-{{ $suffix }}" name="component_id" class="form-select" required @if($locked) style="pointer-events:none" tabindex="-1" @endif>@foreach($components as $c)<option value="{{ $c->id }}" @selected($p?->component_id === $c->id)>{{ $c->label }}</option>@endforeach @if($p && !$components->contains('id', $p->component_id))<option value="{{ $p->component_id }}" selected>{{ $p->component }}</option>@endif</select></div>
        <div class="col-12"><label class="form-label" for="instructions-{{ $suffix }}">Instruksi</label><textarea id="instructions-{{ $suffix }}" name="instructions" class="form-control" rows="2" required maxlength="5000" @readonly($locked)>{{ $p?->instructions }}</textarea></div>
        <div class="col-12"><label class="form-label" for="eligibility-{{ $suffix }}">Ketentuan kelayakan</label><textarea id="eligibility-{{ $suffix }}" name="eligibility_rule" class="form-control" rows="2" required minlength="5" maxlength="2000" @readonly($locked)>{{ $p?->eligibility_rule }}</textarea></div>
        <div class="col-md-4"><label class="form-label" for="opens-{{ $suffix }}">Form dibuka</label><input type="datetime-local" id="opens-{{ $suffix }}" name="opens_at" class="form-control" required value="{{ $local($p?->opens_at) }}" @readonly($locked)></div>
        <div class="col-md-4"><label class="form-label" for="closes-{{ $suffix }}">Form ditutup</label><input type="datetime-local" id="closes-{{ $suffix }}" name="closes_at" class="form-control" required value="{{ $local($p?->closes_at) }}" @readonly($locked)></div>
        <div class="col-md-4"><label class="form-label" for="scheduled-{{ $suffix }}">Pelaksanaan</label><input type="datetime-local" id="scheduled-{{ $suffix }}" name="scheduled_at" class="form-control" required value="{{ $local($p?->scheduled_at) }}" @readonly($locked)></div>
        <div class="col-md-4"><label class="form-label" for="room-{{ $suffix }}">Ruang</label><input id="room-{{ $suffix }}" name="room" class="form-control" required maxlength="150" value="{{ $p?->room }}" @readonly($locked)></div>
        <div class="col-md-4"><label class="form-label" for="file-{{ $suffix }}">Berkas</label><select id="file-{{ $suffix }}" name="requires_file" class="form-select" @if($locked) style="pointer-events:none" tabindex="-1" @endif><option value="0" @selected(!($p?->requires_file))>Opsional</option><option value="1" @selected((bool) $p?->requires_file)>Wajib</option></select></div>
        <div class="col-md-4"><label class="form-label" for="active-{{ $suffix }}">Status</label><select id="active-{{ $suffix }}" name="active" class="form-select"><option value="1" @selected($p === null || $p->active)>Aktif</option><option value="0" @selected($p && !$p->active)>Nonaktif</option></select></div>
        <div class="col-md-8"><label class="form-label" for="reason-{{ $suffix }}">Catatan (opsional)</label><input id="reason-{{ $suffix }}" name="reason" class="form-control" maxlength="1000"></div>
    </fieldset>
    <label class="d-block my-3"><input type="checkbox" name="confirmed" value="1" required> Program sesuai ketentuan resmi.</label>
    <button type="submit" class="btn btn-primary">{{ $p ? 'Simpan program' : 'Tambah program' }}</button>
</div></form>
@endforeach
@endsection
