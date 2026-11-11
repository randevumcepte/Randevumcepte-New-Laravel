@extends('layout.panel')

@section('baslik', 'Satış Raporları')
@section('aktif', 'raporlar')

@push('stil')
    <link rel="stylesheet" href="/css/rapor-v2.css?v=2">
@endpush

@section('icerik')
    {{-- Island build gerektirmez; veri /api/v2/rapor/satis* (Bearer). --}}
    <div id="rc-raporlar" data-salon="{{ $salonId }}"></div>
@endsection

@push('betik')
    <script type="module" src="/js/satis-raporlari-v2.js?v=1"></script>
@endpush
