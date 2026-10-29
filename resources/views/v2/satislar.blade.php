@extends('layout.panel')

@section('baslik', 'Satış Takibi')
@section('aktif', 'satis-takibi')

@push('stil')
    <link rel="stylesheet" href="/css/satislar-v2.css?v=1">
@endpush

@section('icerik')
    {{-- Island build gerektirmez; /js/satislar-v2.js statik servis edilir (public/). --}}
    <div id="rc-satislar" data-salon="{{ $salonId }}"></div>
@endsection

@push('betik')
    <script type="module" src="/js/satislar-v2.js?v=1"></script>
@endpush
