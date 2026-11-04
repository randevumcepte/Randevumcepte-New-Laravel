         @if(count($galeri))
         <section class="slp-section">
            <div class="slp-section__head">
               <span class="slp-eyebrow">Galeri</span>
               <h2 class="slp-section__title">Salonumuzdan Kareler</h2>
               <p class="slp-section__sub">Görsellerimizle atmosferimizi yakından tanıyın.</p>
            </div>
            <div class="slp-gallery">
               @foreach($galeri as $gorsel)
                  <div class="slp-gallery__item">
                     <img src="{{ $gorsel }}" alt="Salon Görseli" loading="lazy" onclick="buyut(@js($gorsel));">
                  </div>
               @endforeach
            </div>
         </section>
         @endif

         {{-- ================= SAATLER + HARITA ================= --}}
         @if($saloncalismasaatleri->count() || !empty($salon->maps_iframe))
         <section class="slp-section slp-section--alt">
            <div class="slp-section__head">
               <span class="slp-eyebrow">Ziyaret</span>
               <h2 class="slp-section__title">Çalışma Saatleri &amp; Konum</h2>
               <p class="slp-section__sub">Açık olduğumuz saatleri ve adresimizi buradan görüntüleyebilirsiniz.</p>
            </div>
            <div class="slp-hourmap-grid">
               <div class="slp-hours">
                  <h3><i class="fa fa-clock-o"></i> Çalışma Saatleri</h3>
                  @php $_gunler = ['Pazartesi','Salı','Çarşamba','Perşembe','Cuma','Cumartesi','Pazar']; @endphp
                  @for($_i=1; $_i<=7; $_i++)
                     @php
                        $_cs = $saloncalismasaatleri->firstWhere('haftanin_gunu', $_i);
                        $_isToday = ((int) date('N')) === $_i;
                     @endphp
                     <div class="slp-hours__row {{$_isToday ? 'slp-hours__row--today' : ''}}">
                        <span class="slp-hours__day">
                           {{$_gunler[$_i-1]}}@if($_isToday) · Bugün @endif
                        </span>
                        <span class="slp-hours__time {{$_cs && $_cs->calisiyor ? '' : 'slp-hours__time--closed'}}">
                           @if($_cs && $_cs->calisiyor)
                              {{date('H:i', strtotime($_cs->baslangic_saati))}} – {{date('H:i', strtotime($_cs->bitis_saati))}}
                           @else
                              Kapalı
                           @endif
                        </span>
                     </div>
                  @endfor
               </div>
               <div class="slp-map">
                  @php
                     $_mapsSrc = $salon->maps_iframe ?? null;
                     // Admin yalnizca iframe HTML yapistirdiysa src'yi cikar
                     if ($_mapsSrc && stripos($_mapsSrc, '<iframe') !== false && preg_match('/src=["\']([^"\']+)["\']/i', $_mapsSrc, $_mm)) {
                         $_mapsSrc = $_mm[1];
                     }
                     // Bos ise adres'ten otomatik Google Maps embed fallback
                     if (empty($_mapsSrc) && !empty($salon->adres)) {
                         $_adresQuery = urlencode(trim($salon->adres.' '.($salon->ilce_adi ?? '').' '.($salon->il_adi ?? '')));
                         $_mapsSrc = 'https://maps.google.com/maps?q='.$_adresQuery.'&output=embed';
                     }
                  @endphp
                  @if(!empty($_mapsSrc))
                     <iframe src="{{$_mapsSrc}}" allowfullscreen loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
                  @else
                     <div style="height:320px; display:flex; align-items:center; justify-content:center; background:var(--slp-bg); color:var(--slp-muted); font-size:14px;">
                        <i class="fa fa-map-marker" style="font-size:28px; margin-right:10px; opacity:.4;"></i> Konum henüz eklenmedi
                     </div>
                  @endif
                  <div class="slp-map__addr">
                     <i class="fa fa-map-marker"></i>
                     <span>{{$salon->adres}}</span>
                     @if(!empty($salon->adres))
                        <a href="https://www.google.com/maps/search/?api=1&query={{urlencode($salon->adres.' '.($salon->ilce_adi ?? '').' '.($salon->il_adi ?? ''))}}" target="_blank" rel="noopener" style="margin-left:auto; color:var(--slp-brand); font-weight:600; font-size:13px; white-space:nowrap;">
                           <i class="fa fa-external-link"></i> Yol Tarifi
                        </a>
                     @endif
                  </div>
               </div>
            </div>
         </section>
         @endif
