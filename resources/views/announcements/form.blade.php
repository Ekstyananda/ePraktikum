@extends('layouts.app')
@section('title', ($row ? 'Edit' : 'Tulis').' pengumuman — Portal Praktikum')
@section('context', $o->semester.' · '.$o->name)
@section('content')
@php $archived = $row && $row->status === 'archived'; @endphp
<x-page-header :title="$row ? $row->title : 'Tulis pengumuman'" :crumbs="['Pengumuman' => route('announcements.index', $o->id), ($row ? 'Edit' : 'Baru') => null]">
    <x-slot:actions>@if($row)<span class="status-badge status-{{ ['draft' => 'neutral', 'published' => 'ok', 'archived' => 'warn'][$row->status] }}">{{ \App\Http\Controllers\AnnouncementController::STATES[$row->status] }}</span>@endif</x-slot:actions>
</x-page-header>
<div class="row g-3">
    <div class="col-lg-7">
        <form method="post" action="{{ $row ? route('announcements.update', [$o->id, $row->id]) : route('announcements.store', $o->id) }}" class="card" data-dirty-form>@csrf<div class="card-body">
            <input type="hidden" name="version" value="{{ $row->version ?? 0 }}">
            <fieldset @disabled($archived)>
            <label class="form-label" for="title">Judul</label><input id="title" name="title" class="form-control mb-3" required maxlength="180" value="{{ old('title', $row->title ?? '') }}">
            <label class="form-label" for="body">Isi</label><textarea id="body" name="body" class="form-control mb-1" rows="10" required maxlength="20000">{{ old('body', $row->body ?? '') }}</textarea>
            <div class="form-text mb-3">Format sederhana: **tebal**, *miring*, daftar dengan "- ", tautan [teks](https://…). HTML tidak diizinkan.</div>
            <div class="row g-3 mb-3">
                <div class="col-sm-6"><label class="form-label" for="audience">Audiens</label><select id="audience" name="audience" class="form-select">@foreach(\App\Http\Controllers\AnnouncementController::AUDIENCES as $key => $label)<option value="{{ $key }}" @selected(old('audience', $row->audience ?? 'public') === $key)>{{ $label }}</option>@endforeach</select></div>
                @if(auth()->user()->role === 'admin')<div class="col-sm-6 d-flex align-items-end"><label><input type="checkbox" name="general" value="1" @checked(old('general', $row && $row->offering_id === null))> Umum (semua praktikum)</label></div>@endif
            </div>
            @if($row)<label class="form-label" for="reason">Catatan (opsional)</label><input id="reason" name="reason" class="form-control mb-3" maxlength="1000">@endif
            <label class="d-block mb-3"><input type="checkbox" name="confirmed" value="1" @checked(old('confirmed'))> Konfirmasi terbit: pengumuman langsung terlihat oleh praktikan <span class="text-secondary small">(wajib saat menerbitkan{{ $row && $row->status === 'published' ? ' atau mengubah pengumuman terbit' : '' }})</span></label>
            <div class="d-flex flex-wrap gap-2"><button type="submit" name="action" value="draft" class="btn btn-outline-primary">Simpan draf</button><button type="submit" name="action" value="publish" class="btn btn-primary"><i class="bi bi-megaphone" aria-hidden="true"></i> {{ $row && $row->status === 'published' ? 'Simpan & tetap terbit' : 'Terbitkan' }}</button></div>
            </fieldset>
        </div></form>
        @if($row && !$archived)
        <form method="post" action="{{ route('announcements.archive', [$o->id, $row->id]) }}" class="card mt-3" data-dirty-form data-confirm="Arsipkan pengumuman ini? Pengumuman tidak lagi tampil.">@csrf<div class="card-body">
            <h2>Arsipkan</h2><input type="hidden" name="version" value="{{ $row->version }}">
            <label class="form-label" for="archive-reason">Catatan (opsional)</label><input id="archive-reason" name="reason" class="form-control mb-2" maxlength="1000">
            <label class="d-block mb-2"><input type="checkbox" name="confirmed" value="1" required> Konfirmasi arsip</label>
            <button type="submit" class="btn btn-outline-danger">Arsipkan</button>
        </div></form>
        @endif
    </div>
    <div class="col-lg-5">
        <section class="card"><div class="card-body"><h2>Pratinjau</h2><div class="announcement-body" id="preview">@if($row){!! \App\Http\Controllers\AnnouncementController::render($row->body) !!}@else<p class="text-secondary">Pratinjau tampil setelah disimpan.</p>@endif</div></div></section>
    </div>
</div>
@endsection
