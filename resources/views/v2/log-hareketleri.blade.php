@extends('layout.panel')

@section('baslik', 'Log Hareketleri')
@section('aktif', 'log-hareketleri')

@push('stil')
    <link rel="stylesheet" href="/css/v2-hareket.css?v=2">
@endpush

@section('icerik')
    {{-- Island build gerektirmez; veri /api/v2/hareketler* (Bearer, salt okuma). Çizim ortak lib/v2-hareket.js. --}}
    <div id="rc-hareket" data-salon="{{ $salonId }}"></div>
@endsection

@push('betik')
    <script type="module" src="/js/log-hareketleri-v2.js?v=1"></script>
@endpush
