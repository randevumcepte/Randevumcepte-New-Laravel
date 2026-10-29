@extends('layout.panel')

@section('baslik', 'Personeller')
@section('aktif', 'personel-yonetimi')

@push('stil')
    <link rel="stylesheet" href="/css/personel-v2.css?v=1">
@endpush

@section('icerik')
    {{-- Island build gerektirmez; /js/personel-v2.js statik servis edilir (public/). --}}
    <div id="rc-personel" data-salon="{{ $salonId }}"></div>
@endsection

@push('betik')
    <script type="module" src="/js/personel-v2.js?v=1"></script>
@endpush
