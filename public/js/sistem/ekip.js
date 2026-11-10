/** Ekip & Roller: liste, ekle/duzenle modali, pasif etme, rol izinleri. Roller ve yazma yetkisi API'den. */
import { el, esc, onay, mesaj } from '/js/lib/v2-ortak.js?v=7';
import { veriTablosu } from '/js/lib/v2-tablo.js?v=8';
import { modalAc, modalKapat } from '/js/lib/v2-modal.js?v=7';
import { ekran, syVeri, syYaz } from '/js/lib/v2-sistem.js?v=7';
import { avatar, rozet, sayfaBaslik, syTablo } from '/js/sistem/ortak.js?v=1';

let secenek = null;
let tablo = null;
let satirlar = [];

const satir = (u) => {
  const islemler = secenek.yazabilir ? `
    <button class="sy-btn sy-btn-sm sy-btn-soft" data-duzenle="${u.id}" title="Düzenle" aria-label="Düzenle"><span class="mdi mdi-pencil"></span></button>
    ${u.aktif && u.id !== secenek.ben_id ? `<button class="sy-btn sy-btn-sm sy-btn-danger" data-pasif="${u.id}" title="Pasif et" aria-label="Pasif et"><span class="mdi mdi-cancel"></span></button>` : ''}` : '';
  return `<tr>
    <td><div class="sy-flex-row">${avatar(u.ad, 32)}<div><div class="sy-fw-600">${esc(u.ad)}</div><div class="sy-text-muted sy-fs-12">ID: ${u.id}</div></div></div></td>
    <td>${esc(u.email)}</td>
    <td>${esc(u.telefon || '—')}</td>
    <td>${rozet(u.rol_renk, u.rol_etiket)}</td>
    <td class="sy-text-muted sy-fs-12">${u.son_giris_once ? `${esc(u.son_giris_once)}<br><span class="sy-text-soft">${esc(u.son_giris_ip || '')}</span>` : '—'}</td>
    <td>${u.aktif ? rozet('success', 'Aktif') : rozet('muted', 'Pasif')}</td>
    <td class="sy-text-right nowrap">${islemler}</td>
  </tr>`;
};

function formAc(u = null) {
  const rol = u ? u.rol : 'destek';
  const govde = `
    <div class="sy-form-row">
      <div class="sy-form-group"><label>Ad Soyad *</label><input id="ekAd" class="sy-input" value="${esc(u?.ad || '')}"></div>
      <div class="sy-form-group"><label>E-posta *</label><input id="ekEmail" type="email" class="sy-input" value="${esc(u?.email || '')}"></div>
    </div>
    <div class="sy-form-row">
      <div class="sy-form-group"><label>Telefon</label><input id="ekTelefon" class="sy-input" value="${esc(u?.telefon || '')}"></div>
      <div class="sy-form-group"><label>Rol *</label>
        <select id="ekRol" class="sy-select">${secenek.roller.map((r) => `<option value="${esc(r.kod)}"${r.kod === rol ? ' selected' : ''}>${esc(r.etiket)} · ${esc(r.aciklama)}</option>`).join('')}</select>
      </div>
    </div>
    <div class="sy-form-row">
      <div class="sy-form-group"><label>Şifre ${u ? '(boş bırakılırsa değişmez)' : '*'}</label><input id="ekSifre" type="password" class="sy-input" minlength="6" autocomplete="new-password"></div>
      ${u ? `<div class="sy-form-group"><label>Durum</label><label class="sy-flex-row" style="margin-top:10px"><input id="ekAktif" type="checkbox"${u.aktif ? ' checked' : ''}> Aktif (giriş yapabilir)</label></div>` : ''}
    </div>`;
  const alt = `<button class="sy-btn" type="button" id="ekVazgec">İptal</button>
    <button class="sy-btn sy-btn-primary" type="button" id="ekKaydet"><span class="mdi mdi-content-save"></span> Kaydet</button>`;
  const ov = modalAc(u ? `${u.ad} — Düzenle` : 'Yeni Ekip Üyesi', govde, alt, { ikon: 'person-gear', altbaslik: 'Rol ve erişim ayarları', sabit: true });

  el('#ekVazgec', ov).onclick = modalKapat;
  el('#ekKaydet', ov).onclick = async (e) => {
    const sifre = el('#ekSifre', ov).value;
    if (!u && !sifre) { mesaj('Şifre zorunludur.', 'hata'); return; }
    const b = e.currentTarget;
    b.disabled = true;
    const govdeVeri = {
      name: el('#ekAd', ov).value.trim(),
      email: el('#ekEmail', ov).value.trim(),
      telefon: el('#ekTelefon', ov).value.trim() || null,
      rol: el('#ekRol', ov).value,
      ...(sifre ? { password: sifre } : {}),
      ...(u ? { aktif: el('#ekAktif', ov).checked } : {}),
    };
    const { tamam } = await syYaz(u ? 'PUT' : 'POST', u ? `/ekip/${u.id}` : '/ekip', govdeVeri);
    if (tamam) { modalKapat(); tablo.yenile(false); } else b.disabled = false;
  };
}

async function pasifEt(id) {
  if (!await onay('Bu ekip üyesi pasif edilsin mi?', { baslik: 'Pasif Et', tamam: 'Pasif Et' })) return;
  const { tamam } = await syYaz('POST', `/ekip/${id}/pasif`);
  if (tamam) tablo.yenile(false);
}

function rolIzinleriCiz() {
  const izinTablo = veriTablosu(el('#ekipIzin'), {
    anahtar: 'sy_rol_izin',
    tabloSinif: 'sy-table',
    birim: 'yetenek',
    araIpucu: 'Yetenek…',
    araMetni: (k) => k.yetenek,
    kolonlar: [{ baslik: 'Yetenek' }, ...secenek.roller.map((r) => ({ baslik: r.etiket }))],
    satir: (k) => `<tr><td>${esc(k.yetenek)}</td>${k.degerler.map((d) => `<td>${esc(d)}</td>`).join('')}</tr>`,
  });
  izinTablo.kayitlarAyarla(secenek.rol_izinleri);
}

async function baslat() {
  secenek = await syVeri('/ekip/secenekler');
  if (!secenek) return;
  ekran().innerHTML = `
    ${sayfaBaslik('Ekip & Roller', 'Ekip üyeleri, rol atamaları ve son giriş bilgileri', secenek.yazabilir ? '<button class="sy-btn sy-btn-primary" type="button" id="ekipYeni"><span class="mdi mdi-plus"></span> Yeni Üye</button>' : '')}
    <div class="sy-card"><div class="sy-card-body tight" id="ekipTablo"></div></div>
    <div class="sy-card sy-mt-24"><div class="sy-card-head"><h3>Rol İzinleri</h3></div><div class="sy-card-body" id="ekipIzin"></div></div>`;

  tablo = syTablo(el('#ekipTablo'), {
    yol: '/ekip',
    anahtar: 'sy_ekip',
    varsayilanBoy: 25,
    birim: 'üye',
    araIpucu: 'Ad, e-posta, telefon…',
    sirala: { alan: 'durum', yon: 'desc' },
    kolonlar: [
      { baslik: 'Üye', sirala: 'uye' }, { baslik: 'E-posta', sirala: 'email' }, { baslik: 'Telefon', sirala: 'telefon' }, { baslik: 'Rol', sirala: 'rol' },
      { baslik: 'Son Giriş', sirala: 'son_giris' }, { baslik: 'Durum', sirala: 'durum' }, { baslik: 'İşlem', sinif: 'sy-text-right' },
    ],
    satir,
    cizildi: (liste) => { satirlar = liste; },
  });

  el('#ekipYeni')?.addEventListener('click', () => formAc());
  el('#ekipTablo').addEventListener('click', (e) => {
    const duzenle = e.target.closest('[data-duzenle]');
    if (duzenle) { formAc(satirlar.find((u) => u.id === Number(duzenle.dataset.duzenle))); return; }
    const pasif = e.target.closest('[data-pasif]');
    if (pasif) pasifEt(Number(pasif.dataset.pasif));
  });
  rolIzinleriCiz();
}

baslat();
