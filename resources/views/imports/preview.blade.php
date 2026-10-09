@extends('layouts.app')
@section('context',$o->semester.' · '.$o->name)
@section('content')<x-page-header title="Pemetaan & pratinjau impor" :subtitle="count($payload['rows']).' baris sumber · belum ada praktikan yang ditambahkan · berlaku 30 menit'" :crumbs="['Praktikan' => route('students.index',$o->id), 'Impor' => route('imports.form',$o->id), 'Pratinjau' => null]" />
<div class="card">
    <div class="card-body">
        <form method="post" action="{{ route('imports.map',[$o->id,$p->id]) }}">@csrf<input type="hidden" name="version" value="{{ $p->version }}">
            <div class="row g-3">@foreach(\App\Services\StudentImport::FIELDS as $key=>$label)<div class="col-md-4"><label class="form-label" for="map-{{ $key }}">{{ $label }}</label><select class="form-select" id="map-{{ $key }}" name="mapping[{{ $key }}]">
                        <option value="">{{ $key==='supervisor_name'?'Tidak dipetakan / boleh kosong':'Pilih kolom' }}</option>@foreach($payload['headers'] as $index=>$header)<option value="{{ $index }}" @selected(($payload['mapping'][$key]??null)!==null&&$payload['mapping'][$key]==$index)>{{ $index+1 }} — {{ $header }}</option>@endforeach
                    </select></div>@endforeach</div><button class="btn btn-outline-primary mt-3">Validasi pratinjau</button>
        </form>
    </div>
</div>
@if($payload['result']!==null)
@php $hasErrors=collect($payload['result'])->contains(fn($r)=>count($r['errors'])>0); @endphp
<div class="card mt-4">
    <div class="card-body">
        <h2>{{ count($payload['result']) }} baris data</h2>
        <p class="{{ $hasErrors?'text-danger':'text-success' }}">{{ $hasErrors?'Ada error. Seluruh impor belum dapat disimpan. Perbaiki sumber atau pemetaan.':'Semua baris valid pada pratinjau ini. Server akan memeriksa ulang saat simpan.' }}</p>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Baris sumber</th>
                        <th>NBI</th>
                        <th>Nama</th>
                        <th>Sesi</th>
                        <th>Dosen</th>
                        <th>Validasi</th>
                    </tr>
                </thead>
                <tbody>@forelse($payload['result'] as $row)<tr>
                        <td>{{ $row['row'] }}</td>
                        <td>{{ $row['data']['nbi'] }}</td>
                        <td>{{ $row['data']['name'] }}</td>
                        <td>{{ $row['data']['session_label'] }}</td>
                        <td>{{ $row['data']['supervisor_name']?:'Belum ditentukan' }}</td>
                        <td>@if($row['errors'])<ul class="text-danger mb-0">@foreach($row['errors'] as $error)<li>{{ $error }}</li>@endforeach</ul>@else<span class="badge text-bg-success">Valid</span>@endif</td>
                    </tr>@empty<tr>
                        <td colspan="6">Tidak ada baris data; header berulang/baris kosong diabaikan.</td>
                    </tr>@endforelse</tbody>
            </table>
        </div>
        <form method="post" action="{{ route('imports.commit',[$o->id,$p->id]) }}" data-dirty-form data-confirm="Impor seluruh baris valid ini?">@csrf<input type="hidden" name="version" value="{{ $p->version }}">
            <div class="form-check my-3"><input type="checkbox" id="confirmed" name="confirmed" value="1" class="form-check-input" required @disabled($hasErrors||!count($payload['result']))><label class="form-check-label" for="confirmed">Saya sudah memeriksa seluruh baris sasaran.</label></div><button type="submit" class="btn btn-primary" @disabled($hasErrors||!count($payload['result']))>Simpan impor</button><a class="btn btn-outline-secondary" href="{{ route('students.index',$o->id) }}">Batal</a>
        </form>
    </div>
</div>
@endif
@endsection