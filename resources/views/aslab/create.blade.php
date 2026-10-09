@extends('layouts.app')
@section('title','Tambah Aslab — Portal Praktikum')
@section('content')<x-page-header title="Tambah Aslab" subtitle="Akun baru dapat ditugaskan ke praktikum setelah disimpan." :crumbs="['Pengaturan Aslab' => route('aslab.index'), 'Tambah' => null]" />
<div class="card">
    <div class="card-body">
        <form method="post" action="{{ route('aslab.store') }}" data-dirty-form novalidate>@csrf<div class="row g-3">
                <div class="col-md-6"><label class="form-label" for="name">Nama</label><input class="form-control" id="name" name="name" value="{{ old('name') }}" required maxlength="150"></div>
                <div class="col-md-6"><label class="form-label" for="email">Email</label><input class="form-control" id="email" name="email" type="email" value="{{ old('email') }}" required></div>
                <div class="col-md-6"><label class="form-label" for="password">Kata sandi awal</label><input class="form-control" id="password" name="password" type="password" autocomplete="new-password" required>
                    <div class="form-text">Minimal 12 karakter, huruf besar/kecil dan angka. Tidak ada kata sandi default.</div>
                </div>
                <div class="col-md-6"><label class="form-label" for="password_confirmation">Konfirmasi kata sandi</label><input class="form-control" id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required></div>
            </div>
            <div class="mt-4 d-flex gap-2"><button type="submit" class="btn btn-primary">Buat akun</button><a class="btn btn-outline-secondary" href="{{ route('aslab.index') }}">Batal</a></div>
        </form>
    </div>
</div>@endsection