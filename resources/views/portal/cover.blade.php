{{-- Practicum cover: uploaded image (re-encoded on upload) or a preset illustration in the practicum colours. --}}
@php $class = $class ?? ''; $c = \App\Services\PracticumPortal::palette($practicum); @endphp
@if($practicum->hero_file_id)
<img src="{{ route('portal.cover', ['practicum' => $practicum->id, 'v' => $practicum->portal_version]) }}" alt="" class="{{ $class }}" loading="lazy">
@else
{!! \App\Services\PracticumPortal::presetSvg($practicum->hero_preset ?: 'database', $c) !!}
@endif
