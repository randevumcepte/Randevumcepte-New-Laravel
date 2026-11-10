@extends('site.hesap.layout')
@section('baslik', 'Sözleşme / Belgelerim')
@push('stil')
<link rel="stylesheet" href="{{ asset('site/css/hesap-belgeler.css') }}?v=1">
@endpush
@section('content')
{{-- Mobil musteripaneli/menu/musterisözlesmeleri.dart karşılığı; içerik public/js/site/hesap-belgeler.js ile çizilir.
     Belge dosyası (uzanti) eski panelde durur; kök eski panel adresidir. --}}
<div class="profile-card profile-card--list" id="belgeSayfa" data-kok="{{ \App\Support\EskiPanelLinki::url('') }}">
   <div class="profile-card-header">
      <h2><i class="fa fa-file-text-o"></i> Sözleşmelerim <small class="hb-alt">Tüm sözleşme ve belgelerini görüntüle</small></h2>
      <span class="hb-sayac-buyuk" id="belgeSayac" hidden></span>
   </div>
   <div class="profile-card-body">
      <div class="hb-arama">
         <i class="fa fa-search"></i>
         <input type="search" id="belgeAra" class="modern-form-control" placeholder="Form veya belge adı ara..." aria-label="Form veya belge adı ara" autocomplete="off">
      </div>
      <div class="profile-empty" id="belgeBos" hidden>
         <i class="fa fa-file-text-o"></i>
         <p><strong>Henüz belge yok</strong></p>
         <small>Salon tarafından sana gönderilen sözleşme ve belgeler burada görünür.</small>
      </div>
      <div class="hb-belgeler" id="belgeListe"></div>
      <div class="hs-sayfalama" id="belgeSayfalama" hidden></div>
   </div>
</div>
<dialog class="hs-pencere" id="belgePencere" aria-label="Belge Detayı"></dialog>
@endsection
@push('betik')
<script type="module" src="{{ asset('js/site/hesap-belgeler.js') }}?v=1"></script>
@endpush
