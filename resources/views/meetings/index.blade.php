@extends('layouts.app')
@section('context',$o->semester.' · '.$o->name)
@section('content')
<x-page-header title="Pertemuan & Modul" subtitle="Pertemuan dan modul berlaku bersama; jadwal diatur per sesi." :crumbs="['Dashboard' => route('dashboard'), 'Pertemuan' => null]">
    <x-slot:actions>@if($canSchedule)<a class="btn btn-outline-primary" href="{{ route('executions.create',$o->id) }}"><i class="bi bi-calendar-plus" aria-hidden="true"></i> Jadwalkan per sesi</a>@endif
    @if($canShared)<a class="btn btn-primary" href="{{ route('meetings.create',$o->id) }}"><i class="bi bi-plus-lg" aria-hidden="true"></i> Tambah pertemuan</a>@endif</x-slot:actions>
</x-page-header>
@if($canShared && $meetings->total()===0)
<form method="post" action="{{ route('meetings.defaults',$o->id) }}" class="card mb-3" data-dirty-form>@csrf<div class="card-body"><h2>Persiapan pertemuan awal</h2><p>Mulai dengan 5 pertemuan, jumlah dapat diubah. Judul dan jadwal dapat diatur setelahnya. Materi resmi belum tersedia.</p><label class="form-label" for="count">Jumlah pertemuan</label><input id="count" name="count" type="number" min="1" max="100" value="{{ old('count',5) }}" class="form-control mb-2" style="max-width:160px"><button type="submit" class="btn btn-primary">Buat pertemuan awal</button></div></form>
@endif
<form method="get" class="toolbar"><label class="visually-hidden" for="q">Cari judul pertemuan</label><input id="q" name="q" class="form-control" placeholder="Cari judul pertemuan" value="{{ request('q') }}"><label class="visually-hidden" for="per_page">Per halaman</label><select id="per_page" name="per_page" class="form-select w-auto">@foreach([10,25,50] as $n)<option value="{{ $n }}" @selected(request('per_page',10)==$n)>{{ $n }} per halaman</option>@endforeach</select><button class="btn btn-outline-primary">Cari</button></form>
<div class="card"><div class="card-body table-responsive"><table class="table table-stack"><thead><tr><th>No</th><th>Pertemuan</th><th>Jadwal per sesi · WIB</th><th>Aksi</th></tr></thead><tbody>
@forelse($meetings as $m)<tr><td>{{ $m->number }}</td><td>{{ $m->title }}</td><td>@forelse($executions->get($m->id,collect()) as $sm)<div class="mb-2"><strong>{{ $sm->session_label }}</strong> · {{ \Carbon\CarbonImmutable::parse($sm->starts_at,'UTC')->setTimezone('Asia/Jakarta')->format('d/m/Y H:i') }} · {{ $sm->room }}<br><span class="badge text-bg-light">{{ $sm->snapshot_created_at?'Snapshot dibekukan':'Terjadwal' }}</span>
@if($roster->access->allowed(auth()->user(),$o->id,'attendance.manage',$sm->session_id))<a class="btn btn-sm btn-outline-primary" href="{{ route('attendance.index',[$o->id,$sm->id]) }}">Presensi · {{ $sm->session_label }}</a>@endif
@if(!$sm->snapshot_created_at && $roster->access->allowed(auth()->user(),$o->id,'materials.manage',$sm->session_id))<a href="{{ route('executions.edit',[$o->id,$sm->id]) }}" class="btn btn-sm btn-outline-secondary">Edit jadwal</a>@endif</div>@empty<span class="text-secondary">Belum dijadwalkan dalam scope Anda.</span>@endforelse</td><td>
@if($roster->any(auth()->user(),$o->id,'materials.manage'))<a class="btn btn-sm btn-outline-primary" href="{{ route('materials.index',[$o->id,$m->id]) }}">Modul · {{ $m->number }}</a>@endif
@if($canShared)<a href="{{ route('meetings.edit',[$o->id,$m->id]) }}" class="btn btn-sm btn-outline-secondary">Edit pertemuan</a>@endif</td></tr>
@empty<tr><td colspan="4" class="empty">Belum ada pertemuan. Pengelola berizin seluruh sesi dapat menyiapkannya.</td></tr>@endforelse
</tbody></table>{{ $meetings->links() }}</div></div>
<p class="small text-secondary mt-3">Pertemuan dan modul digunakan bersama seluruh sesi. Aslab dengan scope terbatas dapat mengatur jadwal sesinya; perubahan data bersama membutuhkan izin seluruh sesi. Jadwal pengumpulan/jenis tugas tersedia pada M4.</p>
@endsection
