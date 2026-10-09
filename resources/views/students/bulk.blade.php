@extends('layouts.app')
@section('context',$o->semester.' · '.$o->name)
@section('content')<x-page-header title="Pratinjau penetapan dosen" :crumbs="['Praktikan' => route('students.index',$o->id), 'Tetapkan dosen' => null]" />
<div class="card">
    <div class="card-body">
        <p><strong>{{ count($payload['rows']) }} praktikan dipilih</strong> melalui {{ $payload['selection_mode']==='filtered'?'seluruh hasil filter':'checkbox halaman aktif' }}. Dosen: <strong>{{ $supervisor->name }}</strong> {{ $supervisor->identity_code }}.</p>
        <p>Mode: {{ $payload['mode']==='empty'?'Isi yang belum ditentukan; dosen yang sudah terisi dipertahankan.':'Ganti seluruh pilihan.' }}</p>@if($payload['reason'])<p>Alasan: {{ $payload['reason'] }}</p>@endif<div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>NBI</th>
                        <th>Nama sasaran</th>
                        <th>Perubahan</th>
                    </tr>
                </thead>
                <tbody>@foreach($payload['rows'] as $e)<tr>
                        <td>{{ $e['nbi'] }}</td>
                        <td>{{ $e['name'] }}</td>
                        <td>{{ $payload['mode']==='empty'&&$e['supervisor_id']!==null?'Dilewati — dosen sudah terisi':'Akan ditetapkan' }}</td>
                    </tr>@endforeach</tbody>
            </table>
        </div>
        <form method="post" action="{{ route('supervisors.commit',$o->id) }}" data-dirty-form data-confirm="Terapkan dosen hanya ke daftar sasaran ini?">@csrf<input type="hidden" name="token" value="{{ $token }}">
            <div class="form-check my-3"><input type="checkbox" name="confirmed" value="1" id="confirmed" class="form-check-input" required><label for="confirmed" class="form-check-label">Saya mengonfirmasi jumlah, nama, dan mode perubahan di atas.</label></div><button type="submit" class="btn btn-primary">Simpan penetapan</button><a class="btn btn-outline-secondary" href="{{ route('students.index',$o->id) }}">Batal</a>
        </form>
    </div>
</div>@endsection