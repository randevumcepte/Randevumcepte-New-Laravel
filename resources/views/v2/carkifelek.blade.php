@extends('layout.panel')

@section('baslik', 'Çarkıfelek')
@section('aktif', 'carkifelek')

@push('stil')
    <link rel="stylesheet" href="/css/carkifelek-v2.css?v=1">
@endpush

@section('icerik')
    {{-- Island build gerektirmez; sekmeler + tüm veri /api/v2/cark/* ve /api/v2/sadakat/* (Bearer). --}}
    <div id="rc-cark" data-salon="{{ $salonId }}"></div>
@endsection

@push('betik')
    <script type="module" src="/js/carkifelek-v2.js?v=1"></script>
@endpush
