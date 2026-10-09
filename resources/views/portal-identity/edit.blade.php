@extends('layouts.app')
@section('title', 'Tampilan Portal — Portal Praktikum')
@section('context', $o->semester.' · '.$o->name)
@section('content')
@php
$isAdmin = auth()->user()->role === 'admin';
$c = \App\Services\PracticumPortal::palette($p);
$url = $p->slug ? url('/'.$p->slug) : null;
@endphp
<x-page-header title="Tampilan Portal" :subtitle="'Identitas halaman publik '.\App\Services\PracticumPortal::displayName($p).' untuk praktikan.'" :crumbs="['Dashboard' => route('dashboard'), 'Tampilan Portal' => null]" />
<div class="alert alert-info d-flex gap-2" role="note"><i class="bi bi-info-circle-fill" aria-hidden="true"></i><div>Pengaturan ini melekat pada <strong>praktikum {{ $p->name }}</strong> dan berlaku untuk <strong>semua semester</strong>{{ $semesters > 1 ? ' ('.$semesters.' pelaksanaan)' : '' }}. Menonaktifkan layanan menutupnya di seluruh portal praktikum ini. Tenggat dan periode tugas tetap diatur per pelaksanaan dan sesi.</div></div>
@unless($isPublicOffering)
<div class="alert alert-warning" role="status">@if($active)Portal publik saat ini menampilkan pelaksanaan <strong>{{ $active->semester }}</strong>, bukan pelaksanaan yang sedang dibuka.@else Praktikum ini belum tampil di portal publik: belum ada pelaksanaan berstatus Aktif pada semester yang tidak terkunci.@endif</div>
@endunless
<div class="row g-3">
    <div class="col-lg-7">
        <form method="post" action="{{ route('portal-identity.update', $o->id) }}" enctype="multipart/form-data" class="card" data-dirty-form data-confirm="Simpan tampilan portal? Perubahan langsung terlihat oleh praktikan.">@csrf<div class="card-body">
            <input type="hidden" name="version" value="{{ $p->portal_version }}">
            <h2>Identitas</h2>
            <div class="row g-3 mb-3">
                <div class="col-sm-4"><label class="form-label" for="short_name">Singkatan</label><input id="short_name" name="short_name" class="form-control" required maxlength="20" value="{{ old('short_name', $p->short_name) }}" placeholder="SBD"></div>
                <div class="col-sm-8"><label class="form-label" for="display_name">Nama tampil</label><input id="display_name" name="display_name" class="form-control" maxlength="150" value="{{ old('display_name', $p->display_name) }}" placeholder="{{ $p->name }}"><div class="form-text">Kosong = memakai nama master "{{ $p->name }}".</div></div>
                <div class="col-12"><label class="form-label" for="tagline">Deskripsi singkat</label><input id="tagline" name="tagline" class="form-control" maxlength="255" value="{{ old('tagline', $p->tagline) }}" placeholder="Akses modul, kumpulkan tugas, ajukan keperluan…"></div>
            </div>
            <h2>Warna dan sampul</h2>
            <fieldset class="mb-3"><legend class="form-label">Warna aksen</legend>
                @foreach(\App\Services\PracticumPortal::PALETTE as $key => $sw)<label class="swatch"><input type="radio" name="accent" value="{{ $key }}" @checked(old('accent', $p->accent) === $key)> <i style="background: {{ $sw['accent'] }}"></i> {{ $sw['label'] }}</label>@endforeach
            </fieldset>
            <div class="row g-3 mb-3">
                <div class="col-sm-6"><label class="form-label" for="hero_preset">Ilustrasi bawaan</label><select id="hero_preset" name="hero_preset" class="form-select">@foreach(\App\Services\PracticumPortal::PRESETS as $key => $label)<option value="{{ $key }}" @selected(old('hero_preset', $p->hero_preset) === $key)>{{ $label }}</option>@endforeach</select></div>
                <div class="col-sm-6"><span class="form-label d-block">Sampul</span>
                    <label class="d-block"><input type="radio" name="hero_mode" value="preset" @checked(old('hero_mode', $p->hero_file_id ? 'keep' : 'preset') === 'preset')> Pakai ilustrasi bawaan</label>
                    @if($p->hero_file_id)<label class="d-block"><input type="radio" name="hero_mode" value="keep" @checked(old('hero_mode', 'keep') === 'keep')> Pertahankan gambar saat ini</label>@endif
                    <label class="d-block"><input type="radio" name="hero_mode" value="upload" @checked(old('hero_mode') === 'upload')> Unggah gambar baru</label></div>
                <div class="col-12"><label class="form-label" for="cover">Gambar sampul (opsional)</label><input type="file" id="cover" name="cover" class="form-control" accept=".png,.jpg,.jpeg,.webp"><div class="form-text">PNG/JPG/WebP, maks. 1 MB, {{ implode('×', \App\Http\Controllers\PortalIdentityController::COVER_MIN) }} sampai {{ implode('×', \App\Http\Controllers\PortalIdentityController::COVER_MAX) }} px. Gambar disimpan ulang sebagai WebP.</div></div>
            </div>
            <h2>Kontak dan layanan</h2>
            <div class="row g-3 mb-3">
                <div class="col-sm-7"><label class="form-label" for="contact">Kontak</label><input id="contact" name="contact" class="form-control" maxlength="500" value="{{ old('contact', $p->contact) }}" placeholder="Koordinator: …"></div>
                <div class="col-sm-5"><label class="form-label" for="contact_url">Tautan kontak (https)</label><input id="contact_url" name="contact_url" type="url" class="form-control" maxlength="255" value="{{ old('contact_url', $p->contact_url) }}" placeholder="https://chat.whatsapp.com/…"></div>
            </div>
            <fieldset class="mb-3"><legend class="form-label">Layanan aktif di portal</legend>
                @foreach(\App\Services\PracticumPortal::SERVICES as $key => $label)<label class="d-block"><input type="checkbox" name="services[]" value="{{ $key }}" @checked(in_array($key, old('services', array_keys(array_filter($services))), true))> {{ $label }}</label>@endforeach
            </fieldset>
            @if($isAdmin)
            <h2>Alamat portal</h2>
            <div class="row g-3 mb-3">
                <div class="col-sm-6"><label class="form-label" for="slug">Slug</label><div class="input-group"><span class="input-group-text">{{ parse_url(url('/'), PHP_URL_HOST) }}/</span><input id="slug" name="slug" class="form-control" maxlength="50" value="{{ old('slug', $p->slug) }}" pattern="[a-z0-9]+(-[a-z0-9]+)*"></div>@if($aliases->isNotEmpty())<div class="form-text">Alamat lama tetap dialihkan: {{ $aliases->implode(', ') }}</div>@endif</div>
                <div class="col-sm-6"><label class="form-label" for="reason">Alasan (wajib bila slug diubah)</label><input id="reason" name="reason" class="form-control" maxlength="1000" value="{{ old('reason') }}"></div>
            </div>
            @endif
            <label class="d-block mb-3"><input type="checkbox" name="confirmed" value="1" required> Saya memahami perubahan ini langsung terlihat oleh praktikan.</label>
            <button type="submit" class="btn btn-primary"><i class="bi bi-floppy" aria-hidden="true"></i> Simpan tampilan</button>
        </div></form>
    </div>
    <div class="col-lg-5">
        <section class="card mb-3"><div class="card-body"><h2>Pratinjau kartu</h2>
            <div class="card practicum-card" style="--card-accent: {{ $c['accent'] }}; --card-soft: {{ $c['soft'] }};">
                <div class="cover">@include('portal.cover', ['practicum' => $p])</div>
                <div class="body"><span class="code">{{ $p->short_name ?: $p->code }}</span><h2>{{ \App\Services\PracticumPortal::displayName($p) }}</h2><p>{{ $p->tagline ?: 'Deskripsi singkat tampil di sini.' }}</p></div>
            </div>
            <p class="small text-secondary mt-2 mb-0">Pratinjau menampilkan data yang sudah disimpan.</p>
        </div></section>
        @if($url)
        <section class="card"><div class="card-body"><h2>Bagikan ke praktikan</h2>
            <label class="form-label" for="share-link">Link portal</label>
            <div class="input-group mb-3"><input id="share-link" class="form-control" readonly value="{{ $url }}"><button type="button" class="btn btn-outline-primary" id="copy-share"><i class="bi bi-clipboard" aria-hidden="true"></i> Salin</button></div>
            <div id="qr" class="qr-box" data-url="{{ $url }}" role="img" aria-label="Kode QR menuju {{ $url }}"></div>
            <button type="button" class="btn btn-sm btn-outline-secondary mt-2" id="download-qr"><i class="bi bi-download" aria-hidden="true"></i> Unduh QR</button>
            <p class="small text-secondary mt-2 mb-0">Bagikan di grup kelas atau tempel QR di ruang lab. Praktikan langsung masuk ke portal praktikum ini.</p>
        </div></section>
        @endif
    </div>
</div>
@endsection
@push('scripts')
<script src="{{ \App\Support\Asset::url('vendor/qrcode-generator/qrcode.js') }}"></script>
<script>
(()=>{const box=document.querySelector('#qr');if(!box||typeof qrcode!=='function')return;const qr=qrcode(0,'M');qr.addData(box.dataset.url);qr.make();box.innerHTML=qr.createSvgTag({cellSize:5,margin:2,scalable:true});
document.querySelector('#copy-share')?.addEventListener('click',async()=>{const i=document.querySelector('#share-link');i.select();try{await navigator.clipboard.writeText(i.value);}catch(e){}});
document.querySelector('#download-qr')?.addEventListener('click',()=>{const svg=box.querySelector('svg');if(!svg)return;const blob=new Blob([svg.outerHTML],{type:'image/svg+xml'});const a=document.createElement('a');a.href=URL.createObjectURL(blob);a.download='qr-portal.svg';a.click();URL.revokeObjectURL(a.href);});})();
</script>
@endpush
