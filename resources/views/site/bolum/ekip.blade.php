         {{-- ================= EKIBIMIZ ================= --}}
         @if($personeller && count($personeller))
         <section class="slp-section slp-section--alt">
            <div class="slp-section__head">
               <span class="slp-eyebrow">Ekibimiz</span>
               <h2 class="slp-section__title">Profesyonel Ekibimizle Tanışın</h2>
               <p class="slp-section__sub">Her biri alanında uzman, gülümseyen yüzler sizi bekliyor.</p>
            </div>
            <div class="slp-team-grid">
               @foreach($personeller as $per)
                     @php
                        $_perResim = $per['profil_resmi'] ?: \App\Support\EskiPanelLinki::mutlak($per['cinsiyet'] == 0 ? 'public/img/author0.jpg' : 'public/img/author1.jpg');
                        $_perName = $per['personel_adi'];
                        $_perSpecialty = ! empty($per['uzmanlik']) ? $per['uzmanlik'] : ($per['unvan'] ?? '');
                     @endphp
                     <div class="slp-team-card" role="button" tabindex="0"
                          onclick="psPersonelDetayAc(this)"
                          onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();psPersonelDetayAc(this);}"
                          data-id="{{$per['id']}}"
                          data-name="{{$_perName}}"
                          data-avatar="{{ $_perResim }}"
                          data-specialty="{{$_perSpecialty}}"
                          data-experience="{{$per['yillik_tecrube'] ?? ''}}"
                          data-instagram="{{$per['instagram'] ?? ''}}"
                          data-bio="{{$per['aciklama'] ?? ''}}">
                        <div class="slp-team-card__avatar">
                           <img src="{{ $_perResim }}" alt="{{$_perName}}" loading="lazy">
                        </div>
                        <h4 class="slp-team-card__name">{{$_perName}}</h4>
                        @if(!empty($_perSpecialty))
                           <span class="slp-team-card__specialty">{{$_perSpecialty}}</span>
                        @endif
                        @if(!empty($per['aciklama']))
                           <p class="slp-team-card__bio">{{ \Illuminate\Support\Str::limit($per['aciklama'], 140) }}</p>
                        @endif
                        <div style="display:flex; flex-direction:column; gap:4px; margin-top:4px;">
                           @if(!empty($per['yillik_tecrube']))
                              <span class="slp-team-card__exp"><i class="fa fa-star"></i> {{$per['yillik_tecrube']}}+ yıl tecrübe</span>
                           @endif
                           @if(!empty($per['instagram']))
                              <span class="slp-team-card__ig">
                                 <i class="fa fa-instagram"></i> {{ '@' . ltrim($per['instagram'], '@') }}
                              </span>
                           @endif
                        </div>
                        <span class="slp-team-card__hint">
                           <i class="fa fa-info-circle"></i> Detayları gör
                        </span>
                     </div>
               @endforeach
            </div>
         </section>

         {{-- ================= PERSONEL DETAY MODAL ================= --}}
         <div class="ps-modal" id="psPersonelDetayModal" aria-hidden="true">
            <div class="ps-modal-backdrop" onclick="psPersonelDetayKapat()"></div>
            <div class="ps-modal-card" role="dialog" aria-modal="true" aria-labelledby="psModalName">
               <button type="button" class="ps-modal-close" onclick="psPersonelDetayKapat()" aria-label="Kapat">
                  <i class="fa fa-times"></i>
               </button>
               <div class="ps-modal-cover">
                  <div class="ps-modal-cover-bg"></div>
                  <div class="ps-modal-avatar-wrap">
                     <img id="psModalAvatar" src="" alt="">
                  </div>
               </div>
               <div class="ps-modal-body">
                  <h2 class="ps-modal-name" id="psModalName"></h2>
                  <span class="ps-modal-specialty" id="psModalSpecialty" style="display:none;"></span>

                  <div class="ps-modal-stats" id="psModalStats">
                     <div class="ps-stat" id="psStatExperience" style="display:none;">
                        <i class="fa fa-trophy"></i>
                        <span class="ps-stat-value" id="psModalExperience"></span>
                        <span class="ps-stat-label">Yıl Tecrübe</span>
                     </div>
                     <a class="ps-stat ps-stat--link" id="psStatInstagram" href="#" target="_blank" rel="noopener" style="display:none;">
                        <i class="fa fa-instagram"></i>
                        <span class="ps-stat-value" id="psModalInstagram"></span>
                        <span class="ps-stat-label">Instagram</span>
                     </a>
                  </div>

                  <div class="ps-modal-section" id="psBioSection" style="display:none;">
                     <h3 class="ps-modal-section-title"><i class="fa fa-user-circle-o"></i> Hakkında</h3>
                     <p class="ps-modal-bio" id="psModalBio"></p>
                  </div>

                  <div class="ps-modal-actions">
                     @if(!empty($salon->musteri_online_randevu_aktif))
                     <button type="button" class="ps-modal-btn ps-modal-btn--primary" onclick="psPersonelRandevuAl()">
                        <i class="fa fa-calendar-plus-o"></i> Bu Personelden Randevu Al
                     </button>
                     @endif
                  </div>
               </div>
            </div>
         </div>

         @endif
