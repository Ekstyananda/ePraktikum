{{-- Page size selector for GET filter forms; the controller validates the value. --}}
@props(['default' => 25, 'options' => [10, 25, 50, 100]])
@php $id = 'per_page_'.substr(md5($attributes->get('id', 'pp')), 0, 6); @endphp
<label class="visually-hidden" for="{{ $id }}">Baris per halaman</label>
<select id="{{ $id }}" name="per_page" {{ $attributes->merge(['class' => 'form-select']) }}>
    @foreach($options as $n)<option value="{{ $n }}" @selected((int) request('per_page', $default) === $n)>{{ $n }} per halaman</option>@endforeach
</select>
