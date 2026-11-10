@extends('site.hesap.layout')
@section('baslik', 'Satın Aldıklarım')
@push('stil')
<link rel="stylesheet" href="{{ asset('site/css/hesap-seanslar.css') }}?v=1">
@endpush
@section('content')
{{-- Mobil MusteriPaneliAdiayonlari (Satın Aldıklarım) karşılığı; içerik public/js/site/hesap-satin-aldiklarim.js ile çizilir. --}}
<div class="profile-card profile-card--list">
   <div class="profile-card-header">
      <h2><i class="fa fa-shopping-bag"></i> Satın Aldıklarım <small class="hs-alt" id="satisAlt">Yükleniyor...</small></h2>
   </div>
   <div class="profile-card-body">
      <div class="hs-cipler" id="satisTurler" role="tablist"></div>
      <div class="profile-empty" id="satisBos" hidden>
         <i class="fa fa-file-text-o"></i>
         <p><strong id="satisBosBaslik">Henüz kaydın yok</strong></p>
         <small id="satisBosMetin">Satın aldıkların burada listelenecek.</small>
      </div>
      <div class="hs-grid" id="satisListe"></div>
      <div class="hs-sayfalama" id="satisSayfa" hidden></div>
   </div>
</div>
<dialog class="hs-pencere" id="satisPencere" aria-label="Satış Detayı"></dialog>
@endsection
@push('betik')
<script type="module" src="{{ asset('js/site/hesap-satin-aldiklarim.js') }}?v=1"></script>
@endpush
