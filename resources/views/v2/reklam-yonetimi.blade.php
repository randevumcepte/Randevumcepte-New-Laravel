@extends('layout.panel')

@section('baslik', 'Reklam Yönetimi')
@section('aktif', 'kampanya_yonetimi')

@push('stil')
    <link rel="stylesheet" href="/css/reklam-yonetimi-v2.css?v=1">
@endpush

@section('icerik')
    {{-- Island build gerektirmez; veri /api/v2/kampanya/* (Bearer). Yazmalar KAMPANYA_V2_YAZMA_AKTIF kapalıyken 409. --}}
    <div id="rc-reklam" data-salon="{{ $salonId }}"></div>
@endsection

@push('betik')
    <script type="module" src="/js/reklam-yonetimi-v2.js?v=1"></script>
@endpush
