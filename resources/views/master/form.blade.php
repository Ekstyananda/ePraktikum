@extends('layouts.app')
@section('content')
@php $masterTitle = ['semester'=>'Semester','praktikum'=>'Master Praktikum','offering'=>'Pelaksanaan Praktikum'][$type]; @endphp
<x-page-header :title="($row?'Edit ':'Tambah ').($type==='offering'?'pelaksanaan praktikum':$type)" :crumbs="[$masterTitle => route('master.index',$type), ($row?'Edit':'Tambah') => null]" />
<div class="card">
    <div class="card-body">
        <form method="post" action="{{ $row?route('master.update',[$type,$row->id]):route('master.store',$type) }}" data-dirty-form data-confirm="Simpan data master ini?" novalidate>@csrf
            @if($row) @method('PUT')<input type="hidden" name="version" value="{{ old('version',$row->version) }}"> @endif
            <div class="row g-3">
                @if($type==='offering')
                @foreach(['semester_id'=>['Semester',$semesters,'label'],'practicum_id'=>['Praktikum',$practicums,'name']] as $field=>$def)
                <div class="col-md-6"><label class="form-label" for="{{ $field }}">{{ $def[0] }}</label><select class="form-select" id="{{ $field }}" name="{{ $field }}" required>
                        <option value="">Pilih</option>@foreach($def[1] as $option)<option value="{{ $option->id }}" @selected(old($field,$row->$field??'')==$option->id)>{{ $option->{$def[2]} }}</option>@endforeach
                    </select></div>
                @endforeach
                @else
                @foreach(['code'=>'Kode',($type==='semester'?'label':'name')=>'Nama'] as $field=>$label)
                <div class="col-md-6"><label class="form-label" for="{{ $field }}">{{ $label }}</label><input id="{{ $field }}" class="form-control" name="{{ $field }}" value="{{ old($field,$row->$field??'') }}" required></div>
                @endforeach
                @if($type==='semester')
                @foreach(['starts_at'=>'Tanggal mulai','ends_at'=>'Tanggal selesai'] as $field=>$label)<div class="col-md-6"><label class="form-label" for="{{ $field }}">{{ $label }}</label><input type="date" id="{{ $field }}" class="form-control" name="{{ $field }}" value="{{ old($field,$row->$field??'') }}" required></div>@endforeach
                @else
                <div class="col-12"><label class="form-label" for="description">Deskripsi (opsional)</label><textarea class="form-control" name="description" id="description">{{ old('description',$row->description??'') }}</textarea></div>
                @endif
                @endif
                @if($type!=='praktikum')<div class="col-md-6"><label class="form-label" for="status">Status</label><select id="status" name="status" class="form-select">@foreach(['draft'=>'Draf','active'=>'Aktif'] as $key=>$label)<option value="{{ $key }}" @selected(old('status',$row->status??'draft')===$key)>{{ $label }}</option>@endforeach</select>
                    <div class="form-text">Semester terkunci tidak dapat diubah dari formulir ini.</div>
                </div>@endif
                @if($row)<div class="col-12"><label for="reason" class="form-label">Catatan (opsional)</label><textarea class="form-control" id="reason" name="reason" maxlength="1000">{{ old('reason') }}</textarea><label class="d-block mb-3"><input type="checkbox" name="confirmed" value="1" required> Konfirmasi perubahan data master.</label></div>@endif
            </div>
            <div class="d-flex gap-2 mt-4"><button type="submit" class="btn btn-primary">Simpan</button><a class="btn btn-outline-secondary" href="{{ route('master.index',$type) }}">Batal</a></div>
        </form>
    </div>
</div>
@if($row)<details class="mt-4">
    <summary>Hapus data yang belum digunakan</summary>
    <form method="post" action="{{ route('master.destroy',[$type,$row->id]) }}" class="mt-3" onsubmit="return confirm('Hapus data ini? Data yang sudah digunakan akan ditolak server.');">@csrf @method('DELETE')<input type="hidden" name="version" value="{{ $row->version }}"><label class="form-label" for="delete-reason">Catatan (opsional)</label><input class="form-control mb-3" id="delete-reason" name="reason" maxlength="1000"><label class="d-block mb-3"><input type="checkbox" name="confirmed" value="1" required> Konfirmasi penghapusan.</label><button class="btn btn-outline-danger">Hapus</button></form>
</details>@endif
@endsection