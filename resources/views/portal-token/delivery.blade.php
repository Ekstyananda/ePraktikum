@extends('layouts.app')
@section('title', 'Token baru kiriman — Portal Praktikum')
@section('context', $o->semester.' · '.$o->name)
@section('content')
<x-page-header title="Token baru kiriman digital" :subtitle="$row->title.' · '.$row->session_label" :crumbs="['Kiriman digital' => route('deliveries.index', $o->id), '#'.$row->id => null]" />
<div class="row g-3"><div class="col-lg-5">
    <section class="card"><div class="card-body"><h2>Pemilik kiriman</h2>
        <dl class="row mb-0 small-dl"><dt class="col-4">NBI</dt><dd class="col-8">{{ $row->nbi }}</dd><dt class="col-4">Nama</dt><dd class="col-8">{{ $row->name }}</dd><dt class="col-4">Dikirim</dt><dd class="col-8">{{ \Carbon\CarbonImmutable::parse($row->received_at, 'UTC')->setTimezone('Asia/Jakarta')->format('d/m/Y H:i') }} WIB</dd></dl>
        <p class="small text-secondary mt-2 mb-0">Cocokkan data ini dengan praktikan yang meminta token.</p>
    </div></section>
</div><div class="col-lg-7">@include('portal-token._form', ['action' => route('deliveries.reissue', [$o->id, $row->id])])</div></div>
@endsection
