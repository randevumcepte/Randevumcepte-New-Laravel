         {{-- ================= MUSTERI YORUMLARI ================= --}}
         <section class="slp-section">
            @php
               $_toplamYorumSayi = $puan['toplam_yorum'] + count($one_cikan_yorumlar);
            @endphp
            <div class="slp-section__head">
               <span class="slp-eyebrow">Müşteri Yorumları</span>
               <h2 class="slp-section__title">
                  @if($_ortPuan)
                     {{$_ortPuan}}/5 · {{$_toplamYorumSayi}} Yorum
                  @else
                     Müşteri Deneyimleri
                  @endif
               </h2>
               <p class="slp-section__sub">Bizi tercih eden değerli müşterilerimizin deneyimleri.</p>
            </div>

            <div data-musteri-oturum hidden>
               <div class="slp-review-form-wrap">
                  <h3>Deneyiminizi Paylaşın</h3>
                  <form id="salonyorumyap">
                     <div class="form-group">
                                                <label style="display:block; margin-bottom:6px; font-weight:600;">Puanlama</label>
                        <div style="display:flex; gap:6px; margin-bottom:14px;">
                           @for($_r=1; $_r<=5; $_r++)
                              <input type="radio" value="{{$_r}}" id="puanlama{{$_r}}" name="puanlama" {{$_r==5?'checked':''}} required style="display:none">
                              <label for="puanlama{{$_r}}" style="cursor:pointer; margin:0;"><div class="rating" data-rating="{{$_r}}"></div></label>
                           @endfor
                        </div>
                        <textarea class="form-control" required rows="3" placeholder="Deneyiminizi yazın..." name="yorumtext_yorum" id="yorumtext_yorum" style="resize:vertical; border:1.5px solid var(--slp-line-2); border-radius:10px; padding:12px;"></textarea>
                        <button type="submit" class="slp-btn slp-btn--primary" style="margin-top:14px;">
                           <i class="fa fa-paper-plane"></i> Yorumu Gönder
                        </button>
                     </div>
                  </form>
               </div>
            </div>

            @if($_toplamYorumSayi > 0)
               <div class="slp-reviews-grid">
                  @foreach($yorumlar as $_yorum)
                     @php
                        $_yName = $_yorum['kullanici_adi'];
                        $_yPic  = $_yorum['profil_resim'] ?: \App\Support\EskiPanelLinki::mutlak('public/img/author1.jpg');
                        $_yPuan = $_yorum['puan'];
                     @endphp
                     <div class="slp-review">
                        <div class="slp-review__head">
                           <div class="slp-review__avatar">
                              <img src="{{ $_yPic }}" alt="{{$_yName}}" loading="lazy">
                           </div>
                           <div style="flex:1; min-width:0;">
                              <p class="slp-review__name">{{$_yName}}</p>
                              <div class="slp-review__date">
                                 @if(date('d')==date('d',strtotime($_yorum['tarih'])))
                                    Bugün {{date('H:i',strtotime($_yorum['tarih']))}}
                                 @elseif(date('d')-1 == date('d',strtotime($_yorum['tarih'])))
                                    Dün {{date('H:i',strtotime($_yorum['tarih']))}}
                                 @else
                                    {{date('d.m.Y',strtotime($_yorum['tarih']))}}
                                 @endif
                              </div>
                           </div>
                           <div class="slp-review__stars">
                              @for($_i=1; $_i<=5; $_i++)<i class="fa fa-star" style="opacity:{{$_i <= $_yPuan ? 1 : 0.22}}"></i>@endfor
                           </div>
                        </div>
                        <p class="slp-review__text">{{ $_yorum['yorum'] }}</p>
                     </div>
                  @endforeach

                  {{-- Olumlu anket yorumlari (dogrulanmis musteri) --}}
                  @foreach($one_cikan_yorumlar as $_ay)
                     @php
                        $_maskName = $_ay['kullanici_adi'];
                        $_ayStar = $_ay['puan'];
                        $_ayTarih = $_ay['tarih'];
                     @endphp
                     <div class="slp-review">
                        <div class="slp-review__head">
                           <div class="slp-review__avatar">
                              <img src="{{\App\Support\EskiPanelLinki::mutlak('public/img/author1.jpg')}}" alt="{{$_maskName}}" loading="lazy">
                           </div>
                           <div style="flex:1; min-width:0;">
                              <p class="slp-review__name">{{$_maskName}}
                                 <span style="display:inline-flex; align-items:center; gap:3px; font-size:10px; font-weight:600; color:#10b981; background:#e8f9f1; padding:1px 7px; border-radius:20px; margin-left:4px; vertical-align:middle;"><i class="fa fa-check-circle"></i> Doğrulanmış</span>
                              </p>
                              <div class="slp-review__date">{{ $_ayTarih ? date('d.m.Y', strtotime($_ayTarih)) : '' }}</div>
                           </div>
                           <div class="slp-review__stars">
                              @for($_i=1; $_i<=5; $_i++)<i class="fa fa-star" style="opacity:{{$_i <= $_ayStar ? 1 : 0.22}}"></i>@endfor
                           </div>
                        </div>
                        <p class="slp-review__text">{{ $_ay['yorum'] }}</p>
                     </div>
                  @endforeach
               </div>
            @else
               <p style="text-align:center; color:var(--slp-muted); padding:20px;">Henüz yorum yapılmamış — ilk yorumu yapan siz olun!</p>
            @endif
         </section>
