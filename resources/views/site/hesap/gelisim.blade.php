@extends('site.hesap.layout')
@section('baslik', 'Gelişimim')
@push('stil')
<link rel="stylesheet" href="{{ asset('site/css/hesap-seanslar.css') }}?v=1">
@endpush
@section('content')
{{-- Mobil VucutOlcumuEkran (danışan, salt okunur) karşılığı; içerik public/js/site/hesap-gelisim.js ile çizilir. --}}
<div class="profile-card profile-card--list">
   <div class="profile-card-header">
      <h2><i class="fa fa-line-chart"></i> Gelişimim</h2>
      <button type="button" class="profile-action-btn" id="gelisimYenile"><i class="fa fa-refresh"></i> <span>Yenile</span></button>
   </div>
   <div class="profile-card-body">
      <div class="profile-empty" id="gelisimHata" hidden>
         <i class="fa fa-exclamation-circle" style="color:#ef4444;"></i>
         <p id="gelisimHataMetin"></p>
         <button type="button" class="profile-action-btn" id="gelisimTekrar"><span>Tekrar Dene</span></button>
      </div>
      <div class="profile-empty" id="gelisimBos" hidden>
         <i class="fa fa-balance-scale"></i>
         <p>Henüz ölçümünüz bulunmuyor.</p>
         <small>Stüdyonuz ölçüm girdikçe burada göreceksiniz.</small>
      </div>
      <div id="gelisimIcerik" hidden>
         <div id="gelisimOzet"></div>
         <div class="hs-grafik" id="gelisimGrafik" hidden>
            <div class="hs-grafik-ust"><strong><i class="fa fa-line-chart"></i> Gelişim Grafiği</strong><span id="gelisimFark"></span></div>
            <div class="hs-cipler" id="gelisimMetrikler"></div>
            <div class="hs-grafik-alan"><canvas id="gelisimTuval" aria-label="Gelişim grafiği"></canvas></div>
         </div>
         <h3 class="hs-bolum">Ölçüm Geçmişi</h3>
         <div id="gelisimGecmis"></div>
      </div>
   </div>
</div>
@endsection
@push('betik')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script type="module" src="{{ asset('js/site/hesap-gelisim.js') }}?v=1"></script>
@endpush
