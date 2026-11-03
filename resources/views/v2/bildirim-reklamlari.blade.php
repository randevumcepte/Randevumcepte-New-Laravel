@extends('layout.panel')

@section('baslik', 'Bildirim Reklamları')
@section('aktif', 'bildirim-reklamlari')

@push('stil')
    <link rel="stylesheet" href="/css/bildirim-reklamlari-v2.css?v=1">
@endpush

@section('icerik')
    {{-- Island build gerektirmez; veri /api/v2/bildirim-reklam/* (Bearer). Sözlükler, kurallar ve
         yetkiler API'de (form-verisi) — Flutter yönetici uygulaması aynı uçları kullanır. --}}
    <div id="rc-reklam" data-salon="{{ $salonId }}"></div>
@endsection

@push('betik')
    <script type="module" src="/js/bildirim-reklamlari-v2.js?v=1"></script>
@endpush
