@extends('layout.panel')

@section('baslik', 'Seans Takibi')
@section('aktif', 'seanstakip')

@push('stil')
    <link rel="stylesheet" href="/css/seans-v2.css?v=6">
@endpush

@section('icerik')
    {{-- Island build gerektirmez; /js/seans-takibi-v2.js statik servis edilir (public/). Veri /api/v2/seans* (Bearer). --}}
    <div id="rc-seans-takibi" data-salon="{{ $salonId }}"></div>
@endsection

@push('betik')
    <script type="module" src="/js/seans-takibi-v2.js?v=7"></script>
@endpush
