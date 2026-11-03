@extends('layout.panel')

@section('baslik', 'Uygulama Afişi')
@section('aktif', 'uygulama-afisi')

@push('stil')
    <link rel="stylesheet" href="/css/uygulama-afisi-v2.css?v=1">
@endpush

@section('icerik')
    {{-- Island build gerektirmez; durum /api/v2/uygulama-afisi, önizleme + PDF aynı uçların
         /onizleme ve /pdf'i (Bearer, blob). Afiş hazır mı / link var mı kuralı API'de. --}}
    <div id="rc-afis" data-salon="{{ $salonId }}"></div>
@endsection

@push('betik')
    <script type="module" src="/js/uygulama-afisi-v2.js?v=1"></script>
@endpush
