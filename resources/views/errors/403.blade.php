@extends('layouts.app')
@section('title','Akses ditolak — Portal Praktikum')
@section('content')<div class="card">
    <div class="card-body">
        <h1>Akses ditolak</h1>
        <p>Akun Anda tidak memiliki izin untuk membuka halaman atau menjalankan tindakan ini.</p><a href="{{ auth()->check()?route('dashboard'):route('login') }}" class="btn btn-primary">{{ auth()->check()?'Kembali ke dashboard':'Kembali ke login' }}</a>
    </div>
</div>@endsection