@extends('layout.sistem')

@php
    $modul = $sayfa['modul'];
    $surum = fn (string $yol) => is_file(public_path($yol)) ? filemtime(public_path($yol)) : 0;
@endphp

@section('baslik', $sayfa['baslik'])
@section('aktif', $sayfa['aktif'])

@if ($surum("css/sistem/{$modul}.css"))
    @push('stil')
        <link rel="stylesheet" href="/css/sistem/{{ $modul }}.css?v={{ $surum("css/sistem/{$modul}.css") }}">
    @endpush
@endif

@section('icerik')
    <div id="sy-ekran" data-id="{{ $kayitId }}"></div>
@endsection

@push('betik')
    <script type="module" src="/js/sistem/{{ $modul }}.js?v={{ $surum("js/sistem/{$modul}.js") }}"></script>
@endpush
