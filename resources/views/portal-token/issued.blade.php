@extends('layouts.app')
@section('title', 'Token baru — Portal Praktikum')
@section('context', $o->semester.' · '.$o->name)
@section('content')
<x-page-header title="Token baru diterbitkan" :subtitle="$label.' · token lama sudah tidak berlaku.'" :crumbs="['Kembali' => $back, 'Token baru' => null]" />
<section class="card receipt-card"><div class="card-body">
    <div class="alert alert-warning d-flex gap-2"><i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i><div><strong>Token hanya tampil sekali di halaman ini.</strong> Berikan langsung kepada praktikan pemilik kiriman. Jangan kirim ke grup, jangan simpan di catatan bersama.</div></div>
    <label for="receipt-token" class="form-label">Token baru</label>
    <input id="receipt-token" class="form-control font-monospace mb-3" value="{{ $token }}" readonly>
    <div class="d-flex flex-wrap gap-2"><button type="button" class="btn btn-primary" id="copy-token"><i class="bi bi-clipboard" aria-hidden="true"></i> Salin token</button><a href="{{ $back }}" class="btn btn-outline-secondary">Selesai</a></div>
    <p id="copy-result" role="status" class="small mt-3 mb-0">Praktikan memakai token ini di Cek Status, termasuk untuk mengirim revisi.</p>
</div></section>
@endsection
@push('scripts')<script>document.querySelector('#copy-token').addEventListener('click',async()=>{const i=document.querySelector('#receipt-token');i.select();try{await navigator.clipboard.writeText(i.value);document.querySelector('#copy-result').textContent='Token disalin.';}catch(e){document.querySelector('#copy-result').textContent='Token dipilih. Gunakan Salin pada perangkat Anda.';}});</script>@endpush
