@extends('layouts.app')
@section('title','Data Master — Portal Praktikum')
@section('content')
@php $masterTitle = ['semester'=>'Semester','praktikum'=>'Master Praktikum','offering'=>'Pelaksanaan Praktikum'][$type]; @endphp
<x-page-header :title="$masterTitle" :crumbs="['Administrasi' => null, $masterTitle => null]">
    <x-slot:actions><a class="btn btn-primary" href="{{ route('master.create',$type) }}"><i class="bi bi-plus-lg" aria-hidden="true"></i> Tambah</a></x-slot:actions>
</x-page-header>
<div class="card">
    <div class="card-body">
        <form method="get" class="toolbar"><label class="visually-hidden" for="q">Cari master</label><input id="q" name="q" class="form-control" value="{{ request('q') }}" placeholder="Cari nama"><x-per-page /><button class="btn btn-outline-primary">Cari</button></form>
        <div class="table-responsive">
            <table class="table table-stack">
                <thead>
                    <tr>
                        <th>Identitas</th>
                        <th>Keterangan</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($rows as $row)
                    <tr>
                        <td>{{ $type==='semester'?$row->label:$row->name }}</td>
                        <td>{{ $type==='offering'?$row->label:$row->code }} @if($type!=='praktikum') <span class="status-badge status-{{ ['active'=>'ok','locked'=>'danger'][$row->status] ?? 'neutral' }}">{{ ['draft'=>'Draf','active'=>'Aktif','locked'=>'Terkunci'][$row->status] ?? $row->status }}</span>@endif</td>
                        <td>
                            <div class="d-flex flex-wrap gap-2 align-items-start">
                                @if(($row->status ?? null) !== 'locked')<a class="btn btn-sm btn-outline-secondary" href="{{ route('master.edit',[$type,$row->id]) }}">Edit</a>@endif
                                @if($type==='offering')<a class="btn btn-sm btn-outline-primary" href="{{ route('offering',$row->id) }}">Buka praktikum</a>@endif
                                @if($type!=='praktikum')
                                @php $locked = $row->status === 'locked'; @endphp
                                <details class="lock-box"><summary class="btn btn-sm {{ $locked ? 'btn-outline-primary' : 'btn-outline-danger' }}">{{ $locked ? 'Buka kembali' : 'Kunci' }}</summary>
                                    <form method="post" action="{{ route('master.lock',[$type,$row->id]) }}" class="mt-2" data-confirm="{{ $locked ? 'Buka kembali data ini?' : 'Kunci data ini? Seluruh perubahan akademik akan ditolak.' }}">@csrf
                                        <input type="hidden" name="action" value="{{ $locked ? 'reopen' : 'lock' }}"><input type="hidden" name="version" value="{{ $row->version }}">
                                        <label class="form-label small" for="lock-reason-{{ $row->id }}">Alasan</label><input id="lock-reason-{{ $row->id }}" name="reason" class="form-control form-control-sm mb-2" required minlength="10" maxlength="1000">
                                        <label class="small d-block mb-2"><input type="checkbox" name="confirmed" value="1" required> Konfirmasi</label>
                                        <button class="btn btn-sm {{ $locked ? 'btn-primary' : 'btn-danger' }}">{{ $locked ? 'Buka kembali' : 'Kunci sekarang' }}</button>
                                    </form>
                                </details>
                                @endif
                            </div>
                        </td>
                    </tr>
                    @empty<tr>
                        <td colspan="3" class="empty">Belum ada data master.</td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>{{ $rows->links() }}
    </div>
</div>
@endsection