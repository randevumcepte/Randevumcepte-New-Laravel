@extends('layout.panel')

@section('baslik', 'Randevular')
@section('aktif', 'randevular-liste')

@push('stil')
    {{-- Filtre çubuğu + zaman seçici rapor ekranlarıyla ortak; çekmece/form takvimle ortak. --}}
    <link rel="stylesheet" href="/css/rapor-v2.css?v=1">
    <link rel="stylesheet" href="/css/takvim-v2.css?v=12">
@endpush

@section('icerik')
    {{-- Island build gerektirmez; /js/randevular-v2.js statik servis edilir (public/). --}}
    <div id="rc-randevular" data-salon="{{ $salonId }}"></div>
@endsection

@push('betik')
    <script type="module" src="/js/randevular-v2.js?v=4"></script>
@endpush
