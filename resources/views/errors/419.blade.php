@extends('layouts.app')
@section('title','Sesi berakhir — Portal Praktikum')
@section('content')<div class="card">
    <div class="card-body">
        <h1>Sesi formulir berakhir</h1>
        <p>Muat ulang halaman sebelum mengirim kembali. Perubahan ini belum disimpan.</p><a href="{{ url()->current() }}" class="btn btn-primary">Muat ulang halaman</a>
    </div>
</div>@endsection