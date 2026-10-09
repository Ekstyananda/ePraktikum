@extends('layouts.app')
@section('context',$o->semester.' · '.$o->name)
@section('content')
<x-page-header :title="$row?'Edit jadwal':'Jadwalkan pertemuan per sesi'" :crumbs="['Pertemuan' => route('meetings.index',$o->id), 'Jadwal' => null]" />
@if($meetings->isEmpty() || $sessions->isEmpty())<div class="card"><div class="card-body empty">Siapkan pertemuan dan sesi berizin terlebih dahulu.</div></div>@else
<form method="post" action="{{ $row?route('executions.update',[$o->id,$row->id]):route('executions.store',$o->id) }}" data-dirty-form data-confirm="Simpan jadwal pertemuan?" class="card"><div class="card-body">@csrf
@if($row)@method('PUT')<input type="hidden" name="version" value="{{ old('version',$row->version) }}">@endif
<label class="form-label" for="meeting_id">Pertemuan</label><select id="meeting_id" name="meeting_id" class="form-select mb-3" required>@foreach($meetings as $m)<option value="{{ $m->id }}" @selected(old('meeting_id',$row?->meeting_id)==$m->id)>{{ $m->number }} · {{ $m->title }}</option>@endforeach</select>
<label class="form-label" for="session_id">Sesi</label><select id="session_id" name="session_id" class="form-select mb-3" required>@foreach($sessions as $s)<option value="{{ $s->id }}" @selected(old('session_id',$row?->session_id)==$s->id)>{{ $s->label }}</option>@endforeach</select>
@foreach(['starts_at'=>'Mulai · WIB','ends_at'=>'Selesai · WIB'] as $field=>$label)<label class="form-label" for="{{ $field }}">{{ $label }}</label><input id="{{ $field }}" name="{{ $field }}" type="datetime-local" required class="form-control mb-3" value="{{ old($field,$row?\Carbon\CarbonImmutable::parse($row->$field,'UTC')->setTimezone('Asia/Jakarta')->format('Y-m-d\TH:i'):'') }}">@endforeach
<label class="form-label" for="room">Ruangan</label><input class="form-control mb-3" id="room" name="room" maxlength="150" required value="{{ old('room',$row?->room) }}">
@if($row)<label class="form-label" for="reason">Catatan (opsional)</label><textarea class="form-control mb-3" name="reason" maxlength="1000" id="reason">{{ old('reason') }}</textarea><label class="d-block mb-3"><input type="checkbox" name="confirmed" value="1" required> Konfirmasi perubahan jadwal untuk praktikan.</label>@endif
<p class="small text-secondary">Jadwal tidak dapat dipindah setelah peserta dibekukan. Cetak/presensi memakai tanggal ini untuk menentukan membership peserta.</p><button class="btn btn-primary" type="submit">Simpan jadwal</button></div></form>
@endif
@endsection
