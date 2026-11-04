@extends('layout.panel')

@section('baslik', 'Memnuniyet Anketi')
@section('aktif', 'anket-sonuclari')

@push('stil')
    <link rel="stylesheet" href="/css/rapor-v2.css?v=1">
    <link rel="stylesheet" href="/css/anket-v2.css?v=1">
@endpush

@section('icerik')
    {{-- Island build gerektirmez; sekmeler, sözlükler ve tüm veri /api/v2/anket/* (Bearer) — Flutter aynı uçları kullanır. --}}
    <div id="rc-anket" data-salon="{{ $salonId }}"></div>
@endsection

@push('betik')
    <script type="module" src="/js/memnuniyet-anketi-v2.js?v=1"></script>
@endpush
