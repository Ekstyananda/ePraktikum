@extends('layouts.app')
@section('context',$o->semester.' · '.$o->name)
@section('content')
<x-page-header :title="$row?'Edit praktikan':'Tambah praktikan'" :crumbs="['Praktikan' => route('students.index',$o->id), ($row?'Edit':'Tambah') => null]" />
<div class="card">
    <div class="card-body">
        <form method="post" action="{{ $row?route('students.update',[$o->id,$row->id]):route('students.store',$o->id) }}" data-dirty-form data-confirm="Simpan data praktikan ini?" novalidate>@csrf
            @if($row) @method('PUT')<input type="hidden" name="version" value="{{ old('version',$row->version) }}"><input type="hidden" name="student_version" value="{{ old('student_version',$row->student_version) }}"> @endif
            <div class="row g-3">@foreach(['nbi'=>'NBI (teks)','name'=>'Nama','sim_class'=>'Simpraktikum','class_category'=>'Kelas'] as $field=>$label)<div class="col-md-6"><label class="form-label" for="{{ $field }}">{{ $label }}</label><input id="{{ $field }}" name="{{ $field }}" class="form-control" value="{{ old($field,$row->$field??'') }}" required @readonly($row&&$field==='nbi' )></div>@endforeach
                <div class="col-md-6"><label for="session_id" class="form-label">Sesi</label><select class="form-select" id="session_id" name="session_id" required>
                        <option value="">Pilih sesi</option>@foreach($sessions as $s)<option value="{{ $s->id }}" @selected(old('session_id',$row->session_id??'')==$s->id)>{{ $s->label }}</option>@endforeach
                    </select></div>
                <div class="col-md-6"><label for="supervisor_id" class="form-label">Dosen pembimbing (opsional)</label><select id="supervisor_id" name="supervisor_id" class="form-select">
                        <option value="">{{ $row?'Pertahankan dosen saat ini':'Belum ditentukan' }}</option>@foreach($supervisors as $sp)<option value="{{ $sp->id }}" @selected(old('supervisor_id',$row->supervisor_id??'')==$sp->id)>{{ $sp->name }}{{ $sp->identity_code?' · '.$sp->identity_code:'' }}</option>@endforeach
                    </select></div>
                @if($row)<div class="col-md-6"><label class="form-label" for="effective_date">Tanggal perubahan sesi</label><input type="date" class="form-control" id="effective_date" name="effective_date" value="{{ old('effective_date',now('Asia/Jakarta')->toDateString()) }}" required>
                    <div class="form-text">Perubahan langsung berlaku hari ini dan menyimpan membership lama. Pengajuan perpindahan terjadwal disiapkan terpisah.</div>
                </div>
                <div class="col-md-6"><input type="hidden" name="active" value="0">
                    <div class="form-check mt-4"><input type="checkbox" class="form-check-input" id="active" name="active" value="1" @checked(old('active',$row->active))><label class="form-check-label" for="active">Enrollment aktif</label></div>
                    <div class="form-check mt-2"><input type="checkbox" class="form-check-input" id="clear_supervisor" name="clear_supervisor" value="1" @checked(old('clear_supervisor',false))><label class="form-check-label" for="clear_supervisor">Kosongkan dosen secara eksplisit</label></div>
                </div>
                <div class="col-12"><label class="form-label" for="reason">Catatan (opsional)</label><textarea class="form-control" id="reason" name="reason" maxlength="1000">{{ old('reason') }}</textarea><label class="d-block mb-3"><input type="checkbox" name="confirmed" value="1" required> Konfirmasi perubahan data praktikan.</label></div>@endif
            </div><button class="btn btn-primary mt-4" type="submit">Simpan praktikan</button>
        </form>
    </div>
</div>
@endsection