@extends('layout.panel')

@section('baslik', 'İşletme Raporları')
@section('aktif', 'isletmeraporlari')

@push('stil')
    <link rel="stylesheet" href="/css/rapor-v2.css?v=2">
@endpush

@section('icerik')
    {{-- Island build gerektirmez; veri /api/v2/rapor/isletme/* (Bearer). Grafikler Chart.js (lib/v2-grafik.js). --}}
    <div id="rc-isletme-raporlari" data-salon="{{ $salonId }}"></div>
@endsection

@push('betik')
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <script type="module" src="/js/isletme-raporlari-v2.js?v=2"></script>
@endpush
