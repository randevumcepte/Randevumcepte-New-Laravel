@extends('layout.panel')

@section('baslik', 'WhatsApp Yönetimi')
@section('aktif', 'whatsapp')

@push('stil')
    <link rel="stylesheet" href="/css/whatsapp-v2.css?v=2">
@endpush

@section('icerik')
    {{-- Island build gerektirmez; sekmeler + tüm veri /api/v2/whatsapp/* (Bearer). Grafik Chart.js (lib/v2-grafik.js). --}}
    <div id="rc-whatsapp" data-salon="{{ $salonId }}"></div>
@endsection

@push('betik')
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <script type="module" src="/js/whatsapp-v2.js?v=1"></script>
@endpush
