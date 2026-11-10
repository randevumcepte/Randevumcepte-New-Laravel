@extends('layout.panel')

@section('baslik', 'Form Yönetimi')
@section('aktif', 'arsivyonetimi')

@push('stil')
    <link rel="stylesheet" href="/css/formlar-v2.css?v=2">
@endpush

@section('icerik')
    {{-- Island build gerektirmez; /js/formlar-v2.js statik servis edilir (public/).
         Arşiv ortak bileşeni lib/v2-form.js (müşteri detayı da kullanır). --}}
    <div id="rc-formlar" data-salon="{{ $salonId }}"></div>
@endsection

@push('betik')
    <script type="module" src="/js/formlar-v2.js?v=6"></script>
@endpush
