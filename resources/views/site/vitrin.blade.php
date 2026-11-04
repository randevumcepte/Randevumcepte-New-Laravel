@extends('site.layout')

@section('content')
<div id="googlemapsarea" tabindex="-1" style="display: none">
   <iframe src="{{ $salon->maps_iframe }}" style="width:100%; height: 300px"  frameborder="0" style="border:0" allowfullscreen></iframe>
   <button id="mapshidingbutton" class="btn btn-secondary" style="display:none; width: 100%">HARİTAYI GİZLE</button>
</div>
<section class="block">
   <div class="container">
   <div class="row">
      <ul class="nav2 randevumenu" id="randevunavigation1">
         <li id="hizmetsecbaslik" class="active" style="width: 25%">
            <a href="#">
               <p class="randevuadimlarirakam"> 1</p>
               <span class="randevuadimbaslik">Hizmet Seç<span>
            </a>
         </li>
         <li id="personelsecbaslik" style="width: 25%">
            <a href="#">
               <p class="randevuadimlarirakam"> 2</p>
               <span class="randevuadimbaslik">Personel Seç<span>
            </a>
         </li>
         <li id="tarihsaatsecbaslik" style="width: 25%">
            <a href="#">
               <p class="randevuadimlarirakam"> 3</p>
               <span class="randevuadimbaslik">Tarih & Saat<span>
            </a>
         </li>
         <li id="onaybaslik" style="width: 25%">
            <a href="#">
               <p class="randevuadimlarirakam"> 4</p>
               <span class="randevuadimbaslik">Randevu Onayı<span>
            </a>
         </li>
      </ul>
      <ul class="nav2 randevumenu" id="randevunavigation2">
         <li id="hizmetsecbaslikmobil" class="active" style="width: 25%">
            <a href="#">
               <p class="randevuadimlarirakam"> 1</p>
               <span class="randevuadimbaslik">Hizmet<span>
            </a>
         </li>
         <li id="personelsecbaslikmobil" style="width: 25%">
            <a href="#">
               <p class="randevuadimlarirakam"> 2</p>
               <span class="randevuadimbaslik">Personel<span>
            </a>
         </li>
         <li id="tarihsaatsecbaslikmobil" style="width: 25%">
            <a href="#">
               <p class="randevuadimlarirakam"> 3</p>
               <span class="randevuadimbaslik">Tarih-Saat<span>
            </a>
         </li>
         <li id="onaybaslikmobil" style="width: 25%">
            <a href="#">
               <p class="randevuadimlarirakam"> 4</p>
               <span class="randevuadimbaslik">Onay<span>
            </a>
         </li>
      </ul>
   </div>

   @php
       // Telefon normalizasyonu: basinda 0 yoksa ekle (+90/90 onekini at). Gosterim + tel: linki icin.
       $_telNum = preg_replace('/\D/', '', trim($salon->telefon_1 ?? ''));
       if (strlen($_telNum) === 12 && substr($_telNum, 0, 2) === '90') { $_telNum = substr($_telNum, 2); }
       if (strlen($_telNum) === 10 && $_telNum[0] !== '0') { $_telNum = '0' . $_telNum; }
       $_telHref = $_telNum !== '' ? 'tel:' . $_telNum : '';
       // Salon acik mi? Bugunun calisma saati
       $_bugun = (int) date('N');
       $_bugunCalisma = $saloncalismasaatleri->first(function($c) use ($_bugun) {
           return $c->haftanin_gunu == $_bugun;
       });
       $_simdiAcik = false;
       if ($_bugunCalisma && $_bugunCalisma->calisiyor == 1) {
           $_simdiAcik = (date('H:i') >= date('H:i', strtotime($_bugunCalisma->baslangic_saati))
                       && date('H:i') <= date('H:i', strtotime($_bugunCalisma->bitis_saati)));
       }
       $_bugunMetin = $_bugunCalisma && $_bugunCalisma->calisiyor == 1
           ? date('H:i', strtotime($_bugunCalisma->baslangic_saati)).' - '.date('H:i', strtotime($_bugunCalisma->bitis_saati))
           : 'Bugün Kapalı';
       $_ortPuan = $puan['toplam_puan'] > 0 ? number_format($puan['ortalama'], 1) : null;
       $_hizmetSayisi = count($hizmetler);
   @endphp
   @php
      $_igRaw = trim($salon->instagram_sayfa ?? '');
      $_igUrl = '';
      if (!empty($_igRaw)) {
          $_igUrl = preg_match('#^https?://#i', $_igRaw)
              ? $_igRaw
              : 'https://instagram.com/' . ltrim($_igRaw, '@');
      }
      $_fbRaw = trim($salon->facebook_sayfa ?? '');
      $_fbUrl = '';
      if (!empty($_fbRaw)) {
          $_fbUrl = preg_match('#^https?://#i', $_fbRaw)
              ? $_fbRaw
              : 'https://facebook.com/' . ltrim($_fbRaw, '@');
      }
   @endphp

   @include('site.bolum.hero')
   @include('site.bolum.randevu-sihirbazi')
   @include('site.bolum.yuzen-araclar')

         <div class="row">
            <div id="hata"></div>
         </div>

   @include('site.bolum.tanitim')
   @include('site.bolum.ekip')
   @include('site.bolum.galeri-saatler')
   @include('site.bolum.yorumlar')
   @include('site.bolum.kapanis')
@endsection
