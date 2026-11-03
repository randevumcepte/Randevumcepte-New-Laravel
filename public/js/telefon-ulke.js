/**
 * Telefon alanı: ülke kodu seçici + ülkeye göre biçim — TUM v2 ekranlari icin otomatik
 * (bagimliliksiz, build'siz; arama-select.js ile ayni desen).
 *
 * Sayfadaki ve SONRADAN eklenen her <input type="tel">'i gelistirir. Orijinal input
 * GIZLI (type=hidden) kalir ve gercek deger kaynagidir — id / name / data-alan korunur:
 *   - mevcut kod degismeden calisir (el('#x').value okur, FormData name'i toplar),
 *   - kodun yaptigi input.value = ... atamalari gorunen alana otomatik yansir,
 *   - degisince orijinal input'a 'input' + 'change' olayi gonderilir.
 *
 * Ulke listesi + bicimler SUNUCUDAN: GET /api/v2/telefon/ulkeler
 * (App\Domain\Telefon\TelefonUlkeleri — Flutter ile TEK kaynak; burada liste tutulmaz).
 * Ulke secimi duz bir <select>'tir; aranabilir listeyi arama-select.js cizer.
 *
 * Saklanan (sunucuya giden) deger — TelefonNumarasi::kanonik ile ayni sozlesme:
 *   - Turkiye (+90): duz 10 hane, +90 / 0 atilmis → "5321234567"
 *   - Yabanci      : "+KOD" + ulusal numara (trunk 0 atilmis) → "+491761234567"
 *
 * Maskeli deger ("5321 *** ** 67", telefon_gor yetkisi yok) oldugu gibi gosterilir ve
 * degistirilmezse oldugu gibi gider (sunucu mevcut numarayi korur).
 * Haric tutmak: <input type="tel" data-tel="yok">.
 */
import { api, esc } from '/js/lib/v2-ortak.js?v=1';

const DEGER = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value');

const r = await api('GET', '/telefon/ulkeler');
const veri = r.ok && r.govde?.basarili ? r.govde.veri : null;
const ULKELER = veri?.ulkeler || [];
const ISO = Object.fromEntries(ULKELER.map((u) => [u.iso, u]));
const VARSAYILAN = ISO[veri?.varsayilan];
const TR = ISO.TR;

const bayrak = (iso) => (/^[A-Z]{2}\d?$/.test(iso)
  ? String.fromCodePoint(...[...iso.slice(0, 2)].map((c) => 0x1f1e6 + c.charCodeAt(0) - 65))
  : '🌐');
const rakam = (s) => String(s ?? '').replace(/\D/g, '');
const trMi = (u) => u.kod === TR.kod; // TR + KKTC: 10 hane, koddan bagimsiz saklanir

/** Uluslararasi rakamlar (491761234567) → en uzun eslesen kodun ulkesi + kalan (esitse tercih). */
function koddanCoz(d) {
  let enIyi = null;
  for (const u of ULKELER) {
    if (!d.startsWith(u.kod) || (enIyi && u.kod.length < enIyi.kod.length)) continue;
    if (!enIyi || u.kod.length > enIyi.kod.length || (u.tercih && !enIyi.tercih)) enIyi = u;
  }
  return enIyi ? { ulke: enIyi, numara: d.slice(enIyi.kod.length) } : null;
}

/** Kayitli degeri {ulke, numara (yalniz rakam) | maskeli} olarak coz. */
function coz(deger, varsayilan) {
  const s = String(deger ?? '').trim();
  if (!s || s === 'null') return { ulke: varsayilan, numara: '' };
  if (s.includes('*')) return { ulke: TR, maskeli: s };
  let d = rakam(s);
  const uluslararasi = s.startsWith('+') || d.startsWith('00');
  if (d.startsWith('00')) d = d.slice(2);
  // Oneksiz 12+ hane (TR'nin 90'li bicimi haric) eski "+"siz yabanci kayittir.
  if (uluslararasi || (d.length > 11 && !(d.length === 12 && d.startsWith(TR.kod)))) {
    const c = koddanCoz(d);
    if (c) return { ulke: c.ulke, numara: ulusal(c.numara, c.ulke) };
  }
  if (d.length === 12 && d.startsWith(TR.kod)) d = d.slice(2);
  return { ulke: TR, numara: ulusal(d, TR) };
}

