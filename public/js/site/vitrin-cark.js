// Vitrin hediye carki — eski salondetaylari.blade.php satir ici betiginin karsiligi (SVG cizim, donus, ses, kutlama ve
// sonuc ekranlari ayni). Kazanan ve odul yazimi sunucuda: giris yapmis musteri /musteri-uygulama/cark*, misafir
// /vitrin/{id}/cark/* (cevir → kod → dogrula). Dilim yazilari (etiket, deger_metni, tam_etiket) sunucudan gelir.
import { api, ApiHatasi, salonId, girisliMi, tokenYaz, esc } from './musteri-api.js';

const pop = document.getElementById('carkPopup');
const veri = document.getElementById('carkVeri');

if (pop && veri) {
    const DILIMLER = JSON.parse(veri.textContent || '[]');
    const SALON_ID = salonId();
    const MARKER = 'rc_cark_sonraki_' + SALON_ID; // misafirin bir sonraki hak tarihi (Y-m-d; eskide oturum isareti)
    const SEEN = 'rdvCarkPopupSeen';
    const DELAY = 1600;

    const svgEl = (n) => document.createElementNS('http://www.w3.org/2000/svg', n);
    const wheel = document.getElementById('carkPopupSvg');
    const toast = document.getElementById('carkPopupToast');
    const content = document.getElementById('carkPopupContent');
    const CX = 150, CY = 150, R = 130;
    let spinning = false, currentRot = 0, taslak = null, formAcilis = 0;

    const depo = (islem) => { try { return islem(); } catch (e) { return null; } };

    function showToast(msg) {
        toast.textContent = msg;
        toast.style.display = 'block';
        setTimeout(() => { toast.style.display = 'none'; }, 3500);
    }
    function wrapText(t, m) {
        const w = (t || '').split(/\s+/), L = []; let c = '';
        w.forEach((x) => { if ((c + ' ' + x).trim().length <= m) c = (c + ' ' + x).trim(); else { if (c) L.push(c); c = x; } });
        if (c) L.push(c); return L.length ? L : [''];
    }
    function renderWheel() {
        wheel.innerHTML = '';
        const n = DILIMLER.length, ang = 360 / n;
        DILIMLER.forEach((sl, i) => {
            const sa = i * ang, ea = (i + 1) * ang;
            const sr = (sa - 90) * Math.PI / 180, er = (ea - 90) * Math.PI / 180;
            const x1 = CX + R * Math.cos(sr), y1 = CY + R * Math.sin(sr);
            const x2 = CX + R * Math.cos(er), y2 = CY + R * Math.sin(er);
            const lg = ang > 180 ? 1 : 0;
            const path = svgEl('path');
            path.setAttribute('d', `M ${CX} ${CY} L ${x1} ${y1} A ${R} ${R} 0 ${lg} 1 ${x2} ${y2} Z`);
            path.setAttribute('fill', sl.renk);
            path.setAttribute('stroke', 'rgba(255,255,255,.7)');
            path.setAttribute('stroke-width', '2');
            wheel.appendChild(path);

            const tAng = sa + ang / 2, tRad = (tAng - 90) * Math.PI / 180;
            const textRot = tAng <= 180 ? tAng - 90 : tAng - 270;
            if (sl.deger_metni) {
                const numFs = n <= 8 ? 17 : 14, catFs = n <= 8 ? 11 : 9;
                const numDist = R - (n <= 8 ? 16 : 13);
                const nx = CX + numDist * Math.cos(tRad), ny = CY + numDist * Math.sin(tRad);
                const ng = svgEl('g'); ng.setAttribute('transform', `rotate(${tAng}, ${nx}, ${ny})`);
                const nt = svgEl('text');
                nt.setAttribute('x', nx); nt.setAttribute('y', ny);
                nt.setAttribute('text-anchor', 'middle'); nt.setAttribute('dominant-baseline', 'middle');
                nt.setAttribute('font-size', numFs); nt.setAttribute('font-weight', '900');
                nt.setAttribute('fill', 'white');
                nt.setAttribute('paint-order', 'stroke');
                nt.setAttribute('stroke', 'rgba(0,0,0,.75)'); nt.setAttribute('stroke-width', '3.5');
                nt.setAttribute('stroke-linejoin', 'round');
                nt.textContent = sl.deger_metni;
                ng.appendChild(nt); wheel.appendChild(ng);

                const innerLabel = sl.etiket;
                const catDist = n <= 8 ? 68 : 60;
                const cx2 = CX + catDist * Math.cos(tRad), cy2 = CY + catDist * Math.sin(tRad);
                const catMaxCh = Math.max(4, Math.floor(55 / (catFs * 0.62)));
                let lines = wrapText(innerLabel, catMaxCh);
                if (lines.length > 2) lines = [innerLabel.slice(0, catMaxCh - 1) + '…'];
                const catLH = catFs + 2;
                const catSY = cy2 - ((lines.length - 1) * catLH / 2);
                const catG = svgEl('g'); catG.setAttribute('transform', `rotate(${textRot}, ${cx2}, ${cy2})`);
                lines.forEach((ln, li) => {
                    const t = svgEl('text');
                    t.setAttribute('x', cx2); t.setAttribute('y', catSY + li * catLH);
                    t.setAttribute('text-anchor', 'middle'); t.setAttribute('dominant-baseline', 'middle');
                    t.setAttribute('font-size', catFs); t.setAttribute('font-weight', '700');
                    t.setAttribute('fill', 'rgba(255,255,255,.92)');
                    t.setAttribute('paint-order', 'stroke');
                    t.setAttribute('stroke', 'rgba(0,0,0,.5)'); t.setAttribute('stroke-width', '2');
                    t.setAttribute('stroke-linejoin', 'round');
                    t.textContent = ln; catG.appendChild(t);
                });
                wheel.appendChild(catG);
            } else {
                const dist = n <= 8 ? 76 : 68, fs = n <= 8 ? 12 : 10;
                const maxCh = Math.max(6, Math.floor(80 / (fs * 0.60))), lh = fs + 3;
                const label = sl.etiket;
                let lines = wrapText(label, maxCh);
                if (lines.length > 2) lines = [label.slice(0, maxCh - 1) + '…'];
                const tx = CX + dist * Math.cos(tRad), ty = CY + dist * Math.sin(tRad);
                const sy = ty - ((lines.length - 1) * lh / 2);
                const g = svgEl('g'); g.setAttribute('transform', `rotate(${textRot}, ${tx}, ${ty})`);
                lines.forEach((ln, li) => {
                    const t = svgEl('text');
                    t.setAttribute('x', tx); t.setAttribute('y', sy + li * lh);
                    t.setAttribute('text-anchor', 'middle'); t.setAttribute('dominant-baseline', 'middle');
                    t.setAttribute('font-size', fs); t.setAttribute('font-weight', '700');
                    t.setAttribute('fill', 'white');
                    t.setAttribute('paint-order', 'stroke');
                    t.setAttribute('stroke', 'rgba(0,0,0,.55)'); t.setAttribute('stroke-width', '2.5');
                    t.setAttribute('stroke-linejoin', 'round');
                    t.textContent = ln; g.appendChild(t);
                });
                wheel.appendChild(g);
            }
        });
    }
    renderWheel();

    /** Sonuc dilimi: cizim yazilari DILIMLER'den (sunucunun verdigi sira), tip ve deger cevaptan. */
    const dilimBul = (data) => ({ ...(DILIMLER[data.dilimIndex] || {}), ...data.dilim });
    const tamEtiket = (d) => esc(d.tam_etiket || d.baslik || d.ismi || '');
    const odulluMu = (d) => Boolean(d.deger_metni);

    // ── Cevirme durumu: uye sunucudan, misafir isaretten ──
    function cevrildiGoster(mesaj) {
        const kutu = document.getElementById('carkPopupCevrildi');
        const btn = document.getElementById('carkPopupSpinBtn');
        if (!kutu || !btn) return;
        if (mesaj) kutu.textContent = '✓ ' + mesaj;
        kutu.hidden = false;
        btn.hidden = true;
    }
    async function durumYukle() {
        if (girisliMi()) {
            const d = await api('/musteri-uygulama/cark', { sorgu: { salon_id: SALON_ID } }).catch(() => null);
            if (d && d.aktif && d.bugunCevirdi) {
                cevrildiGoster(d.cevirmeAraligiGun > 1 && d.sonrakiHakTarihi
                    ? 'Çarkı çevirdiniz. Bir sonraki çevirme: ' + d.sonrakiHakTarihi + '.'
                    : 'Bugün çarkı çevirdiniz. Yarın tekrar deneyebilirsiniz.');
            }
            if (d) return;
        }
        const sonraki = depo(() => localStorage.getItem(MARKER));
        const bugun = new Date().toLocaleDateString('sv-SE'); // Y-m-d
        if (sonraki && bugun < sonraki) cevrildiGoster(null);
    }

    // Sonuc ekrani (kayit formu / kupon kodu)
    function showResult(d, kod, kayitGerekli) {
        let html = '';
        if (kayitGerekli) {
            // Misafir + hediye var → kayit formu
            html = `
               <span class="cark-popup__eyebrow">🎁 Hediyeniz Hazır!</span>
               <h2 class="cark-popup__title" style="font-size:24px;">Hediyeniz: <em>${tamEtiket(d)}</em></h2>
               <p class="cark-popup__sub" style="margin-bottom:14px;">Kodunu almak için 10 saniyelik kayıt — telefonuna kod gönderilecek.</p>
               <div style="display:flex; gap:8px; margin-bottom:10px;">
                  <input type="text" id="ky-ad" placeholder="Ad" autocomplete="given-name" style="flex:1; padding:11px 12px; border:2px solid rgba(255,255,255,.4); border-radius:10px; background:rgba(255,255,255,.95); font-size:14px;">
                  <input type="text" id="ky-soyad" placeholder="Soyad" autocomplete="family-name" style="flex:1; padding:11px 12px; border:2px solid rgba(255,255,255,.4); border-radius:10px; background:rgba(255,255,255,.95); font-size:14px;">
               </div>
               <input type="tel" id="ky-tel" placeholder="5XX XXX XX XX" maxlength="16" autocomplete="tel" style="width:100%; padding:11px 12px; border:2px solid rgba(255,255,255,.4); border-radius:10px; margin-bottom:10px; background:rgba(255,255,255,.95); font-size:15px; letter-spacing:1px;">
               <input type="text" id="ky-hp" tabindex="-1" autocomplete="off" aria-hidden="true" style="position:absolute;left:-9999px;top:-9999px;opacity:0;height:0;width:0;">
               <label style="display:flex; gap:8px; align-items:flex-start; margin-bottom:10px; font-size:12px; color:rgba(255,255,255,.9); text-align:left; cursor:pointer;">
                  <input type="checkbox" id="ky-kvkk" style="margin-top:2px;">
                  <span><a href="${esc(pop.dataset.gizlilik)}" target="_blank" rel="noopener" style="color:#fff; text-decoration:underline;">Kullanım ve gizlilik koşulları</a>nı okudum, kabul ediyorum.</span>
               </label>
               <button type="button" class="cark-popup__cta" id="btn-smskod" data-cark-kod>📨 Kod Gönder</button>
            `;
            formAcilis = Date.now();
        } else if (kod) {
            // Uye + kupon
            html = `
               <span class="cark-popup__eyebrow">🎁 Hediyeniz Hazır!</span>
               <h2 class="cark-popup__title" style="font-size:24px;">Hediyeniz: <em>${tamEtiket(d)}</em></h2>
               <div style="margin:14px 0; padding:14px 22px; background:#fef3c7; color:#92400e; border-radius:12px; font-family:monospace; font-size:24px; font-weight:800; letter-spacing:4px; border:2px dashed #f59e0b; display:inline-block;">${esc(kod)}</div>
               <p class="cark-popup__sub" style="font-size:13px; margin-bottom:12px;">Bu kodu 30 gün içinde salonda kullanabilirsiniz.</p>
               <button type="button" class="cark-popup__cta" data-cark-close>Tamam</button>
            `;
        } else {
            // Tekrar Dene / Bos / uye + puan
            html = `
               <span class="cark-popup__eyebrow">🎁 Hediyeniz</span>
               <h2 class="cark-popup__title" style="font-size:24px;"><em>${tamEtiket(d)}</em></h2>
               <p class="cark-popup__sub">${d.tip === 'tekrar_dene' ? 'Yarın tekrar yararlanabilirsiniz.' : (d.tip === 'puan' ? 'Puan hesabınıza eklendi.' : 'Bir dahaki sefere yeni bir hediye sizi bekliyor.')}</p>
               <button type="button" class="cark-popup__cta" data-cark-close>Tamam</button>
            `;
        }
        content.innerHTML = html;
    }

    // Kod gonderme adimi (misafir kayit)
    async function carkSmsKod() {
        const ad = document.getElementById('ky-ad').value.trim();
        const soyad = document.getElementById('ky-soyad').value.trim();
        const tel = document.getElementById('ky-tel').value.trim();
        if (!ad || !soyad) { showToast('Ad ve soyad zorunlu'); return; }
        if (!tel) { showToast('Geçerli bir telefon numarası girin.'); return; }
        if (!document.getElementById('ky-kvkk').checked) { showToast('Devam etmek için kullanım ve gizlilik koşullarını onaylayın.'); return; }

        const btn = document.getElementById('btn-smskod');
        btn.disabled = true;
        btn.innerHTML = '⏳ Gönderiliyor...';
        let sonuc;
        try {
            sonuc = await api(`/vitrin/${SALON_ID}/cark/kod`, { yontem: 'POST', govde: {
                taslak, ad, soyad, cep_telefon: tel, kvkk: true,
                _hp: document.getElementById('ky-hp').value, form_sure_sn: Math.floor((Date.now() - formAcilis) / 1000),
            } });
        } catch (e) {
            showToast(e instanceof ApiHatasi ? e.message : 'Bağlantı hatası');
            btn.disabled = false; btn.innerHTML = '📨 Kod Gönder';
            return;
        }
        const gorunen = sonuc.telefon.startsWith('+') ? sonuc.telefon : '0' + sonuc.telefon;
        // Kod giris adimi
        content.innerHTML = `
           <span class="cark-popup__eyebrow">📱 Kod Bekleniyor</span>
           <h2 class="cark-popup__title" style="font-size:22px;">Kodunu Gir</h2>
           <p class="cark-popup__sub">${esc(gorunen)} numarasına gelen 4 haneli kodu girin.</p>
           <input type="text" id="kd-kod" maxlength="4" inputmode="numeric" autocomplete="one-time-code" placeholder="• • • •" style="width:100%; padding:14px; border:2px solid rgba(255,255,255,.4); border-radius:10px; background:rgba(255,255,255,.95); font-size:28px; font-weight:800; letter-spacing:14px; text-align:center; font-family:monospace; margin-bottom:10px;">
           <button type="button" class="cark-popup__cta" id="btn-dogrula" data-cark-dogrula>✓ Doğrula ve Kodu Al</button>
        `;
        setTimeout(() => document.getElementById('kd-kod').focus(), 100);
    }

    async function carkSmsDogrula() {
        const kod = (document.getElementById('kd-kod').value || '').trim();
        if (kod.length !== 4) { showToast('4 haneli kodu girin'); return; }
        const btn = document.getElementById('btn-dogrula');
        btn.disabled = true; btn.innerHTML = '⏳ Doğrulanıyor...';
        let data;
        try {
            data = await api(`/vitrin/${SALON_ID}/cark/dogrula`, { yontem: 'POST', govde: { taslak, kod } });
        } catch (e) {
            showToast(e instanceof ApiHatasi ? e.message : 'Bağlantı hatası');
            btn.disabled = false; btn.innerHTML = '✓ Doğrula ve Kodu Al';
            return;
        }
        taslak = null;
        if (data.oturum && data.oturum.token) tokenYaz(data.oturum.token);
        const baslik = data.yeniUye ? '🎉 Hesabın Hazır' : '🎉 Hediyen Hesabında';
        if (data.odulKodu) {
            content.innerHTML = `
               <span class="cark-popup__eyebrow">${baslik}</span>
               <h2 class="cark-popup__title" style="font-size:22px;">Kupon Kodunuz</h2>
               <div style="margin:14px 0; padding:14px 22px; background:#fef3c7; color:#92400e; border-radius:12px; font-family:monospace; font-size:26px; font-weight:800; letter-spacing:5px; border:2px dashed #f59e0b; display:inline-block;">${esc(data.odulKodu)}</div>
               <p class="cark-popup__sub" style="font-size:13px;">Bu kodu 30 gün içinde salonda kullanabilirsiniz.</p>
               <button type="button" class="cark-popup__cta" data-cark-close>Tamam</button>`;
        } else {
            content.innerHTML = `
               <span class="cark-popup__eyebrow">${baslik}</span>
               <h2 class="cark-popup__title" style="font-size:22px;">Puanın eklendi!</h2>
               <p class="cark-popup__sub">Profilinizden puan hediyelerinize göz atabilirsiniz.</p>
               <button type="button" class="cark-popup__cta" data-cark-close>Tamam</button>`;
        }
    }

    /* ===== Web Audio: tik sesi + alkis + fanfar ===== */
    let _ctx = null;
    function ac() { if (!_ctx) _ctx = new (window.AudioContext || window.webkitAudioContext)(); return _ctx; }
    function playTick(vol) {
        try {
            const c = ac(); if (c.state === 'suspended') c.resume();
            const now = c.currentTime;
            const len = Math.floor(c.sampleRate * 0.055);
            const buf = c.createBuffer(1, len, c.sampleRate);
            const d = buf.getChannelData(0);
            for (let i = 0; i < len; i++) {
                const t = i / len;
                d[i] = (Math.random() * 2 - 1) * Math.pow(1 - t, 4) * (vol || 0.45);
            }
            const src = c.createBufferSource(); src.buffer = buf;
            const g = c.createGain(); g.gain.setValueAtTime(1, now);
            src.connect(g); g.connect(c.destination); src.start(now);
        } catch (e) { /* ses yok */ }
    }
    function startTickLoop() {
        const n = DILIMLER.length, sliceAng = 360 / n;
        let last = -1;
        function frame() {
            if (!spinning) return;
            let a = 0;
            try {
                const mat = new DOMMatrix(window.getComputedStyle(wheel).transform);
                a = (Math.atan2(mat.b, mat.a) * 180 / Math.PI + 360) % 360;
            } catch (e) { a = currentRot % 360; }
            const idx = Math.floor(((360 - a) % 360) / sliceAng) % n;
            if (idx !== last) {
                last = idx; playTick(0.45);
            }
            requestAnimationFrame(frame);
        }
        requestAnimationFrame(frame);
    }
    function playCheer() {
        try {
            const c = ac(); if (c.state === 'suspended') c.resume();
            const now = c.currentTime;
            const dur = 4, sr = c.sampleRate;
            const buf = c.createBuffer(2, sr * dur, sr);
            for (let ch = 0; ch < 2; ch++) {
                const d = buf.getChannelData(ch);
                for (let i = 0; i < d.length; i++) {
                    const t = i / sr;
                    const env = t < .5 ? t / .5 : t < 3 ? 1 : Math.pow(1 - (t - 3) / 1, 1.5);
                    const mod = .5 + .5 * Math.abs(Math.sin(t * 7.5 + ch * .4 + Math.random() * .05));
                    d[i] = (Math.random() * 2 - 1) * mod * env * .3;
                }
            }
            const src = c.createBufferSource(); src.buffer = buf;
            const bp = c.createBiquadFilter(); bp.type = 'bandpass'; bp.frequency.value = 1600; bp.Q.value = .7;
            const gv = c.createGain(); gv.gain.value = 2.2;
            src.connect(bp); bp.connect(gv); gv.connect(c.destination);
            src.start(now);
            [[0, 523], [280, 659], [520, 784], [720, 880], [880, 1047]].forEach(([ms, f]) => {
                const o = c.createOscillator(), g = c.createGain();
                o.type = 'sine'; o.frequency.value = f;
                const t0 = now + ms / 1000;
                g.gain.setValueAtTime(0, t0);
                g.gain.linearRampToValueAtTime(.2, t0 + .06);
                g.gain.exponentialRampToValueAtTime(.001, t0 + .5);
                o.connect(g); g.connect(c.destination);
                o.start(t0); o.stop(t0 + .6);
            });
        } catch (e) { /* ses yok */ }
    }
    // Pencere acilisinda kisa cark tikirtisi + "ding" (eski otomatik acilis sesi).
    function playWheelSound() {
        try {
            const Ctx = window.AudioContext || window.webkitAudioContext;
            if (!Ctx) return;
            const ctx = new Ctx();
            if (ctx.state === 'suspended' && ctx.resume) ctx.resume().catch(() => {});
            const ticks = 14, spacing = 95;
            for (let i = 0; i < ticks; i++) {
                setTimeout(() => {
                    const osc = ctx.createOscillator(), gain = ctx.createGain();
                    osc.type = 'square';
                    osc.frequency.value = 1100 + Math.random() * 350;
                    gain.gain.setValueAtTime(0.08, ctx.currentTime);
                    gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.04);
                    osc.connect(gain).connect(ctx.destination);
                    osc.start();
                    osc.stop(ctx.currentTime + 0.05);
                }, i * spacing);
            }
            setTimeout(() => {
                const osc = ctx.createOscillator(), gain = ctx.createGain();
                osc.type = 'sine';
                osc.frequency.setValueAtTime(880, ctx.currentTime);
                osc.frequency.exponentialRampToValueAtTime(1320, ctx.currentTime + 0.18);
                gain.gain.setValueAtTime(0.18, ctx.currentTime);
                gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.6);
                osc.connect(gain).connect(ctx.destination);
                osc.start();
                osc.stop(ctx.currentTime + 0.7);
            }, ticks * spacing + 60);
        } catch (e) { /* ses yok */ }
    }

    /* ===== Kutlama: konfeti + balon + havai fisek ===== */
    let cCan = null, cCtx = null, cRAF = null, cParts = [];
    function startCeleb() {
        if (!cCan) {
            cCan = document.createElement('canvas');
            cCan.style.cssText = 'position:fixed; top:0; left:0; width:100vw; height:100vh; z-index:100002; pointer-events:none;';
            document.body.appendChild(cCan);
            cCtx = cCan.getContext('2d');
        }
        cCan.width = innerWidth; cCan.height = innerHeight; cCan.style.display = 'block';
        cParts = [];
        const cols = ['#FF6B6B', '#FFE66D', '#4ECDC4', '#A29BFE', '#FD79A8', '#6C5CE7', '#00B894', '#FDCB6E', '#E17055', '#74B9FF'];
        for (let i = 0; i < 120; i++) cParts.push({ type: 'c', x: Math.random() * cCan.width, y: -20 - Math.random() * 300, vx: (Math.random() - .5) * 3, vy: 3 + Math.random() * 5, rot: Math.random() * 360, rs: (Math.random() - .5) * 9, w: 6 + Math.random() * 8, h: 3 + Math.random() * 5, color: cols[Math.floor(Math.random() * cols.length)], a: 1 });
        for (let i = 0; i < 14; i++) cParts.push({ type: 'b', x: 40 + Math.random() * (cCan.width - 80), y: cCan.height + 30 + Math.random() * 120, vx: (Math.random() - .5) * 1.4, vy: -(2 + Math.random() * 2.8), sw: Math.random() * Math.PI * 2, ss: .02 + Math.random() * .02, r: 18 + Math.random() * 14, color: cols[Math.floor(Math.random() * cols.length)], a: 1 });
        for (let b = 0; b < 5; b++) {
            const bx = 80 + Math.random() * (cCan.width - 160), by = 60 + Math.random() * (cCan.height * .45), bc = cols[Math.floor(Math.random() * cols.length)];
            setTimeout(() => {
                for (let p = 0; p < 32; p++) {
                    const ang = (p / 32) * Math.PI * 2, sp = 3 + Math.random() * 6;
                    cParts.push({ type: 's', x: bx, y: by, vx: Math.cos(ang) * sp, vy: Math.sin(ang) * sp, r: 3 + Math.random() * 3, color: bc, a: 1, life: 1 });
                }
            }, b * 500);
        }
        if (cRAF) cancelAnimationFrame(cRAF);
        animCeleb();
        playCheer();
    }
    function animCeleb() {
        if (!cCtx) return;
        const W = cCan.width, H = cCan.height;
        cCtx.clearRect(0, 0, W, H);
        cParts.forEach((p) => {
            if (p.a <= .01) return;
            cCtx.save(); cCtx.globalAlpha = p.a;
            if (p.type === 'c') {
                p.x += p.vx; p.y += p.vy; p.vy += .09; p.rot += p.rs;
                if (p.y > H + 20) { p.a = 0; cCtx.restore(); return; }
                cCtx.translate(p.x, p.y); cCtx.rotate(p.rot * Math.PI / 180);
                cCtx.fillStyle = p.color; cCtx.fillRect(-p.w / 2, -p.h / 2, p.w, p.h);
            } else if (p.type === 'b') {
                p.sw += p.ss; p.x += p.vx + Math.sin(p.sw) * .6; p.y += p.vy;
                if (p.y < -70) { p.a = 0; cCtx.restore(); return; }
                cCtx.translate(p.x, p.y);
                cCtx.beginPath(); cCtx.arc(0, 0, p.r, 0, Math.PI * 2); cCtx.fillStyle = p.color; cCtx.fill();
                cCtx.beginPath(); cCtx.arc(-p.r * .3, -p.r * .3, p.r * .27, 0, Math.PI * 2); cCtx.fillStyle = 'rgba(255,255,255,.38)'; cCtx.fill();
                cCtx.beginPath(); cCtx.moveTo(0, p.r); cCtx.quadraticCurveTo(5, p.r + 14, -3, p.r + 28);
                cCtx.strokeStyle = 'rgba(255,255,255,.55)'; cCtx.lineWidth = 1.2; cCtx.stroke();
            } else if (p.type === 's') {
                p.x += p.vx; p.y += p.vy; p.vy += .18; p.life -= .022; p.a = Math.max(0, p.life);
                cCtx.beginPath(); cCtx.arc(p.x, p.y, Math.max(.5, p.r * p.life), 0, Math.PI * 2);
                cCtx.fillStyle = p.color; cCtx.fill();
            }
            cCtx.restore();
        });
        cRAF = requestAnimationFrame(animCeleb);
    }
    function stopCeleb() {
        if (cRAF) { cancelAnimationFrame(cRAF); cRAF = null; }
        if (cCan) cCan.style.display = 'none';
        cParts = [];
    }

    // SIMDI CEVIR — uye kendi ucundan (odul aninda hesabina), misafir vitrin ucundan (odul taslak, kayitla verilir).
    const SPIN_HTML = '<i class="fa fa-bolt"></i> <span>ŞİMDİ ÇEVİR</span>';
    async function carkSpin() {
        if (spinning) return;
        spinning = true;
        try { ac().resume(); } catch (e) { /* ses yok */ }
        const btn = document.getElementById('carkPopupSpinBtn');
        if (btn) { btn.disabled = true; btn.innerHTML = '⏳ Çevriliyor...'; }
        let data;
        try {
            data = girisliMi()
                ? await api('/musteri-uygulama/cark/cevir', { yontem: 'POST', govde: { salon_id: SALON_ID } })
                : await api(`/vitrin/${SALON_ID}/cark/cevir`, { yontem: 'POST' });
        } catch (e) {
            showToast(e instanceof ApiHatasi ? e.message : 'Bağlantı hatası');
            spinning = false;
            if (btn) { btn.disabled = false; btn.innerHTML = SPIN_HTML; }
            return;
        }
        taslak = data.taslak || null;
        if (data.sonrakiHakTarihi) depo(() => localStorage.setItem(MARKER, data.sonrakiHakTarihi));
        const dilim = dilimBul(data);

        const n = DILIMLER.length, ang = 360 / n;
        const idx = data.dilimIndex;
        const jitter = (Math.random() - .5) * ang * 0.6;
        const stopAt = (idx + 0.5) * ang + jitter;
        const offset = ((360 - stopAt) % 360 + 360) % 360;
        const nSpins = (12 + Math.floor(Math.random() * 5)) * 360;
        const curMod = ((currentRot % 360) + 360) % 360;
        let diff = offset - curMod;
        if (diff < 0) diff += 360;
        currentRot += nSpins + diff;
        wheel.style.transform = `rotate(${currentRot}deg)`;

        // Tik sesi dongusu — dilim gecislerinde calar
        startTickLoop();

        setTimeout(() => {
            spinning = false; // tik dongusunu durdur
            showResult(dilim, data.odulKodu, Boolean(data.kayitGerekli));
            // Odul varsa kutlama — bos / tekrar dene'de gosterme
            if (odulluMu(dilim)) startCeleb();
        }, 9200);
    }

    // ── Pencere ac / kapat ──
    function open() {
        pop.classList.add('is-open');
        pop.setAttribute('aria-hidden', 'false');
        document.body.classList.add('cark-popup-open');
        depo(() => sessionStorage.setItem(SEEN, '1'));
        playWheelSound();
    }
    function close() {
        pop.classList.remove('is-open');
        pop.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('cark-popup-open');
        stopCeleb();
    }
    window.openCarkModal = open;
    window.closeCarkModal = close;

    document.addEventListener('click', (e) => {
        const hedef = e.target.closest && e.target.closest('[data-cark-ac], [data-cark-close], [data-cark-cevir], [data-cark-kod], [data-cark-dogrula]');
        if (!hedef) return;
        if (hedef.matches('[data-cark-ac]')) { e.preventDefault(); open(); }
        else if (hedef.matches('[data-cark-close]')) { e.preventDefault(); close(); }
        else if (hedef.matches('[data-cark-cevir]')) carkSpin();
        else if (hedef.matches('[data-cark-kod]')) carkSmsKod();
        else if (hedef.matches('[data-cark-dogrula]')) carkSmsDogrula();
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && pop.classList.contains('is-open')) close();
    });

    durumYukle();

    // Otomatik acilis: oturumda bir kez; ?carkac=1 (push bildirimi derin linki) her zaman acar.
    const deepLink = /[?&]carkac=1\b/.test(window.location.search);
    const alreadySeen = depo(() => sessionStorage.getItem(SEEN)) === '1';
    if (deepLink || !alreadySeen) setTimeout(open, deepLink ? 200 : DELAY);
}
