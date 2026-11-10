@extends('site.hesap.layout')
@section('baslik', 'Grup Derslerim')
{{-- Mobil: musteripaneli/randevularim/grup_derslerim_ekran.dart + grup_dersi_rezervasyon_ekran.dart.
     Rezervasyon düğmesi mobildeki kuralla: stüdyo modu + grup dersi + müşteri online randevu (StudyoPolitikasi::onlineRezervasyon, tek kaynak). --}}
@inject('studyoPolitika', 'App\Services\Studyo\StudyoPolitikaServisi')
@push('stil')
<link rel="stylesheet" href="{{ asset('site/css/hesap-oduller.css') }}?v=1">
@endpush
@section('content')
<div id="grupDersleri" data-rezervasyon="{{ $studyoPolitika->getir($salon->id)->onlineRezervasyon() ? 1 : 0 }}">
   {{-- Derslerim --}}
   <div class="profile-card profile-card--list" id="derslerimPanel">
      <div class="profile-card-header">
         <h2><i class="fa fa-users"></i> Grup Derslerim</h2>
         <button type="button" class="profile-action-btn" id="rezervasyonAc" hidden><i class="fa fa-plus"></i> <span>Rezervasyon Yap</span></button>
      </div>
      <div class="profile-card-body">
         <div class="profile-empty" id="derslerimBos" hidden>
            <i class="fa fa-users"></i>
            <p>Kayıtlı olduğunuz grup dersi bulunmuyor.</p>
         </div>
         <div class="profile-empty" id="derslerimHata" hidden>
            <i class="fa fa-exclamation-circle"></i>
            <p data-hata-metin></p>
         </div>
         <div class="ho-liste" id="derslerimListe"></div>
      </div>
   </div>

   {{-- Ders rezervasyonu (mobilde ayrı ekran) --}}
   <div class="profile-card profile-card--list" id="rezervasyonPanel" hidden>
      <div class="profile-card-header">
         <h2><i class="fa fa-calendar-plus-o"></i> Ders Rezervasyonu</h2>
         <button type="button" class="apt-action apt-action--danger" id="rezervasyonKapat"><i class="fa fa-arrow-left"></i> Grup Derslerim</button>
      </div>
      <div class="profile-card-body">
         <div class="profile-empty" id="rezervasyonHata" hidden>
            <i class="fa fa-exclamation-circle"></i>
            <p>Dersler yüklenemedi.</p>
            <button type="button" class="apt-action apt-action--info" id="rezervasyonTekrar">Tekrar dene</button>
         </div>
         <div id="rezervasyonIcerik" hidden>
            <div class="ho-ay" id="rezervasyonAy"></div>
            <div class="ho-gunler" id="rezervasyonGunler" role="listbox" aria-label="Gün seçin"></div>
            <div class="profile-empty" id="rezervasyonGunBos" hidden>
               <i class="fa fa-calendar-times-o"></i>
               <p>Bu gün için rezervasyona uygun ders yok.</p>
            </div>
            <div class="ho-liste" id="rezervasyonListe"></div>
         </div>
      </div>
   </div>
</div>
@endsection
@push('betik')
<script type="module" src="{{ asset('js/site/hesap-grup-dersleri.js') }}?v=1"></script>
@endpush
