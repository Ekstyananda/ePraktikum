@extends('layouts.app')
@section('title', $name.' — Portal Praktikum')
@section('content')
<section class="card"><div class="empty">
    <i class="bi bi-door-closed fs-2 d-block mb-2" aria-hidden="true"></i>
    <h1 class="h4">{{ $name }} sedang tidak dibuka</h1>
    <p class="mb-3">Praktikum ini belum memiliki periode aktif atau semesternya sudah ditutup. Hubungi aslab bila Anda membutuhkan informasi.</p>
    <a class="btn btn-primary" href="{{ route('home') }}"><i class="bi bi-grid" aria-hidden="true"></i> Lihat praktikum lain</a>
</div></section>
@endsection
