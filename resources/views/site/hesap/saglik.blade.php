@extends('site.hesap.layout')
@section('baslik', 'Sağlık Bilgilerim')
@push('stil')
<link rel="stylesheet" href="{{ asset('site/css/hesap-belgeler.css') }}?v=1">
@endpush
@php
   // Mobil saglikbilgileri.dart soru grupları ve metinleri (users kolonları). Değerler yalnız oturumlu müşterinin kendi kaydından okunur.
   $gruplar = [
      ['fa-heart', 'Genel Sağlık Durumu', [
         'hemofili_hastaligi_var' => 'Hemofili hastalığı var mı?',
         'seker_hastaligi_var' => 'Şeker hastalığı var mı?',
         'deri_yumusak_doku_hastaligi_var' => 'Deri veya yumuşak doku hastalığı var mı?',
         'surekli_kullanilan_ilac_Var' => 'Sürekli kullandığı ilaç var mı?',
         'kemoterapi_goruyor' => 'Kemoterapi görüyor mu?',
      ]],
      ['fa-female', 'Kadın Sağlığı', [
         'hamile' => 'Hamile mi?',
         'regl_doneminde' => 'Regl döneminde mi?',
      ]],
      ['fa-clock-o', 'Son 48 Saat', [
         'yakin_zamanda_ameliyat_gecirildi' => 'Yakın zamanda ameliyat geçirildi mi?',
         'alkol_alimi_yapildi' => '48 saat içinde alkol alımı var mı?',
      ]],
      ['fa-flask', 'Alerji & Estetik Geçmişi', [
         'alerji_var' => 'Herhangi bir alerjisi var mı?',
         'daha_once_uygulama_yaptirildi' => 'Daha önce uygulama yaptırıldı mı?',
      ]],
   ];
@endphp
@section('content')
<form id="saglikFormu" class="hb-saglik" hidden>
   <div class="hb-bilgi-bant">
      <span class="hb-bilgi-ikon"><i class="fa fa-lock"></i></span>
      <p>Sağlık bilgileriniz salonunuzla güvenli bir şekilde paylaşılır ve uygulamanızın güvenliği için kullanılır.</p>
   </div>
   <div class="row">
      @foreach($gruplar as $grup)
      <div class="col-lg-6">
         <div class="profile-card hb-grup">
            <div class="profile-card-header"><h2><i class="fa {{ $grup[0] }}"></i> {{ $grup[1] }}</h2></div>
            <div class="profile-card-body">
               @foreach($grup[2] as $alan => $soru)
               <div class="hb-soru">
                  <span class="hb-soru-metin" id="soru_{{ $alan }}">{{ $soru }}</span>
                  <div class="hb-evet-hayir" role="radiogroup" aria-labelledby="soru_{{ $alan }}">
                     <label><input type="radio" name="{{ $alan }}" value="1"><span>Evet</span></label>
                     <label><input type="radio" name="{{ $alan }}" value="0"><span>Hayır</span></label>
                  </div>
               </div>
               @endforeach
            </div>
         </div>
      </div>
      @endforeach
   </div>
   <div class="profile-card hb-grup">
      <div class="profile-card-header"><h2><i class="fa fa-leaf"></i> Cilt Tipi</h2></div>
      <div class="profile-card-body">
         <div class="hb-cipler" role="radiogroup" aria-label="Cilt Tipi">
            @foreach(\App\Domain\Musteri\MusteriSozluk::CILT_TIPLERI as $id => $ad)
            <label class="hb-cip"><input type="radio" name="cilt_tipi" value="{{ $id }}"><span>{{ $ad }}</span></label>
            @endforeach
         </div>
      </div>
   </div>
   <div class="profile-card hb-grup">
      <div class="profile-card-header"><h2><i class="fa fa-pencil-square-o"></i> Ek Sağlık Notları</h2></div>
      <div class="profile-card-body">
         <textarea name="ek_saglik_sorunu" class="modern-form-control" rows="4" maxlength="2000" placeholder="Belirtmek istediğiniz başka bir sağlık durumu..." aria-label="Ek Sağlık Notları"></textarea>
      </div>
   </div>
   <div class="btn-submit-wrapper">
      <button type="submit" class="btn-submit"><span>Bilgileri Kaydet</span> <i class="fa fa-check"></i></button>
   </div>
</form>
@endsection
@push('betik')
<script type="module" src="{{ asset('js/site/hesap-saglik.js') }}?v=1"></script>
@endpush
