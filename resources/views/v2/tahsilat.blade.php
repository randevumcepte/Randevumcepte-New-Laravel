@extends('layout.panel')

@section('baslik', $adisyonId ? 'Satış & Tahsilat' : 'Yeni Satış')
@section('aktif', 'tahsilat-modern')

@push('stil')
    <link rel="stylesheet" href="/css/tahsilat-v2.css?v=8">
@endpush

@section('icerik')
    {{-- Island build gerektirmez; /js/tahsilat-v2.js statik servis edilir (public/). --}}
    {{-- data-adisyon=0 → "Yeni Satış" modu (müşteri seç → ilk kalemle adisyon açılır). --}}
    <div id="rc-tahsilat" data-adisyon="{{ $adisyonId }}" data-salon="{{ $salonId ?? 0 }}"></div>
@endsection

@push('betik')
    <script type="module" src="/js/tahsilat-v2.js?v=13"></script>
@endpush
