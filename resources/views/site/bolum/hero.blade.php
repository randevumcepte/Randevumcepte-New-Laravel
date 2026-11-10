   {{-- =========================== SALON LANDING HERO =========================== --}}
   <section class="slp-hero">
      <div class="slp-hero__scrim"></div>

      {{-- Top bar: logo + profil/auth (eski beyaz navbar bu alana tasindi) --}}
      <div class="slp-hero__topbar">
         <div class="slp-hero__topbar-inner">
            <a href="{{ $linkler['ana'] }}" class="slp-hero__logo" aria-label="Anasayfa">
               @if(!empty($salon->logo))
                  <img src="{{ $salon->logo }}" alt="{{ $salon->salon_adi }}">
               @else
                  <img src="{{ \App\Support\EskiPanelLinki::mutlak('public/img/randevumcepte.jpg') }}" alt="Randevumcepte">
               @endif
            </a>
            <div class="slp-hero__nav">
               <div class="slp-hero__userwrap" data-musteri-oturum hidden>
                     <button type="button" class="slp-hero__userchip" id="slpUserChip" aria-haspopup="true" aria-expanded="false">
                        <img data-musteri-resim src="{{ \App\Support\EskiPanelLinki::mutlak('public/img/auth.png') }}" alt="">
                        <span data-musteri-ad></span>
                        <i class="fa fa-caret-down" style="margin-left:4px; font-size:11px;"></i>
                     </button>
                     <div class="slp-hero__menu" id="slpUserMenu" role="menu">
                        <a href="{{ $linkler['hesabim'] }}" role="menuitem"><i class="fa fa-th-large"></i> Hesabım</a>
                        <a href="{{ $linkler['randevular'] }}" role="menuitem"><i class="fa fa-calendar"></i> Randevularım</a>
                        <a href="{{ $linkler['profil'] }}" role="menuitem"><i class="fa fa-user"></i> Profilim</a>
                        <hr>
                        <a href="#" role="menuitem" data-musteri-cikis><i class="fa fa-sign-out"></i> Çıkış Yap</a>
                     </div>
                  </div>
                  <span data-musteri-misafir>
                     <a href="{{ $linkler['giris'] }}" class="slp-hero__auth-btn">Giriş Yap</a>
                     <a href="{{ $linkler['giris'] }}#kayit" class="slp-hero__auth-btn slp-hero__auth-btn--primary">Üye Ol</a>
                  </span>
            </div>
         </div>
      </div>

      <div class="slp-hero__inner container">
         <div class="slp-hero__left">
            <span class="slp-hero__eyebrow"><i class="fa fa-bolt"></i> {{$salon->salon_turu_adi ?? 'Güzellik & Bakım'}}</span>
            <h1 class="slp-hero__title">{{ $seo['h1'] ?? $salon->salon_adi }}</h1>
            <p class="slp-hero__sub">
               @if(!empty($salon->meta_description))
                  {{ \Illuminate\Support\Str::limit($salon->meta_description, 160) }}
               @else
                  Profesyonel ekibimiz ve modern hizmet anlayışımızla sizi en iyi şekilde ağırlamak için buradayız.
               @endif
            </p>
            <div class="slp-hero__meta">
               @if($_ortPuan)
                  <span class="slp-hero__chip"><i class="fa fa-star"></i> {{$_ortPuan}} / 5 ({{$puan['toplam_yorum']}} yorum)</span>
               @endif
               <span class="slp-hero__chip"><i class="fa fa-map-marker"></i> {{ $salon->ilce_adi ?? 'Türkiye' }}</span>
               @if($_simdiAcik)
                  <span class="slp-hero__chip slp-hero__chip--open"><i class="fa fa-circle"></i> Şu an Açık · {{$_bugunMetin}}</span>
               @else
                  <span class="slp-hero__chip"><i class="fa fa-clock-o"></i> {{$_bugunMetin}}</span>
               @endif
            </div>
            <div class="slp-hero__cta">
               @if(!empty($salon->musteri_online_randevu_aktif))
               <a href="#randevu-al" class="slp-btn slp-btn--primary" data-slp-open>
                  <i class="fa fa-calendar-check-o"></i> Randevu Al
               </a>
               @endif
               @if(!empty($salon->telefon_1))
                  <a href="{{ $_telHref }}" class="slp-btn slp-btn--ghost">
                     <i class="fa fa-phone"></i> Hemen Ara
                  </a>
               @endif
            </div>
         </div>
         <div class="slp-hero__right">
            <div class="slp-hero__card">
               <div class="slp-hero__card-head">
                  @if(!empty($salon->logo))
                     <div class="slp-hero__card-logo"><img src="{{$salon->logo}}" alt="{{$salon->salon_adi}}"></div>
                  @endif
                  <div>
                     <h3 class="slp-hero__card-name">{{$salon->salon_adi}}</h3>
                     <div class="slp-hero__card-status">
                        @if($_simdiAcik)
                           <span class="slp-dot"></span> Açık · {{$_bugunMetin}}
                        @else
                           <span class="slp-dot" style="background:#F59E0B; box-shadow: 0 0 10px #F59E0B;"></span> {{$_bugunMetin}}
                        @endif
                     </div>
                  </div>
               </div>
               <div class="slp-hero__card-row">
                  <i class="fa fa-map-marker"></i>
                  <span>{{ \Illuminate\Support\Str::limit($salon->adres, 90) }}</span>
               </div>
               @if(!empty($salon->telefon_1))
                  <div class="slp-hero__card-row">
                     <i class="fa fa-phone"></i>
                     <span>{{ $_telNum }}</span>
                  </div>
               @endif
               <div class="slp-hero__card-row">
                  <i class="fa fa-users"></i>
                  <span>{{count($personeller)}} profesyonel personel</span>
               </div>
            </div>
         </div>
      </div>
   </section>

   {{-- =========================== QUICK STATS BAR =========================== --}}
   <div class="slp-stats">
      <div class="slp-stats__grid">
         <div class="slp-stat">
            <i class="fa fa-users"></i>
            <span class="slp-stat__num">{{count($personeller)}}</span>
            <span class="slp-stat__lbl">Personel</span>
         </div>
         <div class="slp-stat">
            <i class="fa fa-magic"></i>
            <span class="slp-stat__num">{{$_hizmetSayisi}}</span>
            <span class="slp-stat__lbl">Hizmet</span>
         </div>
         <div class="slp-stat">
            <i class="fa fa-star"></i>
            <span class="slp-stat__num">{{$_ortPuan ?? '—'}}</span>
            <span class="slp-stat__lbl">Puan</span>
         </div>
         <div class="slp-stat">
            <i class="fa fa-comments-o"></i>
            <span class="slp-stat__num">{{$puan['toplam_yorum']}}</span>
            <span class="slp-stat__lbl">Yorum</span>
         </div>
      </div>
   </div>
