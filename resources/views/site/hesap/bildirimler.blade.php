@extends('site.hesap.layout')
@section('baslik', 'Bildirimler')
@push('stil')
<link rel="stylesheet" href="{{ asset('site/css/hesap-hesabim.css') }}?v=1">
@endpush
@section('content')
{{-- Mobil müşteri bildirimleri (musteripaneli/anasayfa/musteribildirimleri/musteribildirimleri.dart) web karşılığı. Veri: hesap-bildirimler.js --}}
<div class="profile-card profile-card--list" id="bildirimler"
     data-cark="{{ $linkler['ana'] }}?carkac=1" data-oduller="{{ $linkler['oduller'] }}#kuponlar" data-randevular="{{ $linkler['randevular'] }}"
     data-eski-kok="{{ \App\Support\EskiPanelLinki::url('') }}">
   <div class="profile-card-header">
      <h2><i class="fa fa-bell"></i> Bildirimler</h2>
      <button type="button" class="profile-action-btn" id="bdTumunuOku" hidden><i class="fa fa-check-square-o"></i> <span>Tümünü Oku</span></button>
   </div>
   <div class="profile-card-body">
      <div class="hh-bd-ozet">
         <strong id="bdToplam">0</strong>
         <span id="bdYeni"></span>
      </div>
      <div class="hh-filtre" id="bdFiltre">
         <button type="button" class="aktif" data-filtre="0">Tümü</button>
         <button type="button" data-filtre="1">Okunmamış <span class="hh-filtre-rozet" id="bdFiltreRozet" hidden></span></button>
      </div>
      <div id="bdListe"></div>
      <div class="profile-empty" id="bdBos" hidden>
         <i class="fa fa-bell-slash-o"></i>
         <p><strong id="bdBosBaslik"></strong></p>
         <small id="bdBosAlt"></small>
      </div>
      <div class="profile-empty" id="bdHata" hidden>
         <i class="fa fa-cloud"></i>
         <p><strong>Bağlantı sorunu</strong></p>
         <small>Bildirimler yüklenemedi. Tekrar deneyin.</small>
         <div class="hh-orta"><button type="button" class="profile-action-btn" id="bdTekrar"><i class="fa fa-refresh"></i> <span>Tekrar Dene</span></button></div>
      </div>
      <div class="hh-orta"><button type="button" class="profile-action-btn" id="bdDaha" hidden><span>Daha Fazla</span></button></div>
   </div>
</div>
@endsection
@push('betik')
<script type="module" src="{{ asset('js/site/hesap-bildirimler.js') }}?v=1"></script>
@endpush
