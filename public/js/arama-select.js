/**
 * Aranabilir select — TUM v2 ekranlari icin otomatik (bagimliliksiz, build'siz).
 *
 * Sayfadaki ve SONRADAN eklenen (modal, yeniden cizilen satir) her <select>'i
 * aranabilir bir acilir listeye cevirir. Orijinal <select> DOM'da GIZLI kalir ve
 * gercek deger kaynagidir:
 *   - mevcut kod degismeden calisir (sel.value okur, sel.onchange / addEventListener),
 *   - secimde orijinal select'e 'input' + 'change' olayi gonderilir,
 *   - kodun yaptigi sel.value = ... / selectedIndex atamalari ve innerHTML ile
 *     degisen secenekler otomatik yansir.
 *
 * Haric tutmak: <select data-arama="yok">. multiple select'lere ve flatpickr takviminin
 * kendi ay seciminin (.flatpickr-calendar icinde) dokunulmaz — aksi halde yil alani kayar.
 * Arama Turkce harf duyarsizdir (İ/ı/ş/ğ/ü/ö/ç). Klavye: ↑ ↓ Enter Esc.
 */

const DEGER = Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'value');
const INDEKS = Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'selectedIndex');

// Kodun yaptigi sel.value = ... / sel.selectedIndex = ... atamalari etikete yansisin.
// PROTOTIP duzeyinde bir kez sarilir (eleman basina defineProperty bazi ortamlarda —
// indeksli erisimli select Proxy'leri — yok sayilir). Orijinal davranis AYNEN calisir;
// yalnizca gelistirilmis select ise etiket tazelenir.
if (!HTMLSelectElement.prototype.__rsSarili) {
  Object.defineProperty(HTMLSelectElement.prototype, '__rsSarili', { value: true });
  Object.defineProperty(HTMLSelectElement.prototype, 'value', {
    configurable: true, enumerable: DEGER.enumerable,
    get() { return DEGER.get.call(this); },
    set(v) { DEGER.set.call(this, v); if (this._rsBtn) etiketYenile(this); },
  });
  Object.defineProperty(HTMLSelectElement.prototype, 'selectedIndex', {
    configurable: true, enumerable: INDEKS.enumerable,
    get() { return INDEKS.get.call(this); },
    set(v) { INDEKS.set.call(this, v); if (this._rsBtn) etiketYenile(this); },
  });
}

const normal = (s) => String(s ?? '')
  .toLocaleLowerCase('tr')
  .replace(/ı/g, 'i')
  .normalize('NFD').replace(/[̀-ͯ]/g, '');

let acik = null; // { sel, btn, panel, kapat }

function etiketYenile(sel) {
  const btn = sel._rsBtn;
  if (!btn) return;
  const o = sel.options[INDEKS.get.call(sel)];
  btn.querySelector('.rs-etiket').textContent = o ? o.textContent.trim() : '';
  btn.disabled = sel.disabled;
  btn.classList.toggle('rs-bos', !o || o.value === '');
}

function gelistir(sel) {
  if (sel._rsBtn || sel.multiple || sel.dataset.arama === 'yok' || sel.closest('.flatpickr-calendar')) return;

  const btn = document.createElement('button');
  btn.type = 'button';
  btn.className = ('rs-btn ' + sel.className).trim(); // genislik/olcu siniflari korunsun
  btn.innerHTML = '<span class="rs-etiket"></span><i class="bi bi-chevron-down rs-ok"></i>';
  if (sel.title) btn.title = sel.title;
  if (sel.id) btn.setAttribute('aria-controls', sel.id);
  btn.setAttribute('aria-haspopup', 'listbox');
  sel.classList.add('rs-gizli');
  sel.tabIndex = -1;
  sel.insertAdjacentElement('afterend', btn);
  sel._rsBtn = btn;

  new MutationObserver(() => etiketYenile(sel))
    .observe(sel, { childList: true, subtree: true, attributes: true, attributeFilter: ['disabled', 'selected'] });
  sel.addEventListener('change', () => etiketYenile(sel));

  btn.addEventListener('click', () => (acik?.sel === sel ? kapat() : ac(sel)));
  btn.addEventListener('keydown', (e) => {
    if (['ArrowDown', 'ArrowUp', 'Enter', ' '].includes(e.key)) { e.preventDefault(); ac(sel); }
  });
  etiketYenile(sel);
}

