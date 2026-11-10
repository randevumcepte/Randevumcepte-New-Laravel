{{-- Hediye carki: tanitim karti + pencere. Yalniz aktif cark ve en az 2 dilim varsa (VitrinServisi 'cark' null degilse).
     Betik: public/js/site/vitrin-cark.js (misafir: /vitrin/{id}/cark/*, uye: /musteri-uygulama/cark*). --}}
@if(!empty($cark))
   {{-- ============ CARKIFELEK SECTION ============ --}}
   <a href="javascript:void(0)" data-cark-ac class="cark-section" aria-label="Hediye çarkını çevir, hediyeni al">
      <div class="cark-section__inner">
         <div class="cark-section__visual">
            <div class="cark-wheel cark-wheel--lg">
               <span class="cark-wheel__pointer"></span>
               <span class="cark-wheel__hub"><i class="fa fa-gift"></i></span>
            </div>
            <div class="cark-section__sparkles">
               <span>✨</span><span>🎁</span><span>⭐</span>
            </div>
         </div>
         <div class="cark-section__content">
            <span class="cark-section__eyebrow"><i class="fa fa-bolt"></i> Sana Özel · Hediye Çarkı</span>
            <h2 class="cark-section__title">Çarkı Çevir, <em>Hediyeni</em> Kap!</h2>
            <p class="cark-section__sub">Onaylanan her randevuyla çevirme hakkı elde edersin. Puan, indirim ve sürpriz hediyeler seni bekliyor.</p>
            <span class="cark-section__cta">
               <i class="fa fa-bolt"></i> Şimdi Tam Zamanı, Çevir!
               <i class="fa fa-long-arrow-right cark-section__cta-arrow"></i>
            </span>
         </div>
      </div>
   </a>

   {{-- ============ CARKIFELEK POPUP (SVG cark + tek tikla cevirme) ============ --}}
   <div class="cark-popup" id="carkPopup" role="dialog" aria-modal="true" aria-hidden="true" aria-labelledby="carkPopupTitle"
        data-giris="{{ $linkler['giris'] }}" data-gizlilik="{{ $linkler['gizlilik'] }}">
      <div class="cark-popup__backdrop" data-cark-close></div>
      <div class="cark-popup__panel">
         <button type="button" class="cark-popup__close" data-cark-close aria-label="Kapat">
            <i class="fa fa-times"></i>
         </button>
         <div class="cark-popup__decor cark-popup__decor--1">✨</div>
         <div class="cark-popup__decor cark-popup__decor--2">🎁</div>
         <div class="cark-popup__decor cark-popup__decor--3">⭐</div>
         <div class="cark-popup__decor cark-popup__decor--4">💎</div>

         <div class="cark-popup__wheel-wrap" style="position:relative; padding-top:14px;">
            <div style="position:relative; width:240px; height:240px; margin:0 auto; border-radius:50%; box-shadow: 0 0 0 6px #fff, 0 0 0 8px rgba(var(--slp-brand-rgb),.5), 0 26px 60px rgba(var(--slp-brand-rgb),.45); overflow:visible;">
               <span style="position:absolute; top:-14px; left:50%; transform:translateX(-50%); width:0; height:0; border-left:14px solid transparent; border-right:14px solid transparent; border-top:26px solid #FBBF24; filter:drop-shadow(0 4px 8px rgba(0,0,0,.3)); z-index:3;"></span>
               <div style="border-radius:50%; overflow:hidden; width:100%; height:100%; background:#160630;">
                  <svg id="carkPopupSvg" viewBox="0 0 300 300" style="width:100%; height:100%; display:block; transform-origin:50% 50%; transition: transform 9s cubic-bezier(0.17, 0.67, 0.12, 0.99);"></svg>
               </div>
               <span style="position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); width:56px; height:56px; border-radius:50%; background:#fff; border:3px solid #FBBF24; display:flex; align-items:center; justify-content:center; font-size:22px; color:var(--slp-brand); z-index:2; pointer-events:none;">
                  <i class="fa fa-gift"></i>
               </span>
            </div>
         </div>

         <div class="cark-popup__content" id="carkPopupContent">
            <span class="cark-popup__eyebrow">🎁 Sana Özel Hediye</span>
            <h2 class="cark-popup__title" id="carkPopupTitle">Çarkı Çevir,<br><em>Hediyeni Al!</em></h2>
            <p class="cark-popup__sub">{{ $salon->salon_adi }}'a özel hediye çarkı seni bekliyor. Ücretsiz çevirme hakkın şimdi açık!</p>
            <div id="carkPopupCevrildi" hidden style="padding:12px 18px; background:rgba(255,255,255,.18); border-radius:14px; color:#fff; font-weight:700; margin-bottom:12px;">
               ✓ Bugün çarkı çevirdiniz. Yarın tekrar deneyebilirsiniz.
            </div>
            <button type="button" class="cark-popup__cta" id="carkPopupSpinBtn" data-cark-cevir>
               <i class="fa fa-bolt"></i>
               <span>ŞİMDİ ÇEVİR</span>
            </button>
            <div data-musteri-oturum hidden>
               <a href="{{ $linkler['profil'] }}" style="display:block; text-align:center; margin-top:8px; font-size:12px; color:rgba(255,255,255,.85); text-decoration:underline;">⭐ Hesabım — Profilim</a>
            </div>
            <button type="button" class="cark-popup__skip" data-cark-close>Belki daha sonra</button>
         </div>
      </div>
   </div>

   <div id="carkPopupToast" style="position:fixed; top:20px; right:20px; z-index:100000; padding:14px 20px; background:#e17055; color:#fff; border-radius:12px; font-weight:600; box-shadow:0 10px 30px rgba(0,0,0,.2); display:none;"></div>

   <script type="application/json" id="carkVeri">{!! json_encode($cark['dilimler'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
@endif
