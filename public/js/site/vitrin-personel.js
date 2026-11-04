// Personel detay modali + 'Bu Personelden Randevu Al' on secimi — eski inline script birebir.
            (function(){
               window.psPersonelDetayAc = function(card){
                  var d = card.dataset;
                  document.getElementById('psModalName').textContent = d.name || '';
                  document.getElementById('psModalAvatar').src = d.avatar || '';
                  document.getElementById('psModalAvatar').alt = d.name || '';

                  var sp = document.getElementById('psModalSpecialty');
                  if (d.specialty){ sp.textContent = d.specialty; sp.style.display = ''; } else sp.style.display = 'none';

                  var expCard = document.getElementById('psStatExperience');
                  if (d.experience){
                     document.getElementById('psModalExperience').textContent = d.experience + '+';
                     expCard.style.display = '';
                  } else expCard.style.display = 'none';

                  var igCard = document.getElementById('psStatInstagram');
                  if (d.instagram){
                     var ig = d.instagram.replace(/^@/, '');
                     document.getElementById('psModalInstagram').textContent = '@' + ig;
                     igCard.href = 'https://instagram.com/' + ig;
                     igCard.style.display = '';
                  } else igCard.style.display = 'none';

                  var bioSec = document.getElementById('psBioSection');
                  if (d.bio){
                     document.getElementById('psModalBio').textContent = d.bio;
                     bioSec.style.display = '';
                  } else bioSec.style.display = 'none';

                  // Personel id'yi randevu CTA'sina ekle
                  document.getElementById('psPersonelDetayModal').dataset.personelId = d.id || '';

                  document.getElementById('psPersonelDetayModal').classList.add('show');
                  document.getElementById('psPersonelDetayModal').setAttribute('aria-hidden','false');
                  document.body.style.overflow = 'hidden';
               };

               window.psPersonelDetayKapat = function(){
                  document.getElementById('psPersonelDetayModal').classList.remove('show');
                  document.getElementById('psPersonelDetayModal').setAttribute('aria-hidden','true');
                  document.body.style.overflow = '';
               };

               // Kullanici "Bu Personelden Randevu Al" deyince personel ID'yi global'e
               // yazariz; AJAX ile yuklenen <select name="personeller[]"> alaninda otomatik
               // secili hale getiririz. MutationObserver yuklenmeyi izler.
               window.psSecilenPersonelId = null;
               window.psSecilenPersonelAd = null;

               window.psPersonelRandevuAl = function(){
                  try {
                     var modal = document.getElementById('psPersonelDetayModal');
                     window.psSecilenPersonelId = modal ? (modal.dataset.personelId || null) : null;
                     window.psSecilenPersonelAd = (document.getElementById('psModalName')||{}).textContent || '';
                     window.psSecilenPersonelAd = window.psSecilenPersonelAd.trim();
                     var avatarSrc = (document.getElementById('psModalAvatar')||{}).src || '';

                     // Modal'i kapat
                     psPersonelDetayKapat();

                     // Banner ve glow efekti (CSS yuklenmis ise gorunur)
                     try { psBannerKur(window.psSecilenPersonelAd, avatarSrc); } catch(e){}

                     var el = document.getElementById('hizmetsecimbolumu');
                     if (el) {
                        try { el.scrollIntoView({ behavior: 'smooth', block: 'start' }); } catch(e){ window.scrollTo(0, el.offsetTop); }
                        el.classList.add('ps-glow');
                        setTimeout(function(){ el.classList.remove('ps-glow'); }, 2400);
                     }

                     var devamBtn = document.getElementById('personelsecimadiminagec');
                     if (devamBtn){
                        devamBtn.classList.add('ps-pulse');
                        setTimeout(function(){ devamBtn.classList.remove('ps-pulse'); }, 6000);
                     }

                     // Kullaniciya net bildirim — SweetAlert varsa onu, yoksa alert
                     setTimeout(function(){
                        var msg = '✓ ' + window.psSecilenPersonelAd + ' seçildi.\n\nŞimdi yapılacak hizmeti seç ve "DEVAM ET" butonuna bas. Personel otomatik atanacak.';
                        if (typeof window.swal === 'function') {
                           // SweetAlert 1.x veya 2.x
                           try {
                              window.swal({
                                 title: '✓ ' + window.psSecilenPersonelAd + ' seçildi',
                                 text: 'Şimdi yapılacak hizmeti seç ve "DEVAM ET" butonuna bas — personel otomatik atanacak.',
                                 icon: 'success',
                                 button: 'Hizmeti Seç'
                              });
                           } catch(e){
                              // fallback eski API
                              try { window.swal('Personel Seçildi', window.psSecilenPersonelAd + ' seçildi. Şimdi hizmeti seç ve devam et.', 'success'); } catch(e2){ alert(msg); }
                           }
                        } else if (window.Swal && typeof window.Swal.fire === 'function') {
                           window.Swal.fire({
                              icon: 'success',
                              title: window.psSecilenPersonelAd + ' seçildi',
                              text: 'Şimdi yapılacak hizmeti seç ve "DEVAM ET" butonuna bas — personel otomatik atanacak.',
                              confirmButtonText: 'Hizmeti Seç',
                              confirmButtonColor: (getComputedStyle(document.documentElement).getPropertyValue('--slp-brand').trim() || '#5C008E')
                           });
                        } else {
                           alert(msg);
                        }
                     }, 350);
                  } catch(err){
                     console.error('psPersonelRandevuAl hata:', err);
                     alert('Bir hata olustu: ' + (err && err.message ? err.message : err));
                  }
               };

               window.psBannerKur = function(ad, avatar){
                  var existing = document.getElementById('psPersonelBanner');
                  if (existing) existing.remove();

                  var banner = document.createElement('div');
                  banner.id = 'psPersonelBanner';
                  banner.className = 'ps-personel-banner';
                  banner.innerHTML =
                     '<img class="ps-personel-banner__avatar" src="'+ avatar +'" alt="">' +
                     '<div class="ps-personel-banner__text">' +
                        '<div class="ps-personel-banner__title">' + ad + ' seçildi</div>' +
                        '<div class="ps-personel-banner__sub">İstediğin hizmet(leri) seç ve <b>DEVAM ET</b>\'e bas — personel otomatik atanacak.</div>' +
                     '</div>' +
                     '<button type="button" class="ps-personel-banner__close" onclick="psBannerKaldir()" aria-label="İptal"><i class="fa fa-times"></i></button>';
                  var ref = document.getElementById('hizmetsecimbolumu');
                  if (ref && ref.parentNode) ref.parentNode.insertBefore(banner, ref);
               };

               window.psBannerKaldir = function(){
                  var b = document.getElementById('psPersonelBanner');
                  if (b) b.remove();
                  window.psSecilenPersonelId = null;
                  window.psSecilenPersonelAd = null;
               };

               // Personel secim formu AJAX ile yuklenince bizim secimi uygula
               var personelObserver = null;
               function psWatchPersonelSecim(){
                  var bolum = document.getElementById('personelsecimbolumu');
                  if (!bolum || personelObserver) return;
                  personelObserver = new MutationObserver(function(){
                     if (!window.psSecilenPersonelId) return;
                     var selects = bolum.querySelectorAll('select[name="personeller[]"]');
                     if (!selects.length) return;
                     var anyApplied = false;
                     selects.forEach(function(sel){
                        var hasOpt = false;
                        Array.from(sel.options).forEach(function(opt){
                           if (String(opt.value) === String(window.psSecilenPersonelId)) {
                              opt.selected = true;
                              hasOpt = true;
                           } else {
                              opt.selected = false;
                           }
                        });
                        if (hasOpt){
                           // Trigger change event (for any listeners)
                           sel.dispatchEvent(new Event('change', { bubbles: true }));
                           anyApplied = true;
                        }
                     });
                     if (anyApplied) {
                        psShowToast('✓ ' + (window.psSecilenPersonelAd || 'Personel') + ' otomatik seçildi — şimdi tarih ve saat seç');
                        // Banner'i guncelle
                        var b = document.getElementById('psPersonelBanner');
                        if (b){
                           var sub = b.querySelector('.ps-personel-banner__sub');
                           if (sub) sub.innerHTML = '<i class="fa fa-check-circle" style="color:#34d399"></i> Personel otomatik atandı — tarih ve saati seç.';
                           b.classList.add('ps-personel-banner--ok');
                        }
                        // Bir kez uygulayalim, tekrar tekrar tetiklemesin
                        window.psSecilenPersonelId = null;
                     }
                  });
                  personelObserver.observe(bolum, { childList: true, subtree: true });
               }
               psWatchPersonelSecim();
               // DOMContentLoaded sonrasi tekrar dene
               if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', psWatchPersonelSecim);

               // Toast helper
               function psShowToast(msg){
                  var t = document.getElementById('psToast');
                  if (!t){
                     t = document.createElement('div');
                     t.id = 'psToast';
                     t.style.cssText = 'position:fixed;left:50%;bottom:30px;transform:translateX(-50%) translateY(20px);background:linear-gradient(135deg,var(--slp-brand) 0%,var(--slp-brand-3) 100%);color:#fff;padding:14px 22px;border-radius:30px;font-weight:700;font-size:13px;letter-spacing:.3px;box-shadow:0 14px 30px rgba(var(--slp-brand-rgb),.4);z-index:99999;opacity:0;transition:opacity .25s ease,transform .25s ease;max-width:90%;text-align:center;';
                     document.body.appendChild(t);
                  }
                  t.textContent = msg;
                  requestAnimationFrame(function(){
                     t.style.opacity = '1';
                     t.style.transform = 'translateX(-50%) translateY(0)';
                  });
                  clearTimeout(t._hideTimer);
                  t._hideTimer = setTimeout(function(){
                     t.style.opacity = '0';
                     t.style.transform = 'translateX(-50%) translateY(20px)';
                  }, 4000);
               }

               document.addEventListener('keydown', function(e){
                  if (e.key === 'Escape'){
                     var m = document.getElementById('psPersonelDetayModal');
                     if (m && m.classList.contains('show')) psPersonelDetayKapat();
                  }
               });
            })();
