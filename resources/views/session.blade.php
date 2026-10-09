@extends('layouts.app')
@section('content')<x-page-header :title="$s->label" :crumbs="['Dashboard' => route('dashboard'), 'Sesi' => route('sessions.index',$s->offering_id), $s->label => null]" />
<div class="card">
    <div class="card-body">
        <h2>Identitas sesi</h2>
        <dl class="row mb-0">
            <dt class="col-sm-3">Ruangan</dt>
            <dd class="col-sm-9">{{ $s->room??'Belum ditentukan' }}</dd>
            <dt class="col-sm-3">Kapasitas</dt>
            <dd class="col-sm-9">{{ $s->capacity??'Belum ditentukan' }}</dd>
        </dl>
        <p class="small text-secondary mt-3 mb-0">Akses telah diverifikasi di server. <a href="{{ route('sessions.edit',[$s->offering_id,$s->id]) }}">Edit jadwal sesi</a></p>
    </div>
</div>@endsection