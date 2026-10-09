{{-- Status details for the token holder (server-rendered fallback; receipts.js renders the same in the pop-up). --}}
@php $tones = ['pending' => 'warn', 'submitted' => 'info', 'approved' => 'ok', 'accepted' => 'ok', 'complete' => 'ok', 'completed' => 'ok', 'revised' => 'info', 'revision_requested' => 'warn', 'rejected' => 'danger', 'missing_decided' => 'danger']; @endphp
<div class="status-detail">
    <p class="small text-secondary mb-1">{{ $d['practicum'] }}</p>
    <h2 class="h5 mb-2">{{ $d['kind'] }}</h2>
    <p role="status" class="mb-3"><span class="status-badge status-{{ $tones[$d['state']] ?? 'neutral' }}">{{ $d['label'] }}</span></p>
    @if($d['facts'])<dl class="row small-dl mb-3">@foreach($d['facts'] as [$k, $v])<dt class="col-sm-4">{{ $k }}</dt><dd class="col-sm-8">{{ $v }}</dd>@endforeach</dl>@endif
    <h3 class="h6">Tahapan</h3>
    <ol class="status-steps mb-3">@foreach($d['steps'] as $step)<li class="{{ $step['done'] ? 'done' : '' }}"><span>{{ $step['label'] }}</span><small>{{ $step['at'] ?? ($step['done'] ? 'Waktu tidak tercatat' : 'Belum') }}</small></li>@endforeach</ol>
    @if($d['notes'])<h3 class="h6">Catatan aslab</h3>@foreach($d['notes'] as $note)<p class="status-note">{{ $note }}</p>@endforeach @endif
    <p class="status-next mb-0"><i class="bi bi-arrow-right-circle" aria-hidden="true"></i> {{ $d['next'] }}</p>
</div>
