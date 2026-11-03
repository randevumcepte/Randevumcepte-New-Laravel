@extends('layout.panel')

@section('baslik', 'Form Yönetimi')
@section('aktif', 'arsivyonetimi')

@push('stil')
    <link rel="stylesheet" href="/css/formlar-v2.css?v=1">
@endpush

@section('icerik')
    {{-- Island build gerektirmez; /js/formlar-v2.js statik servis edilir (public/).
         ?musteri_id= ile tek müşterinin belgeleri (müşteri detayı "Sözleşmeler/Belgeler"). --}}
    <div id="rc-formlar" data-salon="{{ $salonId }}"></div>
@endsection

@push('betik')
    <script type="module" src="/js/formlar-v2.js?v=1"></script>
@endpush
