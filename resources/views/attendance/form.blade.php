@extends('layouts.app')
@section('context',$o->semester.' · '.$o->name)
@section('content')
<x-page-header title="Edit presensi praktikan" :subtitle="$row->nbi.' · '.$row->name.' · '.$row->session_label.' · Identitas snapshot'" :crumbs="['Presensi' => route('attendance.overview',$o->id), $row->session_label => route('attendance.index',[$o->id,$sm->id]), $row->name => null]" />
<form method="post" action="{{ route('attendance.save',[$o->id,$sm->id]) }}" data-dirty-form class="card"><div class="card-body">@csrf<input type="hidden" name="selected[]" value="{{ $row->id }}"><input type="hidden" name="rows[{{ $row->id }}][version]" value="{{ old('rows.'.$row->id.'.version',$row->attendance_version) }}"><input type="hidden" name="action" value="save">
<label class="form-label" for="status">Status</label><select name="rows[{{ $row->id }}][status]" id="status" class="form-select mb-3">@foreach($statuses as $key=>$label)<option value="{{ $key }}" @selected(old('rows.'.$row->id.'.status',$row->status)===$key)>{{ $label }}</option>@endforeach</select>
<label class="form-label" for="note">Catatan</label><textarea class="form-control mb-3" id="note" name="rows[{{ $row->id }}][note]" maxlength="1000">{{ old('rows.'.$row->id.'.note',$row->note) }}</textarea>
<label class="form-label" for="reason">Alasan koreksi — wajib bila data sudah tersimpan</label><textarea class="form-control mb-3" id="reason" name="reason" maxlength="1000">{{ old('reason') }}</textarea><button type="submit" class="btn btn-primary">Simpan presensi</button><p class="small text-secondary mt-3 mb-0">Hanya satu praktikan ini yang disimpan. Alasan, izin, versi dan audit diperiksa sama seperti rekap tabel.</p></div></form>
@endsection
