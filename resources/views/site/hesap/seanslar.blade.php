@extends('site.hesap.layout')
@section('baslik', 'Seanslarım')
@push('stil')
<link rel="stylesheet" href="{{ asset('site/css/hesap-seanslar.css') }}?v=1">
@endpush
@section('content')
{{-- Mobil SeanslarDashboard karşılığı; içerik public/js/site/hesap-seanslar.js ile çizilir. --}}
<div class="profile-card profile-card--list">
   <div class="profile-card-header">
      <h2><i class="fa fa-hourglass-half"></i> Seanslarım <small class="hs-alt" id="seansAlt">Yükleniyor...</small></h2>
   </div>
   <div class="profile-card-body">
      <div class="hs-istatistik" id="seansIstatistik" hidden></div>
      <div class="profile-empty" id="seansBos" hidden>
         <i class="fa fa-leaf"></i>
         <p><strong>Henüz seansın yok</strong></p>
         <small>Salondan paket aldığında, kalan seans haklarını burada görebilirsin.</small>
      </div>
      <div class="hs-grid" id="seansListe"></div>
      <div class="hs-sayfalama" id="seansSayfa" hidden></div>
   </div>
</div>
<dialog class="hs-pencere" id="seansPencere" aria-label="Seans Detayları"></dialog>
@endsection
@push('betik')
<script type="module" src="{{ asset('js/site/hesap-seanslar.js') }}?v=1"></script>
@endpush
