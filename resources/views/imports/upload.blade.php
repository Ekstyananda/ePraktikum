@extends('layouts.app')
@section('context',$o->semester.' · '.$o->name)
@section('content')<x-page-header title="Impor praktikan" :crumbs="['Praktikan' => route('students.index',$o->id), 'Impor' => null]" />
<div class="card">
    <div class="card-body">
        <p>Unggah CSV UTF-8 (pemisah koma) atau XLSX, maksimal 5 MB dan 2000 baris. Satu sheet pertama dibaca. Baris pertama yang tidak kosong menjadi header; kolom dapat dipetakan sebelum disimpan.</p>
        <p>NBI wajib berupa teks, termasuk dalam Excel. Dosen pembimbing opsional; bila diisi, gunakan nama master unik atau kode identitas dosen. Tidak ada perubahan data praktikan pada tahap unggah/pratinjau.</p>
        <form method="post" action="{{ route('imports.upload',$o->id) }}" enctype="multipart/form-data" data-dirty-form>@csrf<label class="form-label" for="file">Berkas CSV / XLSX</label><input class="form-control" type="file" name="file" id="file" accept=".csv,.xlsx" required>
            <div class="d-flex gap-2 mt-4"><button type="submit" class="btn btn-primary">Unggah & petakan kolom</button><a class="btn btn-outline-primary" href="{{ route('imports.template',$o->id) }}">Unduh template CSV</a></div>
        </form>
    </div>
</div>@endsection