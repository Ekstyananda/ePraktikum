@extends('layouts.app')
@section('context',$o->semester.' · '.$o->name)
@section('content')
<x-page-header title="Data Praktikan" :subtitle="$o->name.' · '.$o->semester" :crumbs="['Dashboard' => route('dashboard'), 'Praktikan' => null]">
    <x-slot:actions><a class="btn btn-outline-primary" href="{{ route('imports.template',$o->id) }}"><i class="bi bi-filetype-csv" aria-hidden="true"></i> Template CSV</a><a class="btn btn-outline-primary" href="{{ route('imports.form',$o->id) }}"><i class="bi bi-upload" aria-hidden="true"></i> Impor CSV / XLSX</a>@if($canExport)<a class="btn btn-outline-primary" href="{{ route('students.export',[$o->id,...request()->except('page','per_page','format'),'format'=>'csv']) }}"><i class="bi bi-download" aria-hidden="true"></i> Ekspor CSV</a><a class="btn btn-outline-primary" href="{{ route('students.export',[$o->id,...request()->except('page','per_page','format'),'format'=>'xlsx']) }}"><i class="bi bi-file-earmark-spreadsheet" aria-hidden="true"></i> Ekspor XLSX</a>@endif<a class="btn btn-primary" href="{{ route('students.create',$o->id) }}"><i class="bi bi-person-plus" aria-hidden="true"></i> Tambah praktikan</a></x-slot:actions>
</x-page-header>
<div class="card">
    <div class="card-body">
        <form method="get" class="row g-2 mb-4">
            <div class="col-lg-3"><label class="form-label small" for="q">Cari NBI / nama</label><input id="q" name="q" class="form-control" value="{{ request('q') }}"></div>
            <div class="col-sm-6 col-lg-2"><label class="form-label small" for="filter-session">Sesi</label><select id="filter-session" name="session_id" class="form-select">
                    <option value="">Semua sesi berizin</option>@foreach($sessions as $s)<option value="{{ $s->id }}" @selected(request('session_id')==$s->id)>{{ $s->label }}</option>@endforeach
                </select></div>
            <div class="col-sm-6 col-lg-2"><label class="form-label small" for="filter-class">Kelas</label><input id="filter-class" name="class_category" class="form-control" value="{{ request('class_category') }}"></div>
            <div class="col-sm-6 col-lg-3"><label class="form-label small" for="filter-supervisor">Dosen pembimbing</label><select id="filter-supervisor" class="form-select" name="supervisor_id">
                    <option value="">Semua dosen</option>
                    <option value="empty" @selected(request('supervisor_id')==='empty' )>Belum ditentukan</option>@foreach($supervisors as $sp)<option value="{{ $sp->id }}" @selected(request('supervisor_id')==$sp->id)>{{ $sp->name }}{{ $sp->identity_code?' · '.$sp->identity_code:'' }}</option>@endforeach
                </select></div>
            <div class="col-sm-6 col-lg-2"><label class="form-label small" for="per_page">Per halaman</label><select id="per_page" class="form-select" name="per_page">@foreach([10,25,50] as $n)<option @selected(request('per_page',10)==$n)>{{ $n }}</option>@endforeach</select></div>
            <div class="col-sm-6 col-lg-2"><label class="form-label small" for="filter-active">Status</label><select class="form-select" id="filter-active" name="active">
                    <option value="">Semua status</option>
                    <option value="1" @selected(request('active')==='1' )>Aktif</option>
                    <option value="0" @selected(request('active')==='0' )>Nonaktif</option>
                </select></div>
            <div class="col-auto align-self-end"><button class="btn btn-outline-primary">Filter</button></div>
        </form>
        <form method="post" action="{{ route('supervisors.preview',$o->id) }}" id="selection-form">@csrf
            @foreach(request()->only(['q','session_id','class_category','supervisor_id','active']) as $field=>$value)<input type="hidden" name="filters[{{ $field }}]" value="{{ $value }}">@endforeach
            <div class="table-responsive">
                <table class="table roster-table">
                    <thead>
                        <tr>
                            <th><input type="checkbox" class="form-check-input" id="select-page" aria-label="Pilih seluruh halaman aktif"></th>
                            <th>No</th>
                            <th class="sticky-nbi">NBI</th>
                            <th class="sticky-student">Nama</th>
                            <th>Simpraktikum</th>
                            <th>Kelas</th>
                            <th>Sesi</th>
                            <th>Dosen Pembimbing</th>
                            <th>Status</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($rows as $e)<tr>
                            <td><input type="checkbox" class="form-check-input row-select" name="selected[]" value="{{ $e->id }}" aria-label="Pilih {{ $e->name }}"></td>
                            <td>{{ $rows->firstItem()+$loop->index }}</td>
                            <td class="sticky-nbi">{{ $e->nbi }}</td>
                            <td class="sticky-student">{{ $e->name }}</td>
                            <td>{{ $e->sim_class }}</td>
                            <td>{{ $e->class_category }}</td>
                            <td>{{ $e->session_label }}</td>
                            <td>{{ $e->supervisor_name??'Belum ditentukan' }}{{ $e->identity_code?' · '.$e->identity_code:'' }}</td>
                            <td>{{ $e->active?'Aktif':'Nonaktif' }}</td>
                            <td><a href="{{ route('students.edit',[$o->id,$e->id]) }}">Edit</a></td>
                        </tr>@empty<tr>
                            <td colspan="10" class="empty">Tidak ada praktikan yang cocok dalam lingkup akses.</td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-3 p-3 bg-light rounded">
                <h2>Tetapkan Dosen Pembimbing</h2>
                <p class="small text-secondary">Seleksi berlaku sesuai pilihan di bawah. Pratinjau membekukan daftar nama sasaran; filter berikutnya tidak memperluas seleksi.</p>
                <div class="row g-3">
                    <div class="col-md-6"><label class="form-label" for="selection_mode">Lingkup pilihan</label><select id="selection_mode" name="selection_mode" class="form-select">
                            <option value="selected">Baris dicentang di halaman aktif (0)</option>
                            <option value="filtered">Seluruh hasil filter — {{ $rows->total() }} praktikan</option>
                        </select>
                        <div id="selection-count" class="form-text" role="status">0 baris dicentang pada halaman aktif.</div>
                    </div>
                    <div class="col-md-6"><label class="form-label" for="supervisor_id">Dosen master</label><select class="form-select" id="supervisor_id" name="supervisor_id" required>
                            <option value="">Pilih dosen</option>@foreach($supervisors as $sp)<option value="{{ $sp->id }}">{{ $sp->name }}{{ $sp->identity_code?' · '.$sp->identity_code:'' }}</option>@endforeach
                        </select></div>
                    <div class="col-md-6"><label class="form-label" for="mode">Mode penetapan</label><select id="mode" name="mode" class="form-select">
                            <option value="empty">Isi yang belum ditentukan (default)</option>
                            <option value="replace">Ganti seluruh pilihan</option>
                        </select></div>
                    <div class="col-md-6"><label class="form-label" for="bulk-reason">Alasan penggantian (wajib untuk ganti)</label><input class="form-control" id="bulk-reason" name="reason" maxlength="1000"></div>
                </div><button class="btn btn-primary mt-3" id="bulk-preview" disabled>Pratinjau penetapan</button>
            </div>
        </form>
        <div class="mt-4">{{ $rows->links() }}</div>
        <p class="small text-secondary mb-0">Menampilkan {{ $rows->firstItem()??0 }}–{{ $rows->lastItem()??0 }} dari {{ $rows->total() }} praktikan berizin.</p>
    </div>
</div>
<details class="card mt-4">
    <summary class="p-3 fw-semibold">Tambah dosen pembimbing ke master</summary>
    <div class="card-body">
        <form method="post" action="{{ route('supervisors.store',$o->id) }}">@csrf<div class="row g-3">
                <div class="col-md-6"><label class="form-label" for="supervisor-name">Nama dosen</label><input class="form-control" name="name" id="supervisor-name" required maxlength="150"></div>
                <div class="col-md-6"><label class="form-label" for="identity_code">Kode identitas / NIDN (opsional)</label><input class="form-control" id="identity_code" name="identity_code" maxlength="80"></div>
                <div class="col-12">
                    <div class="form-check"><input type="checkbox" name="distinct_person" value="1" id="distinct_person" class="form-check-input"><label for="distinct_person" class="form-check-label">Nama sama tetapi dosen berbeda (wajib kode identitas berbeda)</label></div>
                </div>
            </div><button class="btn btn-outline-primary mt-3">Tambah dosen</button></form>
    </div>
</details>
@endsection