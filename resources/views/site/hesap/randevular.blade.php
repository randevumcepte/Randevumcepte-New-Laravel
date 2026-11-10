@extends('site.hesap.layout')
@section('baslik', 'Randevularım')
@push('stil')
<link rel="stylesheet" href="{{ asset('site/css/hesap-belgeler.css') }}?v=1">
@endpush
@section('content')
{{-- Mobil randevularim.dart karşılığı: filtreler, sayfalı liste, detay penceresi ve iptal (public/js/site/hesap.js) --}}
<div class="profile-card profile-card--list">
   <div class="profile-card-header">
      <h2><i class="fa fa-calendar-check-o"></i> Randevularım</h2>
      <a href="{{ $linkler['ana'] }}#randevu-al" class="profile-action-btn"><i class="fa fa-plus"></i> <span>Yeni Randevu Al</span></a>
   </div>
   <div class="profile-card-body">
      <form id="randevuFiltre" class="hb-filtre">
         <div class="modern-form-group">
            <label for="filtreTarih"><i class="fa fa-calendar"></i> Tarih</label>
            <select name="tarih" id="filtreTarih" class="modern-form-control">
               <option value="">Tümü</option>
               <option value="bugun">Bugün</option>
               <option value="yarin">Yarın</option>
               <option value="bu_ay">Bu ay</option>
               <option value="gelecek_ay">Önümüzdeki ay</option>
               <option value="bu_yil">Bu yıl</option>
            </select>
         </div>
         <div class="modern-form-group">
            <label for="filtreDurum"><i class="fa fa-flag"></i> Durum</label>
            <select name="durum" id="filtreDurum" class="modern-form-control">
               <option value="">Tümü</option>
               <option value="0">Onay bekleyen</option>
               <option value="1">Onaylı</option>
               <option value="2">Reddedilen/İptal Edilen</option>
               <option value="3">Müşteri tarafından iptal edilen</option>
            </select>
         </div>
         <div class="modern-form-group">
            <label for="filtreKaynak"><i class="fa fa-map-marker"></i> Oluşturulduğu Yer</label>
            <select name="kaynak" id="filtreKaynak" class="modern-form-control">
               <option value="">Tümü</option>
               <option value="salon">Salon</option>
               <option value="web">Web</option>
               <option value="uygulama">Uygulama</option>
            </select>
         </div>
      </form>
      <div class="profile-empty" id="randevuBos" hidden>
         <i class="fa fa-calendar-o"></i>
         <p><strong>Henüz randevu yok</strong></p>
         <small id="randevuBosMetin"></small>
         <div><button type="button" id="filtreSifirla" class="profile-action-btn hb-bos-dugme" hidden><i class="fa fa-refresh"></i> <span>Filtreleri Sıfırla</span></button></div>
      </div>
      <div class="appointment-grid" id="randevuListe"></div>
      <div class="hs-sayfalama" id="randevuSayfa" hidden></div>
   </div>
</div>
<dialog class="hs-pencere" id="randevuPencere" aria-label="Randevu Detayı"></dialog>
@endsection
