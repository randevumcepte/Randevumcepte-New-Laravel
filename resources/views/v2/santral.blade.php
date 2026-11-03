@extends('layout.panel')

@section('baslik', 'Santral')
@section('aktif', 'santral')

@push('stil')
    <link rel="stylesheet" href="/css/santral-v2.css?v=1">
@endpush

@section('icerik')
    {{-- Island build gerektirmez; /js/santral-v2.js statik servis edilir (public/). --}}
    <div id="rc-santral" data-salon="{{ $salonId }}"></div>
@endsection

@push('betik')
    <script type="module" src="/js/santral-v2.js?v=1"></script>
@endpush
