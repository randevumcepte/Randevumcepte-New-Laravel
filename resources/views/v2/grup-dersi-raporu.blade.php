@extends('layout.panel')

@section('baslik', 'Grup Dersi Raporu')
@section('aktif', 'grup-dersi-raporu')

@section('icerik')
    {{-- Island build gerektirmez; veri /api/v2/ders/rapor (Bearer). --}}
    <div id="rc-grup-raporu" data-salon="{{ $salonId }}"></div>
@endsection

@push('betik')
    <script type="module" src="/js/grup-dersi-raporu-v2.js?v=1"></script>
@endpush
