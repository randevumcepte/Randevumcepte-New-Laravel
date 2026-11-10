   <div class="slp-drawer" id="slpDrawer" role="dialog" aria-modal="true" aria-label="Randevu Oluştur" aria-hidden="true">
      <div class="slp-drawer__backdrop" data-slp-close></div>
      <div class="slp-drawer__panel">
         <button type="button" class="slp-drawer__close" data-slp-close aria-label="Kapat">
            <i class="fa fa-times"></i>
         </button>
         <div class="slp-drawer__body">

   {{-- ======================= LUXE HERO BANNER ========================= --}}
   <div class="lx-hero" id="lxHero">
      <div class="lx-hero__grid">
         <div class="lx-hero__left">
            <span class="lx-hero__eyebrow">Online Randevu</span>
            <h2 class="lx-hero__title">{{$salon->salon_adi}} <em>&times;</em> Size Özel</h2>
            <p class="lx-hero__sub">Saniyeler içinde randevunuzu oluşturun. Dilediğiniz hizmeti, personeli ve saati seçin — gerisini biz hallederiz.</p>
            <div class="lx-hero__meta">
               @if($_ortPuan)
                  <span class="lx-hero__chip"><i class="fa fa-star"></i> {{ $_ortPuan }} / 5</span>
               @endif
               <span class="lx-hero__chip"><i class="fa fa-users"></i> {{count($personeller)}} Personel</span>
               <span class="lx-hero__chip"><i class="fa fa-map-marker"></i> {{ $salon->ilce_adi ?? $salon->il_adi ?? 'Türkiye' }}</span>
               <span class="lx-hero__chip"><i class="fa fa-shield"></i> Güvenli & Anında Onay</span>
            </div>
         </div>
         <div class="lx-hero__right">
            <div class="lx-progress" id="lxProgress" data-lstep="1">
               <div class="lx-progress__track">
                  <div class="lx-progress__bar" style="width:12.5%"></div>
                  <div class="lx-progress__dots">
                     <span class="lx-progress__dot is-active" data-lxs="1"><span>1</span></span>
                     <span class="lx-progress__dot" data-lxs="2"><span>2</span></span>
                     <span class="lx-progress__dot" data-lxs="3"><span>3</span></span>
                     <span class="lx-progress__dot" data-lxs="4"><span>4</span></span>
                  </div>
               </div>
               <div class="lx-progress__labels">
                  <span class="lx-progress__label is-active" data-lxl="1">Hizmet</span>
                  <span class="lx-progress__label" data-lxl="2">Personel</span>
                  <span class="lx-progress__label" data-lxl="3">Tarih &amp; Saat</span>
                  <span class="lx-progress__label" data-lxl="4">Onay</span>
               </div>
            </div>
         </div>
      </div>
   </div>
   {{-- ======================= /LUXE HERO =============================== --}}

   <div id="randevu-al" class="row rdv-luxe-bookingrow" style="margin-top:20px; scroll-margin-top:90px;">
      <div class="col-lg-8" id="randevusistemi">
         <div id="hizmetsecimbolumu">
            <aside class="sidebar">
               <ul class="nav nav-tabs" id="myTab" role="tablist">
                  <li class="nav-item">
                     <a class="nav-link active" id="one-tab" data-toggle="tab" href="#bayan" role="tab" aria-controls="bayan" aria-expanded="true">Kadın Bölümü</a>
                  </li>
                 
               </ul>
                              <div class="tab-content" id="myTabContent">
                  <div class="tab-pane fade show active" id="bayan" role="tabpanel" aria-labelledby="bayan-tab">
                     @foreach($kategoriler as $key => $kategori)
                                <button type="button" class="accordion {{ $key === 0 ? 'active' : '' }}" data-kategori-id="{{ $kategori['id'] }}">{{ $kategori['ad'] }} Hizmetleri
                                </button>
                                <div class="panel_accordion" @if($key === 0) style="display: block;" @endif>
                            <table class="hizmettablo">
                              @foreach($kategori['satirlar'] as $h)
                              <tr>
                                 <td style="width: 50px">
                                    <label class="checkboxcontainer">
                                    <input name="randevuhizmet[]" id="hizmet-{{ $h['hizmet_id'] }}" type="checkbox" class="icheckbox" value="{{ $h['hizmet_id'] }}" data-ad="{{ $h['ad'] }}">
                                    <span class="checkmark"></span>
                                    </label>
                                 </td>
                                 <td style="padding-top: 0">
                                    {{ $h['ad'] }}
                                 </td>
                                 <td>
                                    <p class="btn btn-primary small btn-rounded" style="width:100%; background-color:var(--slp-brand); opacity: 1; text-align: center">Bilgi Alınız</p>
                                 </td>
                              </tr>
                              @endforeach
                           </table>
                        </div>

                        @endforeach
                        <!-- <a href="#" class="btn btn-info" style="width:100%">FİYAT AL</a> -->
                     </div>
                     
                  </div>
            </aside>
            <button id="personelsecimadiminagec"  class="btn btn-primary width-100 btn-rounded" style="width:100%; margin-top: 10px; margin-bottom: 10px">DEVAM ET <i class="fa fa-chevron-right"></i></button>
            </div> 
            <div id="personelsecimbolumu" style="padding-top:10px">
            </div>
            <div id="tarihsaatsecimbolumu">
               <button id='personelseckisminageridon' style='width:auto' class='btn btn-primary'><i class="fa fa-arrow-left"></i> Geri Dön</button>
               <p style='font-size:20px; font-weight:bold; margin-top:15px'>Tarih Seçimi</p>
               <div id="tarihtablosu" class="tarihler">
                  <div class="input-radio"><input type="radio" id="bugun" name="randevutarihi" value="{{date('Y-m-d')}}" checked> <label for="bugun">Bugün</label></div>
                  <div class="input-radio"><input type="radio" id="yarin" name="randevutarihi" value="{{ date('Y-m-d', strtotime('+1 day')) }}"> <label for="yarin">Yarın</label></div>
                  @for ($i = 2; $i <= 30; $i++)
                  <div class="input-radio tarihradio"><input id="nextdays{{ $i }}" type="radio" name="randevutarihi" value="{{ date('Y-m-d', strtotime('+'.$i.' days')) }}"> <label for="nextdays{{ $i }}">{{ str_replace(['Mon','Tue','Wed','Thu','Fri','Sat','Sun'], ['Pzt','Sal','Çar','Per','Cum','Cts','Paz'], date('d.m D', strtotime('+'.$i.' days'))) }}</label></div>
                  @endfor
               </div>
               <p style='font-size:20px; font-weight:bold;margin-top: 15px'>Saat Seçimi</p>
               <div id="saatsecimtablosu" class="saatler">
               </div>
               <button id="onayadiminagec" class="btn btn-primary width-100 btn-rounded" style="width:100%; margin-top: 10px; margin-bottom: 10px">DEVAM ET <i class="fa fa-chevron-right"></i></button>
            </div>
            <div id="onaybolumu">
               <div id="kisiselbilgileralani" class="rdv-kisisel">
                  <button id='tarihsaatseckisminageridon' type="button" class="rdv-back-btn"><i class="fa fa-arrow-left"></i> Geri Dön</button>
                  <h3 class="rdv-kisisel-baslik">Son Adım — İletişim Bilgileri</h3>
                  <p class="rdv-kisisel-aciklama">Adınızı ve telefonunuzu girin; telefonunuza gelen tek kullanımlık kodla giriş yapıp randevunuzu onaylayın.</p>

                  <form id="rdv-direkt-form" autocomplete="on">
                                          {{-- Honeypot - bot'lar dolduracak, biz reddedecegiz (gozle gorunmez) --}}
                     <input type="text" name="_hp" id="_hp" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px;top:-9999px;opacity:0;height:0;width:0;" aria-hidden="true">

                     <div class="rdv-input-grup">
                        <label for="rdv_adsoyad"><i class="fa fa-user"></i> Adınız Soyadınız</label>
                        <input type="text" name="adsoyad" id="rdv_adsoyad"
                               value=""
                               placeholder="Örn. Ayşe Yılmaz"
                               minlength="2" maxlength="100" required autocomplete="name">
                     </div>

                     <div class="rdv-input-grup">
                        <label for="rdv_telefon"><i class="fa fa-mobile"></i> Cep Telefonu</label>
                        <input type="tel" id="rdv_telefon" name="cep_telefon" required>
                        <small class="rdv-input-hint">Telefonunuz randevu bildirimleri için kullanılır. Sizinle paylaşılmaz.</small>
                     </div>

                     {{-- Tek kullanimlik kod: "Kod Gönder" sonrasi gorunur (giris yapmamis musteri kodla girer) --}}
                     <div class="rdv-input-grup" id="rdv-kod-grup" style="display:none;">
                        <label for="rdv_kod"><i class="fa fa-key"></i> Doğrulama Kodu</label>
                        <div style="display:flex;gap:8px;align-items:stretch;flex-wrap:wrap;">
                           <input type="text" id="rdv_kod" inputmode="numeric" maxlength="6" placeholder="• • • •" autocomplete="one-time-code" style="flex:0 0 140px;letter-spacing:6px;font-weight:700;text-align:center;">
                           <button type="button" id="rdv-kod-tekrar" class="btn btn-light" style="white-space:nowrap;background:#f3f0fa;border:1px solid #d9d1ee;color:var(--slp-brand);font-weight:600;">
                              <i class="fa fa-repeat"></i> Kodu tekrar gönder
                           </button>
                        </div>
                        <small class="rdv-input-hint" id="rdv-kod-bilgi">Telefonunuza gelen kodu girin.</small>
                     </div>

                     <label class="rdv-kvkk-check">
                        <input type="checkbox" name="kvkk" id="rdv_kvkk" value="1" required>
                        <span><a href="{{ $linkler['gizlilik'] }}" target="_blank">Kullanım ve gizlilik koşulları</a>nı okudum, kabul ediyorum.</span>
                     </label>

                     <button type="submit" id="rdv-direkt-submit" class="rdv-direkt-btn">
                        <i class="fa fa-paper-plane"></i>
                        <span>KOD GÖNDER</span>
                     </button>
                     <div id="rdv-direkt-hata"></div>
                  </form>
               </div>
               <div id="randevudokumu">
                  <div class="col-md-12" style="text-align: center;">
                     <span class="randevuonaybaslik">Randevu Onayı</span>
                  </div>
                  <form id="randevuonayformu" method="POST">
                                                                                    <div class="randevuozetonay">
                        <div class="rdv-onay-grid">
                           <div class="rdv-onay-field">
                              <span class="rdv-onay-label">Salon adı</span>
                              <div class="rdv-onay-value">{{$salon->salon_adi}}</div>
                           </div>
                           <div class="rdv-onay-field">
                              <span class="rdv-onay-label">Seçilen hizmetler</span>
                              <div class="rdv-onay-value" id="secilenhizmetdokumu"></div>
                           </div>
                           <div class="rdv-onay-field rdv-onay-field--full">
                              <span class="rdv-onay-label">Seçilen personeller</span>
                              <div class="rdv-onay-value rdv-onay-personel" id="secilenpersoneldokumu"></div>
                           </div>
                        </div>
                        <div class="rdv-onay-datetime">
                           <div class="rdv-onay-pill">
                              <span class="rdv-onay-pill-label">Tarih</span>
                              <div class="rdv-onay-pill-value" id="randevutarihidokumu"></div>
                           </div>
                           <div class="rdv-onay-pill">
                              <span class="rdv-onay-pill-label">Saat</span>
                              <div class="rdv-onay-pill-value" id="randevusaatidokumu"></div>
                           </div>
                        </div>
                        <textarea name="randevunotu" placeholder="Randevu için notunuz..."></textarea>
                        <label class="rdv-onay-check">
                           <input type="checkbox" checked id="gizlilikkosulukabul">
                           <span><a href="{{ $linkler['gizlilik'] }}" target="_blank">Kullanım ve gizlilik koşulları</a> sayfasını okudum ve kabul ediyorum</span>
                        </label>
                        <p class="rdv-onay-confirm">Yukarıda detayları listelenen randevunuzu onaylamak istiyor musunuz?</p>
                        <button type="button" id="randevuonaylabutton" class="btn btn-success btn-rounded">Evet</button>
                     </div>
                     <div id="randevuonaybildirim" class="btn btn-success btn-rounded" style="width: 100%; text-align: center;"> </div>
                  </form>
               </div>
            </div>
         </div>
         <div class="col-lg-4" id="randevuozetbolumu">
            <div class="secilenhizmetlertablo">
               <span class="baslik">Randevu Özeti</span>
               <form id="randevuozeti" method="get">
                                                      <table class="randevuozet">
                     <tr style="border-bottom: 1px solid #e4e4e2">
                        <td>
                           Hizmetler
                        </td>
                        <td>
                           <div id="secilenhizmetlistebos">
                              Henüz hizmet seçmediniz... 
                           </div>
                           <div id="secilenhizmetliste" ></div>
               </td>
               </tr>
               <tr style="border-bottom: 1px solid #e4e4e2">
               <td>
               Personeller
               </td>
               <td>
               <div id="personellistebos">
               Henüz personel seçmediniz... 
               </div>  
               <div id="personelliste">
               </div>
               </td>
               </tr>
               <tr style="border-bottom: 1px solid #e4e4e2">
               <td>
               Tarih ve Saat
               </td>
               <td>
               <div id="tarihsaatbos">
               Henüz tarih saat seçmediniz... 
               </div>
               <div id="tarihsaat">
               </div>
               </td>
               </tr>
               <tr>
               <td colspan="2">
               </td>
               </tr>
               </table>
               </form>
               </div>
               </div>
         </div>

         </div>{{-- /.slp-drawer__body --}}
      </div>{{-- /.slp-drawer__panel --}}
   </div>{{-- /.slp-drawer --}}
