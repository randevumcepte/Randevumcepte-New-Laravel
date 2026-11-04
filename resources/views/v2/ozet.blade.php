@extends('layout.panel')

@section('baslik', 'Özet')
@section('aktif', 'ozet')

@push('stil')
    <link rel="stylesheet" href="/css/ozet-v2.css?v=2">
@endpush

@section('icerik')
    {{-- Island build gerektirmez; kart/sekme/eylem listesi ve tüm sayılar /api/v2/ozet/* (Bearer).
         Flutter ana ekranı aynı uçları kullanır — burada yetki kuralı ya da hesap yok. --}}
    <div id="rc-ozet" data-salon="{{ $salonId }}"></div>
@endsection

@push('betik')
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <script type="module" src="/js/ozet-v2.js?v=2"></script>
@endpush
