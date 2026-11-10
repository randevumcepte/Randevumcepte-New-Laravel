@extends('layout.panel')

@section('baslik', 'Çağrı Merkezi')
@section('aktif', 'cagri-merkezi')

@push('stil')
    {{-- Kokpit içindeki ortak formlar: randevu formu (takvim-v2.css .t-* / .rf-*), ön görüşme formu (ongorusmeler-v2.css .og-*) --}}
    <link rel="stylesheet" href="/css/takvim-v2.css?v=17">
    <link rel="stylesheet" href="/css/ongorusmeler-v2.css?v=2">
    <link rel="stylesheet" href="/css/cagri-merkezi-v2.css?v=3">
@endpush

@section('icerik')
    {{-- Island build gerektirmez; sekmeler ve tüm veri /api/v2/cagri-merkezi/* (Bearer). --}}
    <div id="rc-cagri" data-salon="{{ $salonId }}"></div>
@endsection

@push('betik')
    <script type="module" src="/js/cagri-merkezi-v2.js?v=10"></script>
@endpush
