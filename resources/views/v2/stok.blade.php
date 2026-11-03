@extends('layout.panel')

@section('baslik', 'Stok Yönetimi')
@section('aktif', 'urunler')

@push('stil')
    <link rel="stylesheet" href="/css/stok-v2.css?v=1">
@endpush

@section('icerik')
    {{-- Island build gerektirmez; /js/stok-v2.js statik servis edilir (public/). Veri /api/v2/stok/* (Bearer). --}}
    <div id="rc-stok" data-salon="{{ $salonId }}"></div>
@endsection

@push('betik')
    <script type="module" src="/js/stok-v2.js?v=4"></script>
@endpush
