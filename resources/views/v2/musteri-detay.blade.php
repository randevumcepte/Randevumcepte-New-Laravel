@extends('layout.panel')

@section('baslik', 'Müşteri Detayı')
@section('aktif', 'musteriler')

@push('stil')
    <link rel="stylesheet" href="/css/musteri-v2.css?v=4">
    <link rel="stylesheet" href="/css/seans-v2.css?v=5">
    <link rel="stylesheet" href="/css/formlar-v2.css?v=2">
    <link rel="stylesheet" href="/css/takvim-v2.css?v=17">
    <link rel="stylesheet" href="/css/ongorusmeler-v2.css?v=2">
@endpush

@section('icerik')
    {{-- Island build gerektirmez; /js/musteri-detay-v2.js statik servis edilir (public/).
         Sekme #hash ile acilir (#randevular, #satislar, #seanslar, #saglik, #belgeler, #olcum).
         data-eski-panel: profil resmi gibi goreli dosya yollari eski panel host'unda. --}}
    <div id="rc-musteri-detay" data-musteri="{{ $musteriId }}" data-salon="{{ $salonId }}"
         data-eski-panel="{{ rtrim(config('panel.eski_panel_url', ''), '/') }}"></div>
@endsection

@push('betik')
    <script type="module" src="/js/musteri-detay-v2.js?v=24"></script>
@endpush
