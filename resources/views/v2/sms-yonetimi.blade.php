@extends('layout.panel')

@section('baslik', 'SMS Yönetimi')
@section('aktif', 'toplusms')

@push('stil')
    <link rel="stylesheet" href="/css/sms-yonetimi-v2.css?v=1">
@endpush

@section('icerik')
    {{-- Island build gerektirmez; sekmeler ve tüm veri /api/v2/sms/* (Bearer) — Flutter aynı uçları kullanır. --}}
    <div id="rc-sms" data-salon="{{ $salonId }}"></div>
@endsection

@push('betik')
    <script type="module" src="/js/sms-yonetimi-v2.js?v=2"></script>
@endpush
