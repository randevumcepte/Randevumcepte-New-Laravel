@extends('layout.panel')

@section('baslik', 'Asistanım')
@section('aktif', 'e_asistan')

@push('stil')
    <link rel="stylesheet" href="/css/asistan-v2.css?v=1">
@endpush

@section('icerik')
    {{-- Island build gerektirmez; /js/asistan-v2.js statik servis edilir (public/). --}}
    <div id="rc-asistan" data-salon="{{ $salonId }}"></div>
@endsection

@push('betik')
    <script type="module" src="/js/asistan-v2.js?v=2"></script>
@endpush
