@extends('layout.panel')

@section('baslik', 'Hesabım & Faturalar')
@section('aktif', 'hesabim')

@push('stil')
    <link rel="stylesheet" href="/css/hesabim-v2.css?v=1">
@endpush

@section('icerik')
    {{-- Island build gerektirmez; veri + sekme listesi /api/v2/hesabim (Bearer). --}}
    <div id="rc-hesabim" data-salon="{{ $salonId }}"></div>
@endsection

@push('betik')
    <script type="module" src="/js/hesabim-v2.js?v=1"></script>
@endpush
