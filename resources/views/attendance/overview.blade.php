@extends('layouts.app')
@section('title','Presensi — Portal Praktikum')
@section('content')
<x-page-header title="Presensi Praktikum" subtitle="Pilih pelaksanaan pertemuan per sesi untuk mencatat presensi, mencetak lembar TTD atau mengunggah scan." :crumbs="['Dashboard' => route('dashboard'), 'Presensi' => null]" />
<form method="get" class="toolbar">
    <label class="visually-hidden" for="session_id">Sesi</label>
    <select id="session_id" name="session_id" class="form-select" data-autosubmit>
        <option value="">Semua sesi dalam lingkup</option>
        @foreach($sessions as $session)<option value="{{ $session->id }}" @selected((int) request('session_id') === $session->id)>{{ $session->label }}</option>@endforeach
    </select>
    <noscript><button class="btn btn-outline-primary">Filter</button></noscript>
</form>
<section class="card">
    <div class="table-responsive">
        <table class="table table-stack">
            <thead><tr><th>Tanggal · WIB</th><th>Sesi</th><th>Pertemuan</th><th>Ruang</th><th>Status</th><th>Rekap</th><th><span class="visually-hidden">Aksi</span></th></tr></thead>
            <tbody>
                @forelse($rows as $row)
                @php $start = \Carbon\CarbonImmutable::parse($row->starts_at,'UTC')->setTimezone('Asia/Jakarta'); @endphp
                <tr>
                    <td>{{ $start->format('d/m/Y') }}<div class="small text-secondary">{{ $start->format('H:i') }}–{{ \Carbon\CarbonImmutable::parse($row->ends_at,'UTC')->setTimezone('Asia/Jakarta')->format('H:i') }}</div></td>
                    <td>{{ $row->session_label }}</td>
                    <td>Pertemuan {{ $row->number }}<div class="small text-secondary">{{ $row->title }}</div></td>
                    <td>{{ $row->room }}</td>
                    <td>@if($row->snapshot_created_at)<span class="status-badge status-info">Snapshot dibekukan</span>@else<span class="status-badge status-neutral">Terjadwal</span>@endif</td>
                    <td>@if($row->total)<span class="status-badge {{ $row->unrecorded ? 'status-warn' : 'status-ok' }}">{{ $row->present }}/{{ $row->total }} hadir</span>@if($row->unrecorded)<div class="small text-secondary mt-1">{{ $row->unrecorded }} belum dicatat</div>@endif @else<span class="text-secondary">—</span>@endif</td>
                    <td class="text-end"><a class="btn btn-sm btn-primary" href="{{ route('attendance.index',[$o->id,$row->id]) }}">{{ $row->snapshot_created_at ? 'Buka presensi' : 'Siapkan presensi' }}</a></td>
                </tr>
                @empty
                <tr><td colspan="7" class="empty">Belum ada pelaksanaan terjadwal dalam lingkup presensi Anda. Jadwalkan melalui menu Pertemuan.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
<div class="mt-3">{{ $rows->links() }}</div>
@endsection
