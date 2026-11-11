@extends('site.hesap.layout')
@section('baslik', 'Hesabım')
@push('stil')
<link rel="stylesheet" href="{{ asset('site/css/hesap-hesabim.css') }}?v=1">
@endpush
@section('content')
{{-- Mobil müşteri ana sayfasının (musteripaneli/anasayfa/anasayfa.dart) web karşılığı. Veri: hesap-hesabim.js --}}
@php $studyo = $salon->studyo_modu; @endphp
<div class="hh" id="hesabim" data-randevu="{{ $linkler['ana'] }}#randevu-al">
   {{-- Üst çubuk: bildirim zili (okunmamış rozeti), salon adı, profil --}}
   <div class="hh-ust">
      <a href="{{ $linkler['bildirimler'] }}" class="hh-daire" id="hsZil" aria-label="Bildirimler">
         <i class="fa fa-bell-o"></i>
         <span class="hh-rozet" id="hsZilRozet" hidden></span>
      </a>
      <span class="hh-salon-adi">{{ $salon->salon_adi }}</span>
      <a href="{{ $linkler['profil'] }}" class="hh-daire" aria-label="Profilim"><i class="fa fa-user-o"></i></a>
   </div>

   {{-- Selamlama --}}
   <div class="hh-selam">
      <small id="hsSelamOn"></small>
      <h2 id="hsAd"></h2>
      <small>Bugün seni neler bekliyor?</small>
   </div>

   {{-- Hemen Randevu Al: salon online randevuya açıksa (JS gösterir) --}}
   <a href="{{ $linkler['ana'] }}#randevu-al" class="hh-hero" id="hsRandevu" hidden>
      <span class="hh-hero-ikon"><i class="fa fa-calendar"></i></span>
      <span class="hh-hero-metin">
         <small>Yeni Randevu</small>
         <strong>Hemen Randevu Al</strong>
         <em>Tarih ve hizmet seç, dakikalar içinde tamamla</em>
      </span>
      <span class="hh-ok"><i class="fa fa-arrow-right"></i></span>
   </a>

   @if($studyo && $salon->grup_dersi_aktif)
   {{-- Stüdyo + grup dersi + online randevu açık: rezervasyon kısayolu (JS gösterir) --}}
   <a href="{{ $linkler['grup-dersleri'] }}" class="hh-kart hh-satir hh-cerceve" id="hsGrupRezervasyon" hidden>
      <span class="hh-ikon"><i class="fa fa-calendar-check-o"></i></span>
      <span class="hh-satir-metin"><strong>Grup Dersi Rezervasyonu</strong><small>Uygun günü seç, yerini ayır</small></span>
      <i class="fa fa-chevron-right hh-renk"></i>
   </a>
   @endif

   {{-- Üyelik kategorisi + sadakat puanı --}}
   <div class="hh-kart hh-uyelik">
      <div class="hh-uyelik-sol">
         <span class="hh-ikon hh-ikon--daire" id="hsKategoriIkon"><i class="fa fa-user-o"></i></span>
         <span class="hh-satir-metin"><strong id="hsKategori">Pasif Müşteri</strong><small id="hsKategoriAlt">Henüz alışveriş yok</small></span>
      </div>
      <span class="hh-ayrac"></span>
      <div class="hh-uyelik-sag">
         <span class="hh-ikon hh-ikon--daire hh-ikon--dolu"><i class="fa fa-star"></i></span>
         <span class="hh-satir-metin hh-sag"><strong id="hsPuan">0</strong><small>sadakat puanı</small></span>
      </div>
   </div>

   <h3 class="hh-baslik">Sana Özel</h3>
   <div id="hsReklamlar"></div>

   @unless($studyo)
   {{-- Stüdyo modunda çarkıfelek ve puan/kupon kartları gizli (mobil ile aynı) --}}
   {{-- Çark sitenin ana sayfasındaki pencerede (vitrin-cark.js ?carkac=1 ile açar) --}}
   <a href="{{ $linkler['ana'] }}?carkac=1" class="hh-cark">
      <span class="hh-hero-ikon"><i class="fa fa-life-ring fa-spin"></i></span>
      <span class="hh-hero-metin">
         <small>Çarkıfelek</small>
         <strong>Çevir &amp; Kazan 🎁</strong>
         <em>Onaylı randevuna özel ödüller seni bekliyor</em>
      </span>
      <span class="hh-ok"><i class="fa fa-arrow-right"></i></span>
   </a>
   <div class="hh-ikili">
      <a href="{{ $linkler['oduller'] }}#puan" class="hh-kart hh-satir">
         <span class="hh-ikon hh-ikon--sari"><i class="fa fa-star"></i></span>
         <span class="hh-satir-metin"><strong>Puan Ödüllerim</strong><small>Bakiye &amp; ödüller</small></span>
      </a>
      <a href="{{ $linkler['oduller'] }}#kuponlar" class="hh-kart hh-satir">
         <span class="hh-ikon"><i class="fa fa-gift"></i></span>
         <span class="hh-satir-metin"><strong>Kuponlarım</strong><small>Kazandığın ödüller</small></span>
      </a>
   </div>
   @endunless

   {{-- Müşteri yorumları özeti; tıklayınca yorum bölümü açılır --}}
   <button type="button" class="hh-kart hh-satir hh-tam" id="hsYorumKart">
      <span class="hh-puan-daire" id="hsYorumOrt">0,0</span>
      <span class="hh-satir-metin">
         <strong>Müşteri Yorumları</strong>
         <small><span class="hh-yildizlar" id="hsYorumYildiz"></span> <span id="hsYorumAlt">Henüz yorum yok</span></small>
      </span>
      <span class="hh-ok hh-ok--acik"><i class="fa fa-arrow-right"></i></span>
   </button>

   <section class="hh-yorumlar" id="hsYorumBolum" hidden>
      <div class="hh-yorumlar-ust">
         <h3 class="hh-baslik">Müşteri Yorumları</h3>
         <button type="button" class="hh-kapat" id="hsYorumKapat" aria-label="Kapat"><i class="fa fa-times"></i></button>
      </div>
      <div class="hh-kart hh-yorum-ozet">
         <div class="hh-yorum-ozet-sol">
            <strong id="hsOzetOrt">0,0</strong>
            <span class="hh-yildizlar" id="hsOzetYildiz"></span>
            <small id="hsOzetAlt"></small>
         </div>
         <div class="hh-dagilim" id="hsDagilim"></div>
      </div>
      <form class="hh-kart hh-yorum-form" id="hsYorumForm" hidden>
         <strong>Deneyimini değerlendir</strong>
         <small>Puanın ve yorumun diğer müşterilere yardımcı olur.</small>
         <div class="hh-yildiz-sec" id="hsYildizSec">
            @for($i = 1; $i <= 5; $i++)
            <button type="button" data-puan="{{ $i }}" aria-label="{{ $i }} yıldız"><i class="fa fa-star-o"></i></button>
            @endfor
         </div>
         <textarea class="modern-form-control" id="hsYorumMetin" rows="4" maxlength="500" placeholder="Yorumunuz"></textarea>
         <button type="submit" class="profile-action-btn" id="hsYorumGonder"><i class="fa fa-paper-plane"></i> <span>Gönder</span></button>
      </form>
      <div class="hh-filtre" id="hsYorumFiltre">
         <button type="button" class="aktif" data-filtre="0">Tümü</button>
         @for($i = 5; $i >= 1; $i--)
         <button type="button" data-filtre="{{ $i }}">{{ $i }} <i class="fa fa-star"></i></button>
         @endfor
      </div>
      <div id="hsYorumListe"></div>
      <div class="profile-empty" id="hsYorumBos" hidden><i class="fa fa-commenting-o"></i><p><strong></strong></p></div>
      <div class="hh-orta"><button type="button" class="profile-action-btn" id="hsYorumDaha" hidden><span>Daha Fazla</span></button></div>
   </section>

   {{-- Kampanya ve duyurular; kampanya yoksa bilgilendirme kartı --}}
   <div id="hsKampanyalar"></div>

   <h3 class="hh-baslik">Hızlı Erişim</h3>
   <div class="hh-izgara">
      @php
         // Mobil ile aynı: stüdyoda "Aldığım Ürünler" yerine "Derslerim" ve sıra Derslerim, Randevularım, Paketler, Seanslarım.
         $randevularim = ['randevular', 'fa-calendar-check-o', 'Randevularım', 'Yaklaşan & geçmiş'];
         $seanslarim = ['seanslar', 'fa-leaf', 'Seanslarım', 'Aktif & tamamlanan'];
         $paketler = ['satin-aldiklarim', 'fa-gift', 'Aldığım Paketler', 'Kalan haklarını gör'];
         $kisayollar = $studyo
            ? [['grup-dersleri', 'fa-users', 'Derslerim', 'Grup derslerim'], $randevularim, $paketler, $seanslarim]
            : [$randevularim, $seanslarim, $paketler, ['satin-aldiklarim', 'fa-shopping-bag', 'Aldığım Ürünler', 'Satın alma geçmişi']];
      @endphp
      @foreach($kisayollar as [$anahtar, $ikon, $baslik, $alt])
      <a href="{{ $linkler[$anahtar] }}" class="hh-kart hh-kisayol">
         <span class="hh-kisayol-ust"><span class="hh-ikon"><i class="fa {{ $ikon }}"></i></span><span class="hh-mini-ok"><i class="fa fa-chevron-right"></i></span></span>
         <strong>{{ $baslik }}</strong><small>{{ $alt }}</small>
      </a>
      @endforeach
   </div>

   <h3 class="hh-baslik">İletişim</h3>
   <div class="hh-ikili">
      <a href="#" class="hh-kart hh-satir" id="hsAra"><span class="hh-ikon"><i class="fa fa-phone"></i></span><strong>Salonu Ara</strong></a>
      <a href="#" class="hh-kart hh-satir" id="hsWhatsapp" target="_blank" rel="noopener"><span class="hh-ikon hh-ikon--yesil"><i class="fa fa-whatsapp"></i></span><strong>WhatsApp</strong></a>
   </div>
</div>

{{-- Kupon kapıldı penceresi --}}
<div class="hh-perde" id="hsKuponPencere" hidden>
   <div class="hh-pencere">
      <div class="hh-emoji" id="hsKuponEmoji">🎉</div>
      <h3 id="hsKuponBaslik">Tebrikler!</h3>
      <p id="hsKuponMesaj"></p>
      <button type="button" class="profile-action-btn hh-tam" data-kapat><span>Harika!</span></button>
      <a href="{{ $linkler['oduller'] }}" class="hh-not" id="hsKuponNot">Kuponların "Ödüllerim" sayfasında</a>
   </div>
</div>

{{-- Uygunsuz yorum bildirimi penceresi --}}
<div class="hh-perde" id="hsBildirPencere" hidden>
   <form class="hh-pencere hh-sola" id="hsBildirForm">
      <h3>Uygunsuz İçerik Bildir</h3>
      <p>Bu yorumu neden bildirmek istiyorsunuz? Bildiriminiz işletme sahibine iletilecek ve 24 saat içinde değerlendirilecektir.</p>
      @foreach(['Spam veya reklam', 'Hakaret veya küfür', 'Kişisel bilgi ifşası', 'Yanıltıcı / sahte yorum', 'Diğer'] as $sebep)
      <label class="hh-secenek"><input type="radio" name="sebep" value="{{ $sebep }}"> {{ $sebep }}</label>
      @endforeach
      <button type="submit" class="profile-action-btn hh-tam" disabled><i class="fa fa-paper-plane"></i> <span>Bildir</span></button>
      <button type="button" class="hh-not" data-kapat>Vazgeç</button>
   </form>
</div>
@endsection
@push('betik')
<script type="module" src="{{ asset('js/site/hesap-hesabim.js') }}?v=1"></script>
@endpush
