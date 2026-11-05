@extends('layout.panel')

@section('baslik', 'Ön Görüşmeler')
@section('aktif', 'ongorusmeler')

@push('stil')
    <link rel="stylesheet" href="/css/ongorusmeler-v2.css?v=1">
@endpush

@section('icerik')
    {{-- Island build gerektirmez; veri /api/v2/ongorusme/* (Bearer). Yazmalar ONGORUSME_V2_YAZMA_AKTIF kapalıyken 409. --}}
    <div id="rc-ongorusme" data-salon="{{ $salonId }}"></div>
@endsection

@push('betik')
    <script type="module" src="/js/ongorusmeler-v2.js?v=4"></script>
@endpush
