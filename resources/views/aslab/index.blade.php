@extends('layouts.app')
@section('title','Pengaturan Aslab — Portal Praktikum')
@section('content')<x-page-header title="Akun Aslab" subtitle="Klik nama untuk mengatur praktikum, sesi, dan izin individual." :crumbs="['Administrasi' => null, 'Pengaturan Aslab' => null]">
    <x-slot:actions><a href="{{ route('aslab.create') }}" class="btn btn-primary"><i class="bi bi-person-plus" aria-hidden="true"></i> Tambah Aslab</a></x-slot:actions>
</x-page-header>
<div class="card">
    <div class="card-body">
        <form method="get" class="row g-2 mb-4">
            <div class="col-sm-7"><label class="visually-hidden" for="q">Cari nama atau email</label><input class="form-control" id="q" name="q" value="{{ request('q') }}" placeholder="Cari nama atau email"></div>
            <div class="col-sm-3"><label class="visually-hidden" for="per_page">Jumlah per halaman</label><select id="per_page" name="per_page" class="form-select">@foreach([10,25,50] as $n)<option value="{{ $n }}" @selected(request('per_page',10)==$n)>{{ $n }} per halaman</option>@endforeach</select></div>
            <div class="col-sm-2"><button class="btn btn-outline-primary w-100">Cari</button></div>
        </form>
        <div class="table-responsive">
            <table class="table table-stack">
                <thead>
                    <tr>
                        <th>Nama</th>
                        <th>Email</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>@forelse($users as $u)<tr>
                        <td class="sticky-name"><a class="fw-semibold" href="{{ route('aslab.edit',$u) }}">{{ $u->name }}</a></td>
                        <td>{{ $u->email }}</td>
                        <td><span class="badge {{ $u->active?'text-bg-success':'text-bg-secondary' }}">{{ $u->active?'Aktif':'Nonaktif' }}</span></td>
                        <td><a href="{{ route('aslab.edit',$u) }}">Atur akses</a></td>
                    </tr>@empty<tr>
                        <td colspan="4" class="empty">Tidak ada akun aslab yang cocok.</td>
                    </tr>@endforelse</tbody>
            </table>
        </div>{{ $users->links() }}
    </div>
</div>@endsection