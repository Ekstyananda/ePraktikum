@extends('layouts.app')
@section('context',$o->semester.' · '.$o->name)
@section('content')
<x-page-header :title="$final?'Arsip hasil final':'Pratinjau finalisasi'" :crumbs="['Penilaian' => route('grades.index',$o->id), 'Finalisasi' => null]" />
@if($final)@php $snapshot=json_decode($final->snapshot_json,true); @endphp<div class="card"><div class="card-body"><h2>{{ $snapshot['identity']['nbi'] }} · {{ $snapshot['identity']['name'] }}</h2><p class="fs-4">{{ $final->score }} · {{ $final->letter }} · {{ $final->decision }}</p><p>Aturan versi {{ $snapshot['rules_version'] }} · Final {{ \Carbon\CarbonImmutable::parse($final->finalized_at,'UTC')->setTimezone('Asia/Jakarta')->format('d/m/Y H:i') }} WIB</p><ul>@foreach($snapshot['components'] as $entry)<li>{{ $entry['component']['label'] }}: {{ $entry['grade']['score']??'—' }} / {{ $entry['component']['max_score'] }} · bobot {{ $entry['component']['weight'] }}%</li>@endforeach</ul><p class="text-secondary">Arsip versi {{ $final->version }} · read-only.</p></div></div>
@if(auth()->user()->role === 'admin')
<form method="post" action="{{ route('grades.reopen',[$o->id,$final->enrollment_id]) }}" class="card mt-3" data-dirty-form data-confirm="Buka arsip final untuk koreksi? Versi ini disimpan sebagai riwayat.">@csrf<div class="card-body">
    <h2>Buka koreksi (admin)</h2><p class="small text-secondary">Nilai dan pengumpulan praktikan dapat diubah kembali, lalu difinalisasi ulang sebagai versi baru. Versi ini tetap tersimpan.</p>
    <input type="hidden" name="final_id" value="{{ $final->id }}">
    <label class="form-label" for="reopen-reason">Alasan koreksi</label><textarea id="reopen-reason" name="reason" class="form-control mb-3" rows="2" required minlength="10" maxlength="1000"></textarea>
    <label class="d-block mb-3"><input type="checkbox" name="confirmed" value="1" required> Saya memahami arsip ini akan digantikan versi baru setelah finalisasi ulang.</label>
    <button type="submit" class="btn btn-outline-primary">Buka koreksi</button>
</div></form>
@endif
@else
<p>{{ $p['enrollment']->nbi }} · {{ $p['enrollment']->name }}</p><div class="card"><div class="card-body">@if($p['errors'])<h2>Belum dapat difinalisasi</h2><ul class="text-danger">@foreach($p['errors'] as $error)<li>{{ $error }}</li>@endforeach</ul>@else<h2>Seluruh pemeriksaan terpenuhi</h2><p class="fs-4">{{ $p['score'] }} · {{ $p['letter'] }} · {{ $p['decision'] }}</p><p>Aturan versi {{ $p['rule']->version }}</p>@endif
<ul>@foreach($p['details'] as $entry)<li>{{ $entry['component']['label'] }}: {{ $entry['grade']['score']??'Belum diperiksa' }} / {{ $entry['component']['max_score'] }} · bobot {{ $entry['component']['weight']??'belum ditentukan' }}%</li>@endforeach</ul><form method="post" action="{{ route('grades.finalize',[$o->id,$p['enrollment']->id]) }}" data-dirty-form data-confirm="Arsipkan hasil final dan kunci perubahan nilai/pengumpulan praktikan ini?">@csrf<input type="hidden" name="digest" value="{{ $digest }}"><label class="d-block mb-3"><input type="checkbox" name="confirmed" value="1" required @disabled(count($p['errors'])>0)> Saya telah memeriksa hasil dan seluruh kewajiban.</label><button class="btn btn-primary" type="submit" @disabled(count($p['errors'])>0)>Finalisasi hasil</button></form></div></div>
@endif
@if($history->isNotEmpty())
<section class="card mt-3"><div class="card-body"><h2>Riwayat arsip final</h2>
@foreach($history as $h)<div class="history-item"><strong>Versi {{ $h->version }}</strong> · {{ (float) $h->score }} · {{ $h->letter }} · {{ $h->decision }}<div class="small text-secondary">Dibuka {{ \Carbon\CarbonImmutable::parse($h->superseded_at,'UTC')->setTimezone('Asia/Jakarta')->format('d/m/Y H:i') }} WIB oleh {{ $h->reopened_by }} — {{ $h->supersede_reason }}</div></div>@endforeach
</div></section>
@endif
@endsection
