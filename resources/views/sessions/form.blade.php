@extends('layouts.app')
@section('context',$o->semester.' · '.$o->name)
@section('content')
<x-page-header :title="$row?'Edit sesi':'Tambah sesi'" :crumbs="['Sesi' => route('sessions.index',$o->id), ($row?'Edit':'Tambah') => null]" />
<div class="card">
    <div class="card-body">
        <form method="post" action="{{ $row?route('sessions.update',[$o->id,$row->id]):route('sessions.store',$o->id) }}" data-dirty-form data-confirm="Simpan sesi dan jadwal ini?" novalidate>@csrf
            @if($row) @method('PUT')<input type="hidden" name="version" value="{{ old('version',$row->version) }}"> @endif
            <div class="row g-3">
                @foreach(['label'=>['Nama sesi','text'],'room'=>['Ruangan','text'],'capacity'=>['Kapasitas','number'],'start_time'=>['Jam mulai (WIB)','time'],'end_time'=>['Jam selesai (WIB)','time']] as $field=>$def)<div class="col-md-6"><label class="form-label" for="{{ $field }}">{{ $def[0] }}</label><input class="form-control" id="{{ $field }}" type="{{ $def[1] }}" name="{{ $field }}" value="{{ old($field,($def[1]==='time'&&($row->$field??null))?substr($row->$field,0,5):($row->$field??'')) }}" required></div>@endforeach
                <div class="col-md-6"><label class="form-label" for="weekday">Hari</label><select class="form-select" id="weekday" name="weekday">@foreach([1=>'Senin','Selasa','Rabu','Kamis','Jumat','Sabtu','Minggu'] as $key=>$label)<option value="{{ $key }}" @selected(old('weekday',$row->weekday??1)==$key)>{{ $label }}</option>@endforeach</select></div>
                <div class="col-md-6"><label class="form-label" for="responsible_user_id">Penanggung jawab (opsional)</label><select class="form-select" id="responsible_user_id" name="responsible_user_id">
                        <option value="">Belum ditentukan</option>@foreach($staff as $u)<option value="{{ $u->id }}" @selected(old('responsible_user_id',$row->responsible_user_id??'')==$u->id)>{{ $u->name }}</option>@endforeach
                    </select></div>
                @if($row)<div class="col-12"><label class="form-label" for="reason">Catatan (opsional)</label><textarea class="form-control" name="reason" maxlength="1000" id="reason">{{ old('reason') }}</textarea><label class="d-block mb-3"><input type="checkbox" name="confirmed" value="1" required> Konfirmasi perubahan sesi dan jadwal untuk praktikan.</label></div>@endif
            </div><button class="btn btn-primary mt-4" type="submit">Simpan sesi</button>
        </form>
    </div>
</div>
@if($row)<details class="mt-4">
    <summary>Hapus sesi yang belum digunakan</summary>
    <form method="post" action="{{ route('sessions.destroy',[$o->id,$row->id]) }}" class="mt-3" onsubmit="return confirm('Hapus sesi ini? Sesi yang sudah digunakan akan ditolak.');">@csrf @method('DELETE')<input type="hidden" name="version" value="{{ $row->version }}"><label class="form-label" for="delete-reason">Catatan (opsional)</label><input class="form-control mb-3" id="delete-reason" name="reason" maxlength="1000"><label class="d-block mb-3"><input type="checkbox" name="confirmed" value="1" required> Konfirmasi penghapusan sesi.</label><button class="btn btn-outline-danger">Hapus sesi</button></form>
</details>@endif
@endsection