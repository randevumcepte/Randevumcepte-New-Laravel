@extends('site.hesap.layout')
@section('baslik', 'Resimlerim')
@push('stil')
<link rel="stylesheet" href="{{ asset('site/css/hesap-belgeler.css') }}?v=1">
@endpush
@section('content')
{{-- Mobil musteripaneli/menu/musteriresimleri.dart karşılığı; içerik public/js/site/hesap-resimler.js ile çizilir.
     Dosya yolları veritabanında göreli (public/musteri_gorselleri/...); kök eski panel adresidir. --}}
<div class="profile-card profile-card--list" id="resimSayfa" data-kok="{{ \App\Support\EskiPanelLinki::url('') }}">
   <div class="profile-card-header">
      <h2><i class="fa fa-picture-o"></i> Resimlerim</h2>
      <label class="profile-action-btn hb-dosya-dugme">
         <input type="file" id="resimDosya" accept="image/*" hidden>
         <i class="fa fa-camera"></i> <span>Foto Ekle</span>
      </label>
   </div>
   <div class="profile-card-body">
      <div class="hb-ozet" id="resimOzet" hidden>
         <span class="hb-ozet-ikon"><i class="fa fa-picture-o"></i></span>
         <div><strong id="resimToplam"></strong><small id="resimGunSayisi"></small></div>
      </div>
      <div class="profile-empty" id="resimBos" hidden>
         <i class="fa fa-picture-o"></i>
         <p><strong>Henüz fotoğrafın yok</strong></p>
         <small>Kameranla çek ya da galeriden seç — eklediğin fotoğraflar salonun panelinde de görünür.</small>
         <div><label class="profile-action-btn hb-dosya-dugme hb-bos-dugme" for="resimDosya"><i class="fa fa-camera"></i> <span>Fotoğraf Ekle</span></label></div>
      </div>
      <div id="resimGruplar"></div>
   </div>
</div>
<dialog class="hs-pencere hb-goruntuleyici" id="resimPencere" aria-label="Fotoğraf"></dialog>
@endsection
@push('betik')
<script type="module" src="{{ asset('js/site/hesap-resimler.js') }}?v=1"></script>
@endpush
