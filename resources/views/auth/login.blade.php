@extends('layouts.app')
@section('title','Login Pengelola — Portal Praktikum')
@section('content')<div class="login-wrap">
<div class="card login-card">
    <div class="card-body"><img src="{{ \App\Support\Asset::url('assets/logo-prodi.png') }}" alt="Teknik Informatika Untag Surabaya">
        <h1>Portal Praktikum</h1>
        <p class="text-secondary text-center mb-4">Masuk sebagai pengelola</p>
        <form method="post" action="{{ route('login') }}">@csrf
            <div class="mb-3"><label class="form-label" for="email">Email</label><div class="input-icon"><i class="bi bi-person" aria-hidden="true"></i><input id="email" class="form-control @error('email') is-invalid @enderror" name="email" type="email" autocomplete="username" required value="{{ old('email') }}" autofocus>@error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror</div></div>
            <div class="mb-4"><label class="form-label" for="password">Kata sandi</label><div class="input-icon"><i class="bi bi-lock" aria-hidden="true"></i><input id="password" name="password" type="password" class="form-control @error('password') is-invalid @enderror" autocomplete="current-password" required>@error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror</div></div>
            <button class="btn btn-primary w-100 py-2">Masuk</button>
        </form>
        <p class="small text-secondary mt-4 mb-0 text-center">Akun disediakan administrator. Praktikan tidak perlu login.</p>
    </div>
</div>
</div>@endsection
