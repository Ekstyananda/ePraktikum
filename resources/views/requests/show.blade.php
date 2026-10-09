@extends('layouts.app')
@section('title', 'Detail pengajuan — Portal Praktikum')
@section('context', $o->semester.' · '.$o->name)
@section('content')
@php
$wib = fn ($utc, $f = 'd/m/Y H:i') => $utc ? \Carbon\CarbonImmutable::parse($utc, 'UTC')->setTimezone('Asia/Jakarta')->format($f) : '—';
$types = \App\Services\PublicWorkflow::TYPES;
$states = \App\Services\PublicWorkflow::STATES;
$tones = ['pending' => 'warn', 'approved' => 'ok', 'rejected' => 'danger', 'completed' => 'info'];
$attendance = ['present' => 'Hadir', 'excused' => 'Izin', 'sick' => 'Sakit', 'absent' => 'Tidak hadir'];
$exec = fn ($id) => $id && isset($executions[$id]) ? $executions[$id]->label.' · Pertemuan '.$executions[$id]->number.' · '.$wib($executions[$id]->starts_at).' WIB' : '—';
$original = $row->original_grade ? json_decode($row->original_grade, true) : null;
@endphp
<x-page-header :title="$types[$row->type].' — '.$e->name" :subtitle="$e->nbi.' · '.$e->sim_class.' · '.$e->class_category" :crumbs="['Pengajuan' => route('requests.index', $o->id), '#'.$row->id => null]">
    <x-slot:actions><span class="status-badge status-{{ $tones[$row->status] ?? 'neutral' }}">{{ $states[$row->status] ?? $row->status }}</span></x-slot:actions>
