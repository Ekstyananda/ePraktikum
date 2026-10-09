@extends('layouts.app')
@section('title','Data terkunci — Portal Praktikum')
@section('content')<div class="card">
    <div class="card-body">
        <h1>Data terkunci</h1>
        <p>Semester/pelaksanaan telah dikunci, hasil nilai sudah final, atau jadwal sudah dibekukan bersama peserta. Perubahan ini belum disimpan.</p><a class="btn btn-primary" href="{{ route('dashboard') }}">Kembali ke dashboard</a>
    </div>
</div>@endsection