         {{-- ================= HAKKIMIZDA / STORY ================= --}}
         @if(!empty($salon->aciklama))
         <section class="slp-section">
            <div class="slp-section__head">
               <span class="slp-eyebrow">Hakkımızda</span>
               <h2 class="slp-section__title">Sadece bir salon değil, bir deneyim.</h2>
               <p class="slp-section__sub">Profesyonel ekibimiz, modern anlayışımız ve kişisel dokunuşlarımızla sizi ağırlıyoruz.</p>
            </div>
            <div class="slp-about">
               <p>{!! nl2br(e($salon->aciklama)) !!}</p>
            </div>
         </section>
         @endif

         {{-- ================= HIZMETLER ================= --}}
         @if(count($kategoriler))
         <section class="slp-section slp-section--alt">
            <div class="slp-section__head">
               <span class="slp-eyebrow">Hizmetler</span>
               <h2 class="slp-section__title">Profesyonel Hizmetlerimiz</h2>
               <p class="slp-section__sub">Sizi en iyi şekilde ağırlamak için geniş hizmet yelpazemiz.</p>
            </div>
            @php
               $_kategoriIconMap = [
                   'saç' => 'fa-magic',
                   'makyaj' => 'fa-paint-brush',
                   'tırnak' => 'fa-hand-peace-o',
                   'cilt' => 'fa-heart',
                   'kaş' => 'fa-eye',
                   'masaj' => 'fa-spa',
                   'epilasyon' => 'fa-bolt',
                   'gelin' => 'fa-diamond',
                   'sakal' => 'fa-user',
                   'bay' => 'fa-male',
                   'bayan' => 'fa-female',
               ];
            @endphp
            <div class="slp-services-grid">
               @foreach(array_slice($kategoriler, 0, 10) as $kat)
                  @php
                     $_katAdi = $kat['ad'];
                     $_katSayi = $kat['hizmet_sayisi'];
                     $_katIcon = 'fa-magic';
                     foreach ($_kategoriIconMap as $kw => $ic) {
                         if (mb_stripos($_katAdi, $kw) !== false) { $_katIcon = $ic; break; }
                     }
                  @endphp
                  <div class="slp-service-card">
                     <div class="slp-service-card__icon"><i class="fa {{$_katIcon}}"></i></div>
                     <h3>{{$_katAdi}}</h3>
                     <p>{{$_katSayi}} hizmet seçeneği</p>
                     @if(!empty($salon->musteri_online_randevu_aktif))
                     <a href="#randevu-al" data-slp-open data-slp-category="{{ $kat['id'] }}">Randevu Al <i class="fa fa-arrow-right"></i></a>
                     @endif
                  </div>
               @endforeach
            </div>
         </section>
         @endif

         {{-- ================= NEDEN BIZ / FEATURES ================= --}}
         <section class="slp-section">
            <div class="slp-section__head">
               <span class="slp-eyebrow">Neden Biz?</span>
               <h2 class="slp-section__title">Farkımız Detaylarda</h2>
               <p class="slp-section__sub">{{$salon->salon_adi}}'i tercih etmeniz için pek çok sebep var.</p>
            </div>
            <div class="slp-features-grid">
               <div class="slp-feature">
                  <div class="slp-feature__icon"><i class="fa fa-user-md"></i></div>
                  <h3>Uzman Ekip</h3>
                  <p>Alanında deneyimli profesyonel personelimiz en yeni teknikleri kullanarak hizmet veriyor.</p>
               </div>
               <div class="slp-feature">
                  <div class="slp-feature__icon"><i class="fa fa-heart"></i></div>
                  <h3>Hijyenik Ortam</h3>
                  <p>Tüm ekipman ve alanlarımız her kullanım öncesi özenle dezenfekte edilir.</p>
               </div>
               <div class="slp-feature">
                  <div class="slp-feature__icon"><i class="fa fa-mobile"></i></div>
                  <h3>Online Randevu</h3>
                  <p>7/24 dilediğiniz saatte birkaç tıkla randevunuzu anında onaylayın.</p>
               </div>
               <div class="slp-feature">
                  <div class="slp-feature__icon"><i class="fa fa-diamond"></i></div>
                  <h3>Kaliteli Ürünler</h3>
                  <p>Sadece güvenilir markaların profesyonel serilerini kullanıyoruz.</p>
               </div>
               <div class="slp-feature">
                  <div class="slp-feature__icon"><i class="fa fa-clock-o"></i></div>
                  <h3>Dakiklik</h3>
                  <p>Randevu saatinize tam zamanında başlıyor, vaktinize değer veriyoruz.</p>
               </div>
               <div class="slp-feature">
                  <div class="slp-feature__icon"><i class="fa fa-star"></i></div>
                  <h3>Müşteri Memnuniyeti</h3>
                  <p>{{$puan['toplam_yorum']}}+ mutlu müşterimizin deneyimini siz de yaşayın.</p>
               </div>
               <div class="slp-feature">
                  <div class="slp-feature__icon"><i class="fa fa-gift"></i></div>
                  <h3>Sadakat Hediyeleri</h3>
                  <p>Her ziyaretinizde puan biriktirin, çarkı çevirin ve özel indirim fırsatları elde edin.</p>
               </div>
               <div class="slp-feature">
                  <div class="slp-feature__icon"><i class="fa fa-coffee"></i></div>
                  <h3>Konforlu Ortam</h3>
                  <p>Keyifle vakit geçirebileceğiniz ferah, şık ve modern bir atmosfer sizi bekliyor.</p>
               </div>
            </div>
         </section>