</x-page-header>
<div class="row g-3">
    <div class="col-lg-7">
        <section class="card mb-3"><div class="card-body">
            <h2>Rincian pengajuan</h2>
            <dl class="row mb-0 small-dl">
                <dt class="col-sm-4">Diajukan</dt><dd class="col-sm-8">{{ $wib($row->created_at) }} WIB</dd>
                <dt class="col-sm-4">Sesi asal</dt><dd class="col-sm-8">{{ $source->label }}</dd>
                @if($row->source_execution_id)<dt class="col-sm-4">Pelaksanaan asal</dt><dd class="col-sm-8">{{ $exec($row->source_execution_id) }}</dd>@endif
                @if($target)<dt class="col-sm-4">Sesi tujuan</dt><dd class="col-sm-8">{{ $target->label }}</dd>@endif
                @if($row->target_execution_id)<dt class="col-sm-4">Pelaksanaan tujuan</dt><dd class="col-sm-8">{{ $exec($row->target_execution_id) }}</dd>@endif
                @if($row->effective_date)<dt class="col-sm-4">Tanggal efektif</dt><dd class="col-sm-8">{{ \Carbon\CarbonImmutable::parse($row->effective_date)->format('d/m/Y') }}</dd>@endif
                @if($program)<dt class="col-sm-4">Program remidi</dt><dd class="col-sm-8">{{ $program->title }} · komponen {{ $program->component }} (maks. {{ (float) $program->max_score }})<div class="small text-secondary">Kelayakan: {{ $program->eligibility_rule }}</div></dd>@endif
                <dt class="col-sm-4">Alasan</dt><dd class="col-sm-8">{{ $row->reason }}</dd>
                <dt class="col-sm-4">Bukti</dt><dd class="col-sm-8">@if($row->evidence_file_id)<a href="{{ route('requests.download', [$o->id, $row->id]) }}"><i class="bi bi-paperclip" aria-hidden="true"></i> Unduh bukti privat</a>@else<span class="text-secondary">Tidak ada berkas</span>@endif</dd>
                @if($row->decision_at)<dt class="col-sm-4">Keputusan</dt><dd class="col-sm-8">{{ $row->status === 'rejected' ? 'Tidak disetujui' : 'Disetujui' }} oleh {{ $decider }} · {{ $wib($row->decision_at) }} WIB<div class="small text-secondary">{{ $row->decision_note }}</div></dd>@endif
                @if($row->student_note)<dt class="col-sm-4">Catatan untuk praktikan</dt><dd class="col-sm-8">{{ $row->student_note }}</dd>@endif
                @if($row->scheduled_at)<dt class="col-sm-4">Jadwal pelaksanaan</dt><dd class="col-sm-8">{{ $wib($row->scheduled_at) }} WIB · {{ $row->room }}</dd>@endif
            </dl>
        </div></section>

        @if($row->type === 'remidi')
        <section class="card mb-3"><div class="card-body">
            <h2>Nilai awal vs hasil remidi</h2>
            <p class="small text-secondary">Nilai awal dibekukan saat persetujuan dan tidak diubah. Hasil remidi disimpan terpisah dan tidak otomatis menggantikan nilai pada finalisasi.</p>
            <div class="row g-3">
                <div class="col-sm-6"><div class="compare-box"><span class="small text-secondary">Nilai awal{{ $original ? ' (dibekukan)' : ' saat ini' }}</span><strong>{{ ($score = $original['score'] ?? $currentGrade?->score) !== null ? (float) $score : '—' }}</strong></div></div>
                <div class="col-sm-6"><div class="compare-box"><span class="small text-secondary">Hasil remidi terbaru</span><strong>{{ $results->first()?->score !== null ? (float) $results->first()->score : '—' }}</strong></div></div>
            </div>
        </div></section>
        @endif

        <section class="card"><div class="card-body">
            <h2>Riwayat hasil</h2>
            @forelse($results as $res)
            <div class="history-item"><div class="d-flex justify-content-between flex-wrap gap-2"><strong>Versi {{ $res->version }}</strong><span class="small text-secondary">{{ $res->evaluator }} · dicatat {{ $wib($res->created_at) }} WIB</span></div>
                <div>Pelaksanaan {{ $wib($res->performed_at) }} WIB @if($res->attendance) · {{ $attendance[$res->attendance] ?? $res->attendance }}@endif @if($res->score !== null) · nilai {{ (float) $res->score }}@endif</div>
                <div class="small text-secondary">{{ $res->note }}</div></div>
            @empty
            <p class="text-secondary mb-0">Belum ada hasil pelaksanaan.</p>
            @endforelse
        </div></section>
    </div>

    <div class="col-lg-5">
        @if($capacity)
        <section class="card mb-3"><div class="card-body">
            <h2>Kapasitas sesi tujuan</h2>
            @php $full = $capacity['max'] !== null && $capacity['used'] >= $capacity['max']; @endphp
            <p class="mb-1"><span class="status-badge {{ $full ? 'status-danger' : 'status-ok' }}">{{ $capacity['used'] }} / {{ $capacity['max'] ?? 'tanpa batas' }}</span></p>
            <p class="small text-secondary mb-0">Dihitung ulang dengan penguncian saat persetujuan, termasuk perpindahan lain yang sudah disetujui.</p>
        </div></section>
        @endif

        @if($row->status === 'pending')
        <form method="post" action="{{ route('requests.decide', [$o->id, $row->id]) }}" class="card mb-3" data-dirty-form data-confirm="Simpan keputusan pengajuan ini?">@csrf<div class="card-body">
            <h2>Keputusan</h2>
            <input type="hidden" name="version" value="{{ $row->version }}">
            <div class="mb-3"><span class="form-label d-block">Keputusan</span>
                <label class="me-3"><input type="radio" name="decision" value="approved" required @checked(old('decision') === 'approved')> Setujui</label>
                <label><input type="radio" name="decision" value="rejected" @checked(old('decision') === 'rejected')> Tidak disetujui</label></div>
            @if($row->type === 'susulan')
            <div class="row g-2 mb-3"><div class="col-sm-7"><label for="scheduled_at" class="form-label">Jadwal susulan (WIB)</label><input type="datetime-local" id="scheduled_at" name="scheduled_at" class="form-control" value="{{ old('scheduled_at') }}"></div><div class="col-sm-5"><label for="room" class="form-label">Ruang</label><input id="room" name="room" class="form-control" maxlength="150" value="{{ old('room') }}"></div><div class="form-text">Wajib bila disetujui.</div></div>
            @endif
            <label for="reason" class="form-label">Alasan keputusan (internal) — wajib bila tidak disetujui</label><textarea id="reason" name="reason" class="form-control mb-3" rows="3" maxlength="2000">{{ old('reason') }}</textarea>
            <label for="student_note" class="form-label">Catatan untuk praktikan (opsional)</label><textarea id="student_note" name="student_note" class="form-control" rows="2" maxlength="1000" placeholder="Mis. Hadir di Lab B pukul 13.00, bawa kartu praktikum">{{ old('student_note') }}</textarea><div class="form-text">Terlihat oleh praktikan di Cek Status. Jangan tulis nama, NBI, atau nilai.</div><div class="mb-3"></div><label class="d-block mb-3"><input type="checkbox" name="confirmed" value="1" required> Saya telah memeriksa identitas, bukti dan kapasitas.</label>
            <button type="submit" class="btn btn-primary">Simpan keputusan</button>
        </div></form>
        @endif

        @if(in_array($row->type, ['susulan', 'remidi'], true) && in_array($row->status, ['approved', 'completed'], true))
        <form method="post" action="{{ route('requests.result', [$o->id, $row->id]) }}" class="card" data-dirty-form data-confirm="Simpan hasil pelaksanaan sebagai versi baru?">@csrf<div class="card-body">
            <h2>{{ $row->type === 'susulan' ? 'Hasil susulan' : 'Hasil remidi' }}</h2>
            <input type="hidden" name="version" value="{{ $row->version }}">
            <label for="performed_at" class="form-label">Waktu pelaksanaan sebenarnya (WIB)</label><input type="datetime-local" id="performed_at" name="performed_at" class="form-control mb-3" required value="{{ old('performed_at') }}">
            @if($row->type === 'susulan')
            <label for="attendance" class="form-label">Status pelaksanaan</label><select id="attendance" name="attendance" class="form-select mb-3" required><option value="">Pilih status</option>@foreach($attendance as $key => $label)<option value="{{ $key }}" @selected(old('attendance') === $key)>{{ $label }}</option>@endforeach</select>
            @else
            <label for="score" class="form-label">Nilai remidi (maks. {{ (float) $program?->max_score }})</label><input type="number" step="0.0001" min="0" max="{{ $program?->max_score }}" id="score" name="score" class="form-control mb-3" required value="{{ old('score') }}">
            @endif
            <label for="result-reason" class="form-label">Catatan / alasan (internal)</label><textarea id="result-reason" name="reason" class="form-control mb-3" rows="2" required minlength="5" maxlength="2000">{{ old('reason') }}</textarea>
            <label for="result-student-note" class="form-label">Catatan untuk praktikan (opsional)</label><textarea id="result-student-note" name="student_note" class="form-control" rows="2" maxlength="1000">{{ old('student_note') }}</textarea><div class="form-text">Terlihat oleh praktikan di Cek Status. Jangan tulis nama, NBI, atau nilai.</div><div class="mb-3"></div><label class="d-block mb-3"><input type="checkbox" name="confirmed" value="1" required> Hasil sesuai pelaksanaan sebenarnya.</label>
            <button type="submit" class="btn btn-primary">Simpan hasil</button>
            <p class="small text-secondary mt-2 mb-0">Koreksi disimpan sebagai versi baru; versi lama tetap terlihat.</p>
        </div></form>
        @endif
        <details class="mt-3"><summary class="small text-secondary mb-2">Praktikan kehilangan token?</summary>@include('portal-token._form', ['action' => route('requests.reissue', [$o->id, $row->id])])</details>
    </div>
</div>
@endsection
