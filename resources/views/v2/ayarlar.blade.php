@extends('layout.panel')

@section('baslik', 'Ayarlar')
@section('aktif', 'ayarlar')

@push('stil')
    <link rel="stylesheet" href="/css/ayarlar-v2.css?v=5">
@endpush

@section('icerik')
    {{-- Island build gerektirmez; /js/ayarlar-v2.js + /js/ayarlar/* statik servis edilir (public/). --}}
    <div id="rc-ayarlar" data-salon="{{ $salonId }}"></div>
@endsection

@push('betik')
    <script type="module" src="/js/ayarlar-v2.js?v=4"></script>
@endpush
