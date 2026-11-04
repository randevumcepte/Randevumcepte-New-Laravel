@extends('layout.panel')

@section('baslik', 'Profil Bilgileri')
@section('aktif', 'profil')

@push('stil')
    <link rel="stylesheet" href="/css/hesabim-v2.css?v=1">
@endpush

@section('icerik')
    {{-- Island build gerektirmez; veri /api/v2/profil (Bearer). --}}
    <div id="rc-profil" data-salon="{{ $salonId }}"></div>
@endsection

@push('betik')
    <script type="module" src="/js/profil-v2.js?v=1"></script>
@endpush
