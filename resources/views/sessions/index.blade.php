@extends('layouts.app')
@section('context',$o->semester.' · '.$o->name)
@section('content')
<x-page-header title="Sesi & Jadwal" :subtitle="$o->name.' · '.$o->semester" :crumbs="['Dashboard' => route('dashboard'), 'Sesi' => null]">
    <x-slot:actions>@if($canCreate)<a class="btn btn-primary" href="{{ route('sessions.create',$o->id) }}"><i class="bi bi-plus-lg" aria-hidden="true"></i> Tambah sesi</a>@endif</x-slot:actions>
</x-page-header>
<div class="card">
    <div class="card-body">
        <form method="get" class="toolbar"><label class="visually-hidden" for="q">Cari sesi</label><input id="q" class="form-control" name="q" value="{{ request('q') }}" placeholder="Cari sesi atau ruangan"><x-per-page /><button class="btn btn-outline-primary">Cari</button></form>
        <div class="table-responsive">
            <table class="table table-stack">
                <thead>
                    <tr>
                        <th>Sesi</th>
                        <th>Hari</th>
                        <th>Waktu WIB</th>
                        <th>Ruangan</th>
                        <th>Kapasitas</th>
                        <th>Penanggung jawab</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($rows as $s)<tr>
                        <td>{{ $s->label }}</td>
                        <td>{{ [1=>'Senin','Selasa','Rabu','Kamis','Jumat','Sabtu','Minggu'][$s->weekday]??'Belum ditentukan' }}</td>
                        <td>{{ $s->start_time?substr($s->start_time,0,5).'–'.substr($s->end_time,0,5):'Belum ditentukan' }}</td>
                        <td>{{ $s->room??'Belum ditentukan' }}</td>
                        <td>{{ $s->capacity??'Belum ditentukan' }}</td>
                        <td>{{ $s->responsible_name??'Belum ditentukan' }}</td>
                        <td><a href="{{ route('sessions.edit',[$o->id,$s->id]) }}">Edit</a></td>
                    </tr>@empty<tr>
                        <td colspan="7" class="empty">Belum ada sesi dalam lingkup akses.</td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>{{ $rows->links() }}
    </div>
</div>
<p class="small text-secondary mt-3">Jadwal mingguan ini menjadi dasar pelaksanaan. Tanggal pertemuan dan arsip peserta akan dikelola melalui layanan pertemuan.</p>
@endsection