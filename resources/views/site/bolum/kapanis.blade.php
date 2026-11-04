         {{-- ================= FINAL CTA BANNER ================= --}}
         <div class="slp-cta">
            <div class="slp-cta__inner">
               <div class="slp-cta__text">
                  <h2>Size Özel Deneyime Hazır mısınız?</h2>
                  <p>Saniyeler içinde randevunuzu oluşturun, uzman ekibimizle tanışmaya gelin.</p>
               </div>
               <div class="slp-cta__actions">
                  @if(!empty($salon->musteri_online_randevu_aktif))
                  <a href="#randevu-al" class="slp-btn slp-btn--primary" data-slp-open>
                     <i class="fa fa-calendar-check-o"></i> Hemen Randevu Al
                  </a>
                  @endif
                  @if(!empty($salon->telefon_1))
                     <a href="{{ $_telHref }}" class="slp-btn slp-btn--ghost">
                        <i class="fa fa-phone"></i> Bizi Arayın
                     </a>
                  @endif
               </div>
            </div>
         </div>

         {{-- ================= SEO ANAHTAR KELIMELER / HIZMET ALANLARI ================= --}}
         @if(!empty($arama_terimleri) && is_array($arama_terimleri) && count(array_filter($arama_terimleri, function($k){ return trim($k) !== ''; })))
         <section class="slp-section slp-keywords">
            <div class="slp-section__head">
               <span class="slp-eyebrow">Hizmet Alanlarımız</span>
               <h2 class="slp-section__title">{{$salon->salon_adi}} ile Aradığınız Her Şey</h2>
               <p class="slp-section__sub">Bölgemizde sunduğumuz öne çıkan hizmetler.</p>
            </div>
            <div class="slp-keywords__grid">
               @foreach($arama_terimleri as $kelime)
                  @if(trim($kelime) !== '')
                  <div class="slp-keyword-chip">
                     <i class="fa fa-check-circle" aria-hidden="true"></i>
                     <h3>{{ucwords($kelime)}}</h3>
                  </div>
                  @endif
               @endforeach
            </div>
         </section>
         <style>
            .slp-keywords__grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:14px;max-width:1000px;margin:0 auto;padding:0 12px}
            .slp-keyword-chip{display:flex;align-items:center;gap:12px;padding:16px 18px;background:#fff;border:1px solid rgba(var(--slp-brand-rgb),.10);border-radius:14px;box-shadow:0 4px 16px rgba(var(--slp-brand-rgb),.05);transition:transform .18s ease,box-shadow .18s ease,border-color .18s ease}
            .slp-keyword-chip:hover{transform:translateY(-3px);box-shadow:0 10px 26px rgba(var(--slp-brand-rgb),.12);border-color:rgba(var(--slp-brand-rgb),.35)}
            .slp-keyword-chip i{color:var(--slp-brand-2);font-size:18px;flex:0 0 auto}
            .slp-keyword-chip h3{margin:0;font-size:15px;font-weight:600;color:#2a2340;line-height:1.35;text-transform:capitalize}
         </style>
         @endif

         {{-- ================= ILETISIM ================= --}}
         <section class="slp-section slp-section--tight">
            <div class="slp-section__head">
               <span class="slp-eyebrow">İletişim</span>
               <h2 class="slp-section__title">Bize Ulaşın</h2>
            </div>
            <div class="slp-contact-grid">
               <div class="slp-contact-card">
                  <div class="slp-contact-card__icon"><i class="fa fa-map-marker"></i></div>
                  <p class="slp-contact-card__lbl">Adres</p>
                  <p class="slp-contact-card__val">{{$salon->adres}}</p>
               </div>
               @if(!empty($salon->telefon_1))
                  <div class="slp-contact-card">
                     <div class="slp-contact-card__icon"><i class="fa fa-phone"></i></div>
                     <p class="slp-contact-card__lbl">Telefon</p>
                     <p class="slp-contact-card__val"><a href="{{ $_telHref }}">{{ $_telNum }}</a></p>
                  </div>
               @endif
               <div class="slp-contact-card">
                  <div class="slp-contact-card__icon"><i class="fa fa-share-alt"></i></div>
                  <p class="slp-contact-card__lbl">Sosyal Medya</p>
                  <div class="slp-contact-card__social">
                     @if(!empty($_igUrl))
                        <a href="{{ $_igUrl }}" target="_blank" rel="noopener" aria-label="Instagram"><i class="fa fa-instagram"></i></a>
                     @endif
                     @if(!empty($_fbUrl))
                        <a href="{{ $_fbUrl }}" target="_blank" rel="noopener" aria-label="Facebook"><i class="fa fa-facebook"></i></a>
                     @endif
                     @if(!empty($salon->musteri_online_randevu_aktif))
                     <a href="#randevu-al" data-slp-open aria-label="Randevu Al"><i class="fa fa-calendar"></i></a>
                     @endif
                  </div>
               </div>
            </div>
         </section>

   </div>
   <!--end container-->
</section>
<div id="myModal2" class="modalimage">
   <span class="modalimageclose">&times;</span>
   <img class="modalimage-content" id="img01">
   <div id="caption"></div>
</div>
