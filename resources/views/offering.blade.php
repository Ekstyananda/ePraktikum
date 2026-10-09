@extends('layouts.app')
@section('context', $o->semester.' · '.$o->name)
@section('content')<x-page-header :title="$o->name" :subtitle="$o->semester.' · Sesi dalam lingkup akses Anda'" :crumbs="['Dashboard' => route('dashboard'), $o->name => null]" />
<div class="d-flex gap-2 flex-wrap mb-4">@if(app(\App\Services\Roster::class)->any(auth()->user(),$o->id,'students.manage'))<a class="btn btn-primary" href="{{ route('students.index',$o->id) }}">Data Praktikan</a>@endif
    @if(app(\App\Services\Roster::class)->any(auth()->user(),$o->id,'sessions.manage'))<a class="btn btn-outline-primary" href="{{ route('sessions.index',$o->id) }}">Sesi & Jadwal</a>@endif
@if(app(\App\Services\Roster::class)->any(auth()->user(),$o->id,'materials.manage') || app(\App\Services\Roster::class)->any(auth()->user(),$o->id,'attendance.manage'))<a class="btn btn-outline-primary" href="{{ route('meetings.index',$o->id) }}">Pertemuan, Modul & Presensi</a>@endif
@if(app(\App\Services\Roster::class)->any(auth()->user(),$o->id,'submissions.manage'))<a class="btn btn-outline-primary" href="{{ route('assignments.index',$o->id) }}">Pengumpulan & Tugas</a>@endif
@if(app(\App\Services\Roster::class)->any(auth()->user(),$o->id,'grades.manage'))<a class="btn btn-outline-primary" href="{{ route('grades.index',$o->id) }}">Penilaian</a>@endif</div>
<div class="card">
    <div class="card-body table-responsive">
        <table class="table table-stack">
            <thead>
                <tr>
                    <th>Sesi</th>
                    <th>Ruangan</th>
                    <th>Lingkup izin</th>
                </tr>
            </thead>
            <tbody>@forelse($sessions as $s)<tr>
                    <td>@if($access->allowed(auth()->user(),$o->id,'sessions.manage',$s->id))<a href="{{ route('session',[$o->id,$s->id]) }}">{{ $s->label }}</a>@else{{ $s->label }}@endif</td>
                    <td>{{ $s->room??'Belum ditentukan' }}</td>
                    <td>
                        <div class="d-flex gap-1 flex-wrap">@foreach(\App\Services\StaffAccess::PERMISSIONS as $key=>$label)@if($access->allowed(auth()->user(),$o->id,$key,$s->id))<span class="badge text-bg-light">{{ $label }}</span>@endif
                            @endforeach</div>
                    </td>
                </tr>@empty<tr>
                    <td colspan="3" class="empty">Belum ada sesi dalam lingkup akses.</td>
                </tr>@endforelse</tbody>
        </table>
    </div>
</div>@endsection