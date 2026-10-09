@extends('layouts.app')
@section('title', 'Bukti kiriman — Portal Praktikum')
@section('content')
<x-page-header title="Bukti kiriman privat" :subtitle="$kind.' tercatat dan menunggu pemeriksaan pengelola.'" :crumbs="['Beranda' => route('home'), 'Bukti kiriman' => null]" />
<section class="card receipt-card"><div class="card-body">
    <div class="alert alert-warning d-flex gap-2"><i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i><div><strong>Simpan token ini sekarang.</strong> Token hanya ditampilkan sekali dan diperlukan untuk Cek Status atau mengirim revisi yang diminta. Jangan bagikan kepada orang lain.</div></div>
    <label for="receipt-token" class="form-label">Token rahasia</label>
    <input id="receipt-token" class="form-control font-monospace mb-3" value="{{ $token }}" readonly>
    <div class="save-device mb-3" id="save-device" data-kind="{{ $kind }}" data-practicum="{{ $portal['name'] ?? '' }}" data-slug="{{ $portal['current_slug'] ?? '' }}" hidden>
        <label class="d-block fw-semibold"><input type="checkbox" id="save-token"> Simpan token di perangkat ini (hanya HP/laptop pribadi)</label>
        <div class="small text-secondary">Jangan centang di komputer lab/umum: siapa pun yang memakai perangkat ini bisa melihat status dan mengirim revisi. Token tersimpan bisa dihapus kapan saja di Cek Status.</div>
    </div>
    <p class="small text-secondary">Diterima {{ now('Asia/Jakarta')->format('d/m/Y H:i') }} WIB · {{ $kind }}</p>
    <div class="d-flex flex-wrap gap-2"><button type="button" class="btn btn-primary" id="copy-token"><i class="bi bi-clipboard" aria-hidden="true"></i> Salin token</button><button type="button" class="btn btn-outline-primary" id="share-token" hidden><i class="bi bi-share" aria-hidden="true"></i> Kirim ke diri sendiri</button><button type="button" class="btn btn-outline-primary" id="print-receipt"><i class="bi bi-printer" aria-hidden="true"></i> Cetak bukti</button><a href="{{ route('public.status') }}" class="btn btn-outline-secondary"><i class="bi bi-search" aria-hidden="true"></i> Cek Status</a></div>
    <p id="copy-result" role="status" class="small mt-3 mb-0">Bukti tidak memuat nilai atau file.</p>
</div></section>
@endsection
@push('scripts')<script src="{{ \App\Support\Asset::url('assets/receipts.js') }}" defer></script><script>document.querySelector('#copy-token').addEventListener('click',async()=>{const input=document.querySelector('#receipt-token');input.select();try{await navigator.clipboard.writeText(input.value);document.querySelector('#copy-result').textContent='Token disalin.';}catch(e){document.querySelector('#copy-result').textContent='Token dipilih. Gunakan Salin pada perangkat Anda.';}});document.querySelector('#print-receipt').addEventListener('click',()=>window.print());</script>@endpush