/** Bastaki trunk 0'i at (sifir_kalir ulkeler haric), en_cok haneye kirp. */
function ulusal(d, u) {
  const n = u.sifir_kalir ? rakam(d) : rakam(d).replace(/^0+/, '');
  return n.slice(0, u.en_cok);
}

function bicimle(d, u) {
  let cikti = '';
  let i = 0;
  for (const c of u.maske) {
    if (i >= d.length) break;
    cikti += c === '#' ? d[i++] : c;
  }
  return cikti;
}

/** Sunucuya giden deger: TR → 10 hane, yabanci → +KOD + numara. */
const birlestir = (u, numara) => (!numara ? '' : trMi(u) ? numara : `+${u.kod}${numara}`);

const secenek = (u) => `<option value="${u.iso}">${bayrak(u.iso)} +${u.kod}  ${esc(u.ad)}</option>`;
const SECENEKLER = `<optgroup label="Sık kullanılan">${(veri?.sik_kullanilan || []).map((i) => ISO[i]).filter(Boolean).map(secenek).join('')}</optgroup>
  <optgroup label="Tüm ülkeler">${ULKELER.map(secenek).join('')}</optgroup>`;

// ─────────────────────────── geliştirme ───────────────────────────

function gelistir(inp) {
  if (inp._tel || inp.dataset.tel === 'yok') return;

  const kap = document.createElement('div');
  kap.className = 'tel-grup';
  kap.innerHTML = `<select class="tel-ulke" aria-label="Ülke kodu">${SECENEKLER}</select><input type="tel" class="tel-num" inputmode="tel" autocomplete="off">`;
  const sec = kap.querySelector('.tel-ulke');
  const num = kap.querySelector('.tel-num');
  if (inp.required) { num.required = true; inp.required = false; }
  num.disabled = inp.disabled;
  num.readOnly = inp.readOnly;
  sec.disabled = inp.disabled || inp.readOnly;
  inp.insertAdjacentElement('afterend', kap);
  inp.type = 'hidden';
  inp.removeAttribute('maxlength');
  inp.removeAttribute('pattern');

  const t = {
    ulke: VARSAYILAN,
    maskeli: null,
    yaziyor: false,
    /** Disaridan atanan / ilk deger → gorunen alan. */
    yukle(deger) {
      const c = coz(deger, t.ulke);
      t.ulke = c.ulke;
      t.maskeli = c.maskeli || null;
      etiket();
      num.value = t.maskeli || bicimle(c.numara, t.ulke);
      dogrula();
    },
  };
  inp._tel = t;

  function etiket() {
    sec.value = t.ulke.iso;
    sec.title = t.ulke.ad;
    num.placeholder = trMi(t.ulke) ? '5XX XXX XX XX' : t.ulke.maske.replace(/#/g, 'X');
  }

  function dogrula() {
    const d = t.maskeli ? '' : rakam(num.value);
    let hata = '';
    if (d && trMi(t.ulke) && d.length !== 10) hata = 'Telefon numarası 10 haneli olmalı (5XX XXX XX XX).';
    else if (d && d.length < 4) hata = 'Telefon numarası eksik.';
    num.setCustomValidity(hata);
    kap.classList.toggle('tel-hatali', !!hata && document.activeElement !== num);
  }

  /** Gorunen alan → gizli (asil) input + olaylar. */
  function yaz() {
    const yeni = t.maskeli || birlestir(t.ulke, ulusal(num.value, t.ulke));
    dogrula();
    if (DEGER.get.call(inp) === yeni) return;
    t.yaziyor = true;
    DEGER.set.call(inp, yeni);
    t.yaziyor = false;
    inp.dispatchEvent(new Event('input', { bubbles: true }));
    inp.dispatchEvent(new Event('change', { bubbles: true }));
  }

  sec.addEventListener('change', () => {
    const u = ISO[sec.value];
    if (!u || u === t.ulke) return;
    const d = t.maskeli ? '' : rakam(num.value);
    t.ulke = u;
    t.maskeli = null;
    etiket();
    num.value = bicimle(ulusal(d, u), u);
    yaz();
    num.focus();
  });

  num.addEventListener('focus', () => { kap.classList.remove('tel-hatali'); if (t.maskeli) num.select(); });
  num.addEventListener('input', () => {
    const ham = num.value;
    // Maskeli numaraya dokunulduysa artik kullanicinin yazdigi gecerli.
    if (t.maskeli && ham !== t.maskeli) t.maskeli = null;
    if (t.maskeli) return;
    // "+49…" / "0049…" yazildiysa kod cozulene kadar ham birak (blur/yapistirmada cozulur).
    if (/^\s*(\+|00)/.test(ham)) { yaz(); return; }
    let d = rakam(ham);
    if (trMi(t.ulke) && d.length === 12 && d.startsWith(TR.kod)) d = d.slice(2);
    // Imlec: onundeki (korunan) rakam sayisi ayni kalir.
    const yeniD = ulusal(d, t.ulke);
    const atilan = rakam(ham).length - yeniD.length - Math.max(0, d.length - t.ulke.en_cok);
    let hedef = Math.max(0, rakam(ham.slice(0, num.selectionStart ?? ham.length)).length - atilan);
    num.value = bicimle(yeniD, t.ulke);
    if (document.activeElement === num) {
      let p = 0;
      while (p < num.value.length && hedef > 0) { if (/\d/.test(num.value[p])) hedef--; p++; }
      num.setSelectionRange(p, p);
    }
    yaz();
  });
  const uluslararasiCoz = () => {
    const ham = num.value.trim();
    if (!/^(\+|00)/.test(ham)) return;
    const c = koddanCoz(rakam(ham).replace(/^00/, ''));
    if (!c) return;
    t.ulke = c.ulke;
    etiket();
    num.value = bicimle(ulusal(c.numara, c.ulke), c.ulke);
    yaz();
  };
  num.addEventListener('paste', () => setTimeout(uluslararasiCoz));
  num.addEventListener('blur', () => { uluslararasiCoz(); dogrula(); });

  t.yukle(DEGER.get.call(inp));
}

function tara(kok) {
  if (kok.matches?.('input[type="tel"]:not(.tel-num)')) gelistir(kok);
  kok.querySelectorAll?.('input[type="tel"]:not(.tel-num)').forEach(gelistir);
}

// Liste gelmezse (ag hatasi) alanlar duz tel input olarak kalir — sessizce bozulmaz.
if (TR && VARSAYILAN) {
  // Kodun yaptigi input.value = ... atamalari gorunen alana yansisin (prototip duzeyinde bir kez).
  if (!HTMLInputElement.prototype.__telSarili) {
    Object.defineProperty(HTMLInputElement.prototype, '__telSarili', { value: true });
    Object.defineProperty(HTMLInputElement.prototype, 'value', {
      configurable: true, enumerable: DEGER.enumerable,
      get() { return DEGER.get.call(this); },
      set(v) { DEGER.set.call(this, v); if (this._tel && !this._tel.yaziyor) this._tel.yukle(v); },
    });
  }
  tara(document.body);
  new MutationObserver((kayitlar) => {
    for (const k of kayitlar) k.addedNodes.forEach((n) => { if (n.nodeType === 1) tara(n); });
  }).observe(document.body, { childList: true, subtree: true });
}
