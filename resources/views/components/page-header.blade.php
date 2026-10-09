{{-- Shared page header: breadcrumb, title, subtitle, optional meta and actions. --}}
@props(['title', 'subtitle' => null, 'crumbs' => [], 'eyebrow' => null])
<div class="page-header">
    @if($crumbs)
    <nav class="breadcrumb-row" aria-label="Breadcrumb">
        @foreach($crumbs as $label => $url)
        @if(!$loop->first)<span class="sep" aria-hidden="true">/</span>@endif
        @if($url && !$loop->last)<a href="{{ $url }}">{{ $label }}</a>@else<span @if($loop->last) aria-current="page" @endif>{{ $label }}</span>@endif
        @endforeach
    </nav>
    @endif
    <div class="page-head">
        <div class="page-title">
            @if($eyebrow)<div class="eyebrow">{{ $eyebrow }}</div>@endif
            <h1>{{ $title }}</h1>
            @if($subtitle)<p>{{ $subtitle }}</p>@endif
            {{ $slot }}
        </div>
        @isset($meta)<div class="page-meta-wrap">{{ $meta }}</div>@endisset
        @isset($actions)<div class="page-actions">{{ $actions }}</div>@endisset
    </div>
</div>
