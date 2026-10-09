@extends('layouts.app')
@section('title', 'Cek Status — Portal Praktikum')
@section('content')
<x-page-header title="Cek Status" subtitle="Masukkan token rahasia dari bukti kiriman. Jangan masukkan NBI." :crumbs="['Beranda' => route('home'), 'Cek Status' => null]" />
@isset($flash)<div class="alert alert-success" role="status">{{ $flash }}</div>@endisset
<section class="card mb-3" id="saved-receipts" hidden><div class="card-body">
    <div class="card-title-row"><h2 class="mb-0"><i class="bi bi-phone" aria-hidden="true"></i> Kiriman tersimpan di perangkat ini</h2><div class="d-flex gap-2"><button type="button" class="btn btn-sm btn-outline-primary" id="check-all"><i class="bi bi-arrow-repeat" aria-hidden="true"></i> Cek semua</button><button type="button" class="btn btn-sm btn-outline-danger" id="forget-all"><i class="bi bi-trash" aria-hidden="true"></i> Hapus semua</button></div></div>
    <p class="small text-secondary">Hanya tersimpan di browser ini. Di komputer lab/umum, hapus setelah selesai.</p>
    <ul class="list-plain saved-list" id="saved-list"></ul>
</div></section>
<form method="post" action="{{ route('public.status.check') }}" class="card mb-3" id="status-form" data-detail="{{ route('public.status.detail') }}" data-batch="{{ route('public.status.batch') }}">@csrf<div class="card-body">
    <label for="token" class="form-label">Token rahasia</label>
    <div class="d-flex gap-2 flex-wrap"><input id="token" name="token" autocomplete="off" spellcheck="false" class="form-control font-monospace flex-grow-1" style="min-width:0;flex-basis:280px" required maxlength="200"><button class="btn btn-primary"><i class="bi bi-search" aria-hidden="true"></i> Periksa status</button></div>
    <p class="small text-secondary mt-2 mb-0">Rincian tidak memuat nilai, nama, NBI atau berkas.</p>
</div></form>
@if($result)
<section class="card"><div class="card-body">@include('portal.status-detail', ['d' => $result])</div></section>
@elseif($checked)
<div class="alert alert-secondary" role="status">{{ \App\Http\Controllers\PublicPortalController::STATUS_NOT_FOUND }}</div>
@endif
@if($revision)
<form method="post" action="{{ route('public.revise') }}" enctype="multipart/form-data" class="card mt-3" data-dirty-form data-confirm="Kirim versi revisi baru?">@csrf<div class="card-body">
    <input type="hidden" name="token" value="{{ $token }}">
    <h2>Revisi diminta</h2><p>Unggah versi baru selama periode tugas masih terbuka. Versi sebelumnya tetap tersimpan.</p>
    <label for="revision-file" class="form-label">Berkas revisi</label><input type="file" id="revision-file" name="file" class="form-control mb-3" accept=".pdf,.docx,.zip,.txt,.sql" required>
    <label class="d-block mb-3"><input type="checkbox" name="confirmed" value="1" required> File revisi sudah saya periksa.</label>
    <button type="submit" class="btn btn-primary"><i class="bi bi-upload" aria-hidden="true"></i> Kirim revisi</button>
</div></form>
@endif
<div class="modal fade" id="status-modal" tabindex="-1" aria-labelledby="status-modal-title" aria-hidden="true"><div class="modal-dialog modal-dialog-scrollable modal-dialog-centered"><div class="modal-content">
    <div class="modal-header"><h2 class="modal-title h5" id="status-modal-title">Rincian status</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button></div>
    <div class="modal-body" id="status-modal-body"></div>
    <div class="modal-footer flex-wrap">
        <form method="post" action="{{ route('public.revise') }}" enctype="multipart/form-data" id="modal-revise" class="w-100" hidden>@csrf<input type="hidden" name="token"><label for="modal-revision-file" class="form-label">Berkas revisi</label><input type="file" id="modal-revision-file" name="file" class="form-control mb-2" accept=".pdf,.docx,.zip,.txt,.sql" required><label class="d-block mb-2"><input type="checkbox" name="confirmed" value="1" required> File revisi sudah saya periksa.</label><button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-upload" aria-hidden="true"></i> Kirim revisi</button></form>
        <button type="button" class="btn btn-outline-primary btn-sm" id="modal-save" hidden><i class="bi bi-bookmark-plus" aria-hidden="true"></i> Simpan ke perangkat ini</button>
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Tutup</button>
    </div>
</div></div></div>
@endsection
@push('scripts')<script src="{{ \App\Support\Asset::url('assets/receipts.js') }}" defer></script>@endpush
