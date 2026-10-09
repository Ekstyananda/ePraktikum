@extends('layouts.app')
@section('title', 'Log Aktivitas — Portal Praktikum')
@if($o)@section('context', $o->semester.' · '.$o->name)@endif
@section('content')
@php $detail = fn ($id) => $o ? route('logs.offering.show', [$o->id, $id]) : route('logs.show', $id); @endphp
<x-page-header title="Log Aktivitas" subtitle="Catatan perubahan read-only: siapa, kapan, apa dan alasannya. Log tidak dapat diubah atau dihapus dari aplikasi." :crumbs="$o ? ['Dashboard' => route('dashboard'), 'Log aktivitas' => null] : ['Pengaturan' => route('settings.index'), 'Log aktivitas' => null]" />
<form method="get" class="card mb-3"><div class="card-body"><div class="row g-2 align-items-end">
    @unless($o)<div class="col-md-3"><label class="form-label small" for="offering_id">Praktikum</label><select id="offering_id" name="offering_id" class="form-select"><option value="">Semua (termasuk akun)</option>@foreach($offerings as $off)<option value="{{ $off->id }}" @selected((int) request('offering_id') === $off->id)>{{ $off->name }} · {{ $off->semester }}</option>@endforeach</select></div>@endunless
    <div class="col-md-2"><label class="form-label small" for="actor_id">Pelaku</label><select id="actor_id" name="actor_id" class="form-select"><option value="">Semua</option>@foreach($actors as $a)<option value="{{ $a->id }}" @selected((int) request('actor_id') === $a->id)>{{ $a->name }}</option>@endforeach</select></div>
    <div class="col-md-2"><label class="form-label small" for="entity">Entitas</label><select id="entity" name="entity" class="form-select"><option value="">Semua</option>@foreach($entities as $en)<option value="{{ $en }}" @selected(request('entity') === $en)>{{ $en }}</option>@endforeach</select></div>
    <div class="col-md-2"><label class="form-label small" for="action">Aksi (awalan)</label><input id="action" name="action" class="form-control" value="{{ request('action') }}" placeholder="mis. attendance"></div>
    @if($sessions->isNotEmpty())<div class="col-md-2"><label class="form-label small" for="session_id">Sesi</label><select id="session_id" name="session_id" class="form-select"><option value="">Semua</option>@foreach($sessions as $s)<option value="{{ $s->id }}" @selected((int) request('session_id') === $s->id)>{{ $s->label }}</option>@endforeach</select></div>@endif
    <div class="col-6 col-md-2"><label class="form-label small" for="from">Dari (WIB)</label><input type="date" id="from" name="from" class="form-control" value="{{ request('from') }}"></div>
    <div class="col-6 col-md-2"><label class="form-label small" for="to">Sampai</label><input type="date" id="to" name="to" class="form-control" value="{{ request('to') }}"></div>
    <div class="col-md-1"><button class="btn btn-outline-primary w-100"><i class="bi bi-funnel" aria-hidden="true"></i><span class="visually-hidden">Filter</span></button></div>
</div></div></form>
<section class="card"><div class="table-responsive"><table class="table table-stack table-compact">
    <thead><tr><th>Waktu · WIB</th><th>Pelaku</th><th>Aksi</th><th>Entitas</th>@unless($o)<th>Praktikum</th>@endunless<th>Alasan</th><th><span class="visually-hidden">Detail</span></th></tr></thead>
    <tbody>
    @forelse($rows as $row)
    <tr>
        <td class="text-nowrap">{{ \Carbon\CarbonImmutable::parse($row->created_at, 'UTC')->setTimezone('Asia/Jakarta')->format('d/m/Y H:i:s') }}</td>
        <td>{{ $row->actor ?? 'Sistem / publik' }}</td>
        <td><code>{{ $row->action }}</code></td>
        <td>{{ $row->entity_type }} #{{ $row->entity_id }}</td>
        @unless($o)<td>{{ $row->practicum ?? '—' }}</td>@endunless
        <td>{{ \Illuminate\Support\Str::limit($row->reason ?? '—', 80) }}</td>
        <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="{{ $detail($row->id) }}">Detail</a></td>
    </tr>
    @empty
    <tr><td colspan="7" class="empty">Tidak ada log sesuai filter.</td></tr>
    @endforelse
    </tbody>
</table></div></section>
<div class="mt-3">{{ $rows->links() }}</div>
@endsection
