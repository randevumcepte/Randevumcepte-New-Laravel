@extends('layout.panel')

@section('baslik', 'Satış & Tahsilat')
@section('aktif', 'tahsilat-modern')

@push('stil')
    <link rel="stylesheet" href="/css/tahsilat-v2.css?v=4">
@endpush

@section('icerik')
    {{-- Island build gerektirmez; /js/tahsilat-v2.js statik servis edilir (public/). --}}
    <div id="rc-tahsilat" data-adisyon="{{ $adisyonId }}"></div>
@endsection

@push('betik')
    <script type="module" src="/js/tahsilat-v2.js?v=8"></script>
@endpush
