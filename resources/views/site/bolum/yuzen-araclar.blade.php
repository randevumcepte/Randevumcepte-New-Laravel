   {{-- ============ BOTTOM DOCK — ORTADA CIFT BUTON (HEMEN ARA + RANDEVU AL) ============ --}}
   <div class="slp-dock" role="region" aria-label="Hizli aksiyonlar">
      @if(!empty($salon->telefon_1))
         <a href="{{ $_telHref }}" class="slp-dock__btn slp-dock__btn--call" aria-label="Hemen Ara">
            <span class="slp-dock__icon slp-dock__icon--call"><i class="fa fa-phone"></i></span>
            <span class="slp-dock__label">
               <span class="slp-dock__top">Hemen Ara</span>
               <span class="slp-dock__sub">{{ $_telNum }}</span>
            </span>
         </a>
      @endif
      @if(!empty($salon->musteri_online_randevu_aktif))
      <a href="#randevu-al" data-slp-open class="slp-dock__btn slp-dock__btn--book" aria-label="Randevu Al">
         <span class="slp-dock__icon"><i class="fa fa-calendar-check-o"></i></span>
         <span class="slp-dock__label">
            <span class="slp-dock__top">Randevu Al</span>
            <span class="slp-dock__sub">Saniyeler içinde</span>
         </span>
      </a>
      @endif
   </div>

   {{-- ============ SAGDA SABIT SOSYAL MEDYA SERIDI ============ --}}
   @if(!empty($_igUrl) || !empty($_fbUrl) || !empty($salon->telefon_1) || !empty($salon->adres))
      <aside class="slp-social" aria-label="Sosyal Medya Hesaplari">
         @if(!empty($_igUrl))
            <a href="{{ $_igUrl }}" target="_blank" rel="noopener" class="slp-social__btn slp-social__btn--ig" aria-label="Instagram'da takip et" title="Instagram'da takip et">
               <i class="fa fa-instagram"></i>
            </a>
         @endif
         @if(!empty($_fbUrl))
            <a href="{{ $_fbUrl }}" target="_blank" rel="noopener" class="slp-social__btn slp-social__btn--fb" aria-label="Facebook sayfasi" title="Facebook sayfasi">
               <i class="fa fa-facebook"></i>
            </a>
         @endif
         @if(!empty($salon->telefon_1))
            <a href="{{ $_telHref }}" class="slp-social__btn slp-social__btn--phone" aria-label="Telefon ile ara" title="Telefon">
               <i class="fa fa-phone"></i>
            </a>
         @endif
         @if(!empty($salon->maps_iframe) || !empty($salon->adres))
            <a href="https://www.google.com/maps/search/?api=1&query={{ urlencode(($salon->adres ?? '').' '.($salon->ilce_adi ?? '').' '.($salon->il_adi ?? '')) }}" target="_blank" rel="noopener" class="slp-social__btn slp-social__btn--map" aria-label="Yol Tarifi" title="Yol Tarifi">
               <i class="fa fa-map-marker"></i>
            </a>
         @endif
      </aside>
   @endif

   {{-- ============ WHATSAPP FLOATING CHAT WIDGET (sag taraf) ============ --}}
   @if(!empty($salon->telefon_1))
      @php
         // Telefon numarasini wa.me formatina cevir (sadece rakam, basina 90 ekle)
         $_waDigits = preg_replace('/\D/', '', $salon->telefon_1 ?? '');
         if (strlen($_waDigits) === 11 && substr($_waDigits, 0, 1) === '0') {
             $_waNumber = '9' . $_waDigits; // 0531... -> 90531...
         } elseif (strlen($_waDigits) === 10 && substr($_waDigits, 0, 1) === '5') {
             $_waNumber = '90' . $_waDigits; // 531... -> 90531...
         } elseif (strlen($_waDigits) >= 12 && substr($_waDigits, 0, 2) === '90') {
             $_waNumber = $_waDigits; // 90... oldugu gibi
         } else {
             $_waNumber = $_waDigits;
         }
         $_waMsg = urlencode('Merhaba, '.$salon->salon_adi.' hakkında bilgi almak istiyorum.');
         $_waLink = 'https://wa.me/'.$_waNumber.'?text='.$_waMsg;
      @endphp
      <div class="slp-wa" id="slpWa">
         <button type="button" class="slp-wa__fab" id="slpWaToggle" aria-label="WhatsApp ile yazışın">
            <i class="fa fa-whatsapp"></i>
            <span class="slp-wa__pulse"></span>
            <span class="slp-wa__badge">1</span>
         </button>
         <div class="slp-wa__panel" id="slpWaPanel" role="dialog" aria-hidden="true" aria-label="WhatsApp Sohbet">
            <div class="slp-wa__header">
               <div class="slp-wa__avatar">
                  @if(!empty($salon->logo))
                     <img src="{{ $salon->logo }}" alt="{{ $salon->salon_adi }}">
                  @else
                     <i class="fa fa-comments"></i>
                  @endif
                  <span class="slp-wa__online" title="Çevrimiçi"></span>
               </div>
               <div class="slp-wa__head-info">
                  <div class="slp-wa__name">{{ $salon->salon_adi }}</div>
                  <div class="slp-wa__status"><span class="slp-wa__dot"></span> Genellikle dakikalar içinde yanıtlar</div>
               </div>
               <button type="button" class="slp-wa__close" id="slpWaClose" aria-label="Kapat">
                  <i class="fa fa-times"></i>
               </button>
            </div>
            <div class="slp-wa__body">
               <div class="slp-wa__bubble">
                  Merhaba 👋<br>
                  <strong>{{ $salon->salon_adi }}</strong>'a hoş geldiniz!<br>
                  Hizmetler, fiyatlar veya randevu hakkında sorularınızı cevaplayalım.
               </div>
            </div>
            <div class="slp-wa__footer">
               <a href="{{ $_waLink }}" target="_blank" rel="noopener" class="slp-wa__cta">
                  <i class="fa fa-whatsapp"></i> WhatsApp'ta Yazın
               </a>
            </div>
         </div>
      </div>
   @endif
