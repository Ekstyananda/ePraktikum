@extends('layouts.app')
@section('context',$o->semester.' · '.$o->name)
@section('content')
<x-page-header :title="($row?'Edit':'Tambah').' pertemuan'" :crumbs="['Pertemuan' => route('meetings.index',$o->id), ($row?'Edit':'Tambah') => null]" />
<form method="post" action="{{ $row?route('meetings.update',[$o->id,$row->id]):route('meetings.store',$o->id) }}" data-dirty-form data-confirm="Simpan pertemuan?" class="card"><div class="card-body">@csrf
@if($row)@method('PUT')<input type="hidden" name="version" value="{{ old('version',$row->version) }}">@endif
<label class="form-label" for="number">Nomor pertemuan</label><input class="form-control mb-3" id="number" name="number" type="number" min="1" max="999" required value="{{ old('number',$row?->number) }}">
<label class="form-label" for="title">Judul pertemuan</label><input class="form-control mb-3" id="title" name="title" maxlength="180" required value="{{ old('title',$row?->title) }}">
@if($row)<label class="form-label" for="reason">Catatan (opsional)</label><textarea class="form-control mb-3" name="reason" maxlength="1000" id="reason">{{ old('reason') }}</textarea><label class="d-block mb-3"><input type="checkbox" name="confirmed" value="1" required> Konfirmasi perubahan pertemuan.</label><p class="small text-secondary">Identitas pada snapshot/cetakan terdahulu tetap utuh.</p>@endif
<button class="btn btn-primary" type="submit">Simpan pertemuan</button></div></form>
@endsection
