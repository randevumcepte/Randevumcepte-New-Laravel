@extends('layout.panel')

@section('baslik', 'Müşteriler')
@section('aktif', 'musteriler')

@push('stil')
    <link rel="stylesheet" href="/css/musteri-v2.css?v=2">
@endpush

@section('icerik')
    {{-- Island build gerektirmez; /js/musteriler-v2.js statik servis edilir (public/).
         ?durum=0..4 sekme (eski ?filtre=yeni de kabul). --}}
    <div id="rc-musteriler" data-salon="{{ $salonId }}"></div>
@endsection

@push('betik')
    <script type="module" src="/js/musteriler-v2.js?v=1"></script>
@endpush
