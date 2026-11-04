// Isletme sitesi gorunum davranislari (drawer, akordeon, adim gostergesi, dock, WhatsApp) — eski salondetaylari inline script'i, veri erisimi YOK.
   function buyut(imgsrc){
       
         var modal2 = document.getElementById('myModal2');
   
   
       var modalImg = document.getElementById("img01");
       var captionText = document.getElementById("caption"); 
   
       modal2.style.display = "block";
       modalImg.src = imgsrc; 
       var span = document.getElementsByClassName("modalimageclose")[0];
   
   
       span.onclick = function() { 
           modal2.style.display = "none";
       }
   }
   var acc = document.getElementsByClassName("accordion");
   var i;
   
   for (i = 0; i < acc.length; i++) {
   acc[i].addEventListener("click", function() {
   this.classList.toggle("active");
   var panel = this.nextElementSibling;
   if (panel.style.display === "block") {
   panel.style.display = "none";
   } else {
   panel.style.display = "block";
   }
   });
   }

   /* ============== LUXE HERO — progress sync ============== */
   (function(){
       var sections = {
           1: document.getElementById('hizmetsecimbolumu'),
           2: document.getElementById('personelsecimbolumu'),
           3: document.getElementById('tarihsaatsecimbolumu'),
           4: document.getElementById('onaybolumu')
       };
       var bar  = document.querySelector('#lxProgress .lx-progress__bar');
       var dots = document.querySelectorAll('#lxProgress .lx-progress__dot');
       var labs = document.querySelectorAll('#lxProgress .lx-progress__label');
       if (!bar || !dots.length) return;

       function isVisible(el){
           if (!el) return false;
           var cs = window.getComputedStyle(el);
           if (cs.display === 'none' || cs.visibility === 'hidden') return false;
           return el.offsetParent !== null || cs.position === 'fixed';
       }
       function setStep(n){
           var pct = [12.5, 37.5, 62.5, 92][n-1];
           bar.style.width = pct + '%';
           dots.forEach(function(d){
               var s = parseInt(d.getAttribute('data-lxs'),10);
               d.classList.remove('is-active','is-done');
               if (s < n) d.classList.add('is-done');
               else if (s === n) d.classList.add('is-active');
           });
           labs.forEach(function(l){
               var s = parseInt(l.getAttribute('data-lxl'),10);
               l.classList.remove('is-active','is-done');
               if (s < n) l.classList.add('is-done');
               else if (s === n) l.classList.add('is-active');
           });
           document.getElementById('lxProgress').setAttribute('data-lstep', n);
       }
       function detect(){
           var active = 1;
           if (isVisible(sections[4])) active = 4;
           else if (isVisible(sections[3])) active = 3;
           else if (isVisible(sections[2])) active = 2;
           else active = 1;
           setStep(active);
       }
       detect();

       ['personelsecimadiminagec','onayadiminagec',
        'personelseckisminageridon','tarihsaatseckisminageridon'].forEach(function(id){
           var b = document.getElementById(id);
           if (b) b.addEventListener('click', function(){ setTimeout(detect, 60); });
       });

       // Observe display changes on each step section
       Object.keys(sections).forEach(function(k){
           var el = sections[k];
           if (!el) return;
           new MutationObserver(function(){ setTimeout(detect, 30); })
               .observe(el, { attributes: true, attributeFilter: ['style','class'] });
       });

       
       /* --- WhatsApp chat widget --- */
       (function(){
           var wa = document.getElementById('slpWa');
           var toggle = document.getElementById('slpWaToggle');
           var closeBtn = document.getElementById('slpWaClose');
           if (!wa || !toggle) return;
           function openWa(){ wa.classList.add('is-open'); toggle.setAttribute('aria-expanded', 'true'); }
           function closeWa(){ wa.classList.remove('is-open'); toggle.setAttribute('aria-expanded', 'false'); }
           toggle.addEventListener('click', function(e){
               e.stopPropagation();
               wa.classList.contains('is-open') ? closeWa() : openWa();
           });
           if (closeBtn) {
               closeBtn.addEventListener('click', function(e){
                   e.stopPropagation();
                   closeWa();
               });
           }
           document.addEventListener('click', function(e){
               if (!wa.contains(e.target)) closeWa();
           });
           document.addEventListener('keydown', function(e){
               if (e.key === 'Escape') closeWa();
           });
           // Ilk yuklemede badge dikkat cekmek icin sallanir
           setTimeout(function(){ toggle.classList.add('is-jiggle'); }, 1200);
           setTimeout(function(){ toggle.classList.remove('is-jiggle'); }, 4400);
       })();

       /* --- Hero topbar user chip dropdown --- */
       (function(){
           var chip = document.getElementById('slpUserChip');
           var menu = document.getElementById('slpUserMenu');
           if (!chip || !menu) return;
           chip.addEventListener('click', function(e){
               e.stopPropagation();
               var open = menu.classList.toggle('is-open');
               chip.setAttribute('aria-expanded', open ? 'true' : 'false');
           });
           document.addEventListener('click', function(e){
               if (!menu.contains(e.target) && !chip.contains(e.target)) {
                   menu.classList.remove('is-open');
                   chip.setAttribute('aria-expanded', 'false');
               }
           });
           document.addEventListener('keydown', function(e){
               if (e.key === 'Escape') {
                   menu.classList.remove('is-open');
                   chip.setAttribute('aria-expanded', 'false');
               }
           });
       })();

       /* --- Sticky hero: publish height var so summary sidebar knows how far to push --- */
       var hero = document.getElementById('lxHero');
       if (hero) {
           function measureHero() {
               document.documentElement.style.setProperty('--lx-hero-h', hero.offsetHeight + 'px');
           }
           window.addEventListener('resize', measureHero);
           window.addEventListener('load', measureHero);
           measureHero();
       }

       /* ============== RANDEVU DRAWER — open/close/hash/scroll-lock ============== */
       var drawer = document.getElementById('slpDrawer');
       if (drawer) {
           var HASH = '#randevu-al';
           var body = document.body;

           function openDrawer(pushHash, categoryId) {
               if (drawer.classList.contains('is-open')) return;
               drawer.classList.add('is-open');
               drawer.setAttribute('aria-hidden', 'false');
               body.classList.add('slp-drawer-open');
               if (pushHash !== false && window.location.hash !== HASH) {
                   history.pushState({ rdvOpen: true }, '', HASH);
               }
               // Re-measure lx-hero inside drawer after layout settles
               setTimeout(function(){
                   if (typeof measureHero === 'function') measureHero();
               }, 420);
               // Hedef kategori varsa ilgili akordiyonu ac, digerlerini kapat, ustune kaydir
               if (categoryId) {
                   setTimeout(function(){ activateCategory(categoryId); }, 200);
               }
           }

           function activateCategory(categoryId) {
               var hsec = document.getElementById('hizmetsecimbolumu');
               if (!hsec) return;
               var targetBtn = hsec.querySelector('.accordion[data-kategori-id="' + categoryId + '"]');
               if (!targetBtn) return;
               // Tum akordiyonlari kapat
               hsec.querySelectorAll('.accordion').forEach(function(b){
                   b.classList.remove('active');
                   var panel = b.nextElementSibling;
                   if (panel && panel.classList.contains('panel_accordion')) {
                       panel.style.display = 'none';
                   }
               });
               // Hedefi ac
               targetBtn.classList.add('active');
               var targetPanel = targetBtn.nextElementSibling;
               if (targetPanel && targetPanel.classList.contains('panel_accordion')) {
                   targetPanel.style.display = 'block';
               }
               // Panel icinde hedefi goster
               setTimeout(function(){
                   var panelEl = document.querySelector('.slp-drawer__panel');
                   if (panelEl && targetBtn.offsetParent !== null) {
                       panelEl.scrollTo({
                           top: targetBtn.offsetTop - 80,
                           behavior: 'smooth'
                       });
                   }
               }, 300);
           }

           function closeDrawer(popHash) {
               if (!drawer.classList.contains('is-open')) return;
               drawer.classList.remove('is-open');
               drawer.setAttribute('aria-hidden', 'true');
               body.classList.remove('slp-drawer-open');
               if (popHash !== false && window.location.hash === HASH) {
                   history.replaceState(null, '', window.location.pathname + window.location.search);
               }
           }

           // Open triggers: any [data-slp-open]; [data-slp-category] hint'i varsa
           // drawer aciliktan sonra o kategori akordiyonu aciktir.
           document.addEventListener('click', function(e){
               var openEl = e.target.closest ? e.target.closest('[data-slp-open]') : null;
               if (openEl) {
                   e.preventDefault();
                   var catId = openEl.getAttribute('data-slp-category');
                   if (drawer.classList.contains('is-open') && catId) {
                       // Drawer zaten aciksa sadece kategori degistir
                       activateCategory(catId);
                   } else {
                       openDrawer(true, catId);
                   }
                   return;
               }
               var closeEl = e.target.closest ? e.target.closest('[data-slp-close]') : null;
               if (closeEl) {
                   e.preventDefault();
                   closeDrawer(true);
               }
           });

           // ESC kapatir
           document.addEventListener('keydown', function(e){
               if (e.key === 'Escape' && drawer.classList.contains('is-open')) {
                   closeDrawer(true);
               }
           });

           // Hash degisince (geri tusu vb.) senkronla
           window.addEventListener('hashchange', function(){
               if (window.location.hash === HASH) openDrawer(false);
               else closeDrawer(false);
           });
           window.addEventListener('popstate', function(){
               if (window.location.hash === HASH) openDrawer(false);
               else closeDrawer(false);
           });

           // Ilk yukleme: URL zaten #randevu-al ise otomatik ac
           if (window.location.hash === HASH) {
               setTimeout(function(){ openDrawer(false); }, 60);
           }
       }
   })();

(function(){
    function gotoRandevuAl(){
        if (window.location.hash !== '#randevu-al') return;
        var el = document.getElementById('randevu-al');
        if (!el) return;
        setTimeout(function(){
            try { el.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
            catch(e){ window.scrollTo(0, el.offsetTop - 80); }
        }, 220);
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', gotoRandevuAl);
    } else {
        gotoRandevuAl();
    }
    window.addEventListener('hashchange', gotoRandevuAl);
})();
