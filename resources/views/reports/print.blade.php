<!doctype html>
<html lang="id">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ \App\Services\Reports::TYPES[$type] }} · {{ $o->name }}</title>
<style>
@page { size: A4 landscape; margin: 12mm; }
body { font-family: Arial, Helvetica, sans-serif; color: #000; font-size: 9.5pt; margin: 0; }
.toolbar { display: flex; gap: 8px; padding: 10px; background: #f4f7fb; border-bottom: 1px solid #ccc; }
h1 { font-size: 14pt; margin: 0 0 4px; }
.meta { margin: 0 0 8px; }
table { width: 100%; border-collapse: collapse; }
th, td { border: 1px solid #000; padding: 3px 4px; text-align: left; vertical-align: top; }
thead { display: table-header-group; }
tr { break-inside: avoid; }
main { padding: 12px; }
@media print { .toolbar { display: none; } main { padding: 0; } }
</style></head>
<body>
<div class="toolbar"><a href="{{ route('reports.index', array_filter([$o->id, 'type' => $type, 'session_id' => $session, 'meeting_id' => $meeting])) }}">← Rekap</a><button type="button" onclick="window.print()">Cetak / Simpan PDF</button><span>A4 landscape</span></div>
<main>
<h1>{{ strtoupper(\App\Services\Reports::TYPES[$type]) }}</h1>
<p class="meta"><strong>{{ $o->name }}</strong> · {{ $o->semester }}@if($session) · {{ $sessions->firstWhere('id', $session)?->label }}@endif @if($meeting) · Pertemuan {{ $meetings->firstWhere('id', $meeting)?->number }}@endif<br>Dicetak {{ now('Asia/Jakarta')->format('d/m/Y H:i') }} WIB oleh {{ auth()->user()->name }} · {{ $data['note'] }}</p>
<table><thead><tr>@foreach($data['headers'] as $h)<th>{{ $h }}</th>@endforeach</tr></thead><tbody>
@forelse($data['rows'] as $row)<tr>@foreach($row as $cell)<td>{{ $cell }}</td>@endforeach</tr>@empty<tr><td colspan="{{ count($data['headers']) }}">Belum ada data.</td></tr>@endforelse
</tbody></table>
</main>
</body></html>
