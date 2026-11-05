@extends('layout.panel')

@section('baslik', 'Ders Programı')
@section('aktif', 'ders-programi')

@section('icerik')
    {{-- Island build gerektirmez; veri /api/v2/ders/* (Bearer). Yazmalar DERS_V2_YAZMA_AKTIF kapalıyken 409. --}}
    <div id="rc-ders-programi" data-salon="{{ $salonId }}"></div>
@endsection

@push('betik')
    <script type="module" src="/js/ders-programi-v2.js?v=2"></script>
@endpush
