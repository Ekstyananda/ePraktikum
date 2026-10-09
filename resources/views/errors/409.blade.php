@extends('layouts.app')
@section('title','Konflik perubahan — Portal Praktikum')
@section('content')<div class="card">
    <div class="card-body">
        <h1>Perubahan belum disimpan</h1>
        <p>Data telah berubah, atau pratinjau sudah dipakai/kedaluwarsa. Muat ulang data dan periksa perubahan pengelola lain sebelum menyimpan. Bila memakai pratinjau, buat pratinjau baru.</p><a class="btn btn-primary" href="{{ request()->route('offering')?route('offering',request()->route('offering')):url()->current() }}">Kembali ke data</a>
    </div>
</div>@endsection