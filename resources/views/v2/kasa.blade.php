@extends('layout.panel')

@section('baslik', 'Kasa Raporu')
@section('aktif', 'kasadefteri')

@push('stil')
    <link rel="stylesheet" href="/css/rapor-v2.css?v=2">
@endpush

@section('icerik')
    {{-- Island build gerektirmez; veri /api/v2/kasa/* + /api/v2/masraf/* (Bearer). Yazmalar KASA_V2_YAZMA_AKTIF kapalıyken 409. --}}
    <div id="rc-kasa" data-salon="{{ $salonId }}"></div>
@endsection

@push('betik')
    <script type="module" src="/js/kasa-v2.js?v=4"></script>
@endpush
