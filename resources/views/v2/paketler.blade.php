@extends('layout.panel')

@section('baslik', 'Paket Yönetimi')
@section('aktif', 'paketsatislari')

@push('stil')
    <link rel="stylesheet" href="/css/paketler-v2.css?v=4">
@endpush

@section('icerik')
    {{-- Island build gerektirmez; veri /api/v2/paket/* (Bearer). Yazmalar PAKET_V2_YAZMA_AKTIF kapalıyken 409. --}}
    <div id="rc-paket" data-salon="{{ $salonId }}"></div>
@endsection

@push('betik')
    <script type="module" src="/js/paketler-v2.js?v=8"></script>
@endpush