function ac(sel) {
  kapat();
  const btn = sel._rsBtn;
  const panel = document.createElement('div');
  panel.className = 'rs-panel';
  panel.setAttribute('role', 'listbox');
  panel.innerHTML = '<div class="rs-ara-kap"><i class="bi bi-search"></i><input class="rs-ara" placeholder="Ara…" autocomplete="off"></div><div class="rs-liste"></div>';
  document.body.appendChild(panel);

  const liste = panel.querySelector('.rs-liste');
  const ara = panel.querySelector('.rs-ara');
  let aktif = -1;
  let gorunen = [];

  const ciz = () => {
    const q = normal(ara.value.trim());
    gorunen = [];
    let html = '';
    let sonGrup = null;
    [...sel.options].forEach((o, i) => {
      if (o.hidden) return;
      if (q && !normal(o.textContent).includes(q)) return;
      const grup = o.parentElement.tagName === 'OPTGROUP' ? o.parentElement.label : null;
      if (grup && grup !== sonGrup) { html += `<div class="rs-grup">${grup.replace(/</g, '&lt;')}</div>`; }
      sonGrup = grup;
      const secili = i === INDEKS.get.call(sel);
      html += `<div class="rs-sec${secili ? ' secili' : ''}${o.disabled ? ' pasif' : ''}" data-i="${i}" role="option" aria-selected="${secili}">${o.textContent.trim().replace(/</g, '&lt;') || '&nbsp;'}</div>`;
      if (!o.disabled) gorunen.push(i);
    });
    liste.innerHTML = html || '<div class="rs-yok">Sonuç yok</div>';
    aktif = q ? 0 : Math.max(0, gorunen.indexOf(INDEKS.get.call(sel)));
    vurgula();
  };
  const vurgula = () => {
    liste.querySelectorAll('.rs-sec.aktif').forEach((x) => x.classList.remove('aktif'));
    const hedef = liste.querySelector(`.rs-sec[data-i="${gorunen[aktif]}"]`);
    if (hedef) { hedef.classList.add('aktif'); hedef.scrollIntoView({ block: 'nearest' }); }
  };
  const sec = (i) => {
    const o = sel.options[i];
    if (!o || o.disabled) return;
    const degisti = INDEKS.get.call(sel) !== i;
    INDEKS.set.call(sel, i);
    etiketYenile(sel);
    kapat();
    btn.focus();
    if (degisti) {
      sel.dispatchEvent(new Event('input', { bubbles: true }));
      sel.dispatchEvent(new Event('change', { bubbles: true }));
    }
  };
  const konumla = () => {
    const r = btn.getBoundingClientRect();
    panel.style.minWidth = Math.max(r.width, 200) + 'px';
    panel.style.left = Math.min(r.left, window.innerWidth - panel.offsetWidth - 8) + 'px';
    const alt = window.innerHeight - r.bottom;
    const yuk = Math.min(panel.offsetHeight, 340);
    panel.style.top = (alt < yuk + 12 && r.top > alt ? r.top - yuk - 4 : r.bottom + 4) + 'px';
  };

  liste.addEventListener('mousedown', (e) => e.preventDefault()); // input odagini kaybetmesin
  liste.addEventListener('click', (e) => { const d = e.target.closest('.rs-sec'); if (d) sec(+d.dataset.i); });
  ara.addEventListener('input', ciz);
  ara.addEventListener('keydown', (e) => {
    if (e.key === 'ArrowDown') { e.preventDefault(); aktif = Math.min(aktif + 1, gorunen.length - 1); vurgula(); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); aktif = Math.max(aktif - 1, 0); vurgula(); }
    else if (e.key === 'Enter') { e.preventDefault(); if (gorunen[aktif] !== undefined) sec(gorunen[aktif]); }
    else if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); kapat(); btn.focus(); }
    else if (e.key === 'Tab') { kapat(); }
  });

  const disTik = (e) => { if (!panel.contains(e.target) && e.target !== btn && !btn.contains(e.target)) kapat(); };
  const kaydir = (e) => { if (!panel.contains(e.target)) konumla(); };
  document.addEventListener('mousedown', disTik, true);
  window.addEventListener('scroll', kaydir, true);
  window.addEventListener('resize', konumla);

  btn.classList.add('rs-acik');
  btn.setAttribute('aria-expanded', 'true');
  acik = {
    sel,
    kapat() {
      document.removeEventListener('mousedown', disTik, true);
      window.removeEventListener('scroll', kaydir, true);
      window.removeEventListener('resize', konumla);
      btn.classList.remove('rs-acik');
      btn.setAttribute('aria-expanded', 'false');
      panel.remove();
    },
  };
  ciz();
  konumla();
  ara.focus();
}

function kapat() {
  if (acik) { const a = acik; acik = null; a.kapat(); }
}

function tara(kok) {
  if (kok.tagName === 'SELECT') gelistir(kok);
  kok.querySelectorAll?.('select').forEach(gelistir);
}

tara(document.body);
new MutationObserver((kayitlar) => {
  for (const k of kayitlar) {
    k.addedNodes.forEach((n) => { if (n.nodeType === 1) tara(n); });
    // Acik listenin select'i DOM'dan kalktiysa (yeniden cizim) listeyi kapat.
    if (acik && !document.body.contains(acik.sel)) kapat();
  }
}).observe(document.body, { childList: true, subtree: true });

window.rcAramaSelect = { tara, kapat };
