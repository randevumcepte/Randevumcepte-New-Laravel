@extends('site.hesap.layout')
@section('baslik', 'Ödüllerim & Kuponlarım')
{{-- Mobil müşteri menüsündeki üç öğenin (stüdyo modunda gizli) tek sayfada sekmeli karşılığı:
     Çarkıfelek (anasayfa/carkifelek.dart — çevirme vitrindeki çarkta), Puan Ödüllerim (puan_odullerim.dart),
     Kuponlarım (odullerim.dart). Betik: public/js/site/hesap-oduller.js --}}
@push('stil')
<link rel="stylesheet" href="{{ asset('site/css/hesap-oduller.css') }}?v=1">
@endpush
@section('content')
<div class="profile-card profile-card--list" id="oduller" data-cark-link="{{ $linkler['ana'] }}?carkac=1">
   <div class="profile-card-header">
      <h2><i class="fa fa-gift"></i> Ödüllerim &amp; Kuponlarım</h2>
   </div>
   <div class="ho-sekmeler" role="tablist">
      <button type="button" class="ho-sekme" role="tab" data-sekme="cark"><i class="fa fa-life-ring"></i> Çarkıfelek</button>
      <button type="button" class="ho-sekme" role="tab" data-sekme="puan"><i class="fa fa-star"></i> Puan Ödüllerim</button>
      <button type="button" class="ho-sekme" role="tab" data-sekme="kuponlar"><i class="fa fa-ticket"></i> Kuponlarım</button>
   </div>
   <div class="profile-card-body">
      <div role="tabpanel" data-panel="cark" hidden></div>
      <div role="tabpanel" data-panel="puan" hidden></div>
      <div role="tabpanel" data-panel="kuponlar" hidden></div>
   </div>
</div>

{{-- Kampanya kuralları (mobil: Çarkıfelek ekranındaki "Kampanya Kuralları" penceresi) --}}
<dialog class="ho-pencere" id="kurallarPencere">
   <h3>Kampanya Kuralları</h3>
   <div class="ho-pencere-govde">
      <p><strong>Çarkı Çevir Kampanyası — Resmi Kurallar</strong></p>
      <p>1) Kampanya, uygulamayı işletmenize ait mobil uygulama üzerinden kullanan müşterilerimize açıktır.</p>
      <p>2) Her müşteri, hesabındaki uygun (onaylı) randevu başına 1 hak kazanır. Aynı gün içinde tek çevirme hakkı vardır.</p>
      <p>3) Çarkta kazanılan ödül, ilgili işletmede kullanılabilecek indirim kuponu veya sadakat puanıdır. Nakit ödül, kripto para veya para değerli hiçbir şey verilmez.</p>
      <p>4) Kupon kodları, işletmenin belirlediği geçerlilik süresi içinde ibraz edilerek kullanılmalıdır. İşletme süre sınırı koymamışsa kupon süresizdir. Kupon detayında yer alan bilgi geçerlidir.</p>
      <p>5) Ödül kazanan kişi kupon kodunu üçüncü şahıslara devredemez. Kupon sadece hesabın sahibine aittir.</p>
      <p>6) İşletme, kampanyanın kurallarını, ödüllerini veya çarktaki dilim oranlarını önceden haber vermeksizin değiştirme hakkını saklı tutar.</p>
      <p>7) Katılım için satın alma zorunluluğu yoktur. Uygulama üzerinden kayıt ücretsizdir.</p>
      <p>8) Bu kampanya bir çekiliş veya kumar oyunu değildir; sadakat programı kapsamında bir promosyondur.</p>
   </div>
   <div class="ho-pencere-alt">
      <button type="button" class="apt-action apt-action--danger" data-pencere-kapat>Kapat</button>
   </div>
</dialog>

{{-- Puan ödülü talebi sonucu (mobil: _PuanTalepKart) --}}
<dialog class="ho-pencere ho-pencere--orta" id="talepPencere">
   <div class="ho-buyuk-emoji">🎉</div>
   <h3>Ödülünüz Hazır!</h3>
   <p class="ho-soluk" data-talep-baslik></p>
   <small class="ho-soluk">Kupon Kodunuz</small>
   <div class="ho-kod" data-talep-kod></div>
   <small class="ho-soluk">Bu kodu 60 gün içinde salonda ibraz edin.</small>
   <div data-talep-sube></div>
   <div class="ho-ikili">
      <button type="button" class="ho-btn ho-btn--cerceve" data-talep-kuponlar>Kuponlarım</button>
      <button type="button" class="ho-btn ho-btn--mor" data-pencere-kapat>Tamam</button>
   </div>
</dialog>
@endsection
@push('betik')
<script type="module" src="{{ asset('js/site/hesap-oduller.js') }}?v=1"></script>
@endpush
