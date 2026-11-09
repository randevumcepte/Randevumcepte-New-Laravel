/** Profilim: kisisel bilgiler, sifre degisikligi, son girisler ve aktiviteler. Her rol kendi profilini gorur. */
import { el, esc } from '/js/lib/v2-ortak.js?v=7';
import { ekran, syVeri, syYaz } from '/js/lib/v2-sistem.js?v=7';
import { bos, kisalt, rozet, sayfaBaslik } from '/js/sistem/ortak.js?v=1';

const SATIR = 'style="padding:10px 18px;border-bottom:1px solid var(--sy-border)"';

const girisSatiri = (g) => `<div ${SATIR}>
  <div class="sy-flex-row" style="justify-content:space-between">
    <div><strong>${esc(g.ip || '—')}</strong> <span class="sy-text-muted sy-fs-12">${esc(kisalt(g.tarayici, 60))}</span></div>
    ${rozet(g.basarili ? 'success' : 'danger', g.basarili ? 'OK' : 'Hata')}
  </div>
  <div class="sy-text-muted sy-fs-12">${esc(g.zaman)}</div>
</div>`;

const aktiviteSatiri = (a) => `<div ${SATIR}>
  <div class="sy-fw-600">${esc(a.islem)} <span class="sy-text-muted sy-fs-12">→ ${esc(a.hedef_tipi || '')}: ${esc(a.hedef_etiket || '')}</span></div>
  <div class="sy-text-muted sy-fs-12">${esc(a.zaman)} · ${esc(a.ip || '')}</div>
</div>`;

async function baslat() {
  const v = await syVeri('/profil');
  if (!v) return;
  const p = v.profil;
  ekran().innerHTML = `
    ${sayfaBaslik('Profilim', 'Kişisel bilgileriniz, şifre değişikliği ve aktivite geçmişiniz')}
    <div class="sy-grid-2">
      <div class="sy-card">
        <div class="sy-card-head"><h3>Kişisel Bilgiler</h3></div>
        <div class="sy-card-body">
          <form id="prForm">
            <div class="sy-form-group"><label>Ad Soyad</label><input name="name" class="sy-input" value="${esc(p.ad)}" required></div>
            <div class="sy-form-group"><label>E-posta</label><input name="email" type="email" class="sy-input" value="${esc(p.email)}" required></div>
            <div class="sy-form-group"><label>Telefon</label><input name="telefon" class="sy-input" value="${esc(p.telefon || '')}"></div>
            <div class="sy-form-group"><label>Rol</label><input class="sy-input" value="${esc(p.rol_etiket)}" disabled>
              <div class="sy-text-muted sy-fs-12">Rol değişikliği için Süper Admin'e başvurun.</div></div>
            <button class="sy-btn sy-btn-primary"><span class="mdi mdi-content-save"></span> Kaydet</button>
          </form>
        </div>
      </div>
      <div class="sy-card">
        <div class="sy-card-head"><h3>Şifre Değiştir</h3></div>
        <div class="sy-card-body">
          <form id="prSifre">
            <div class="sy-form-group"><label>Mevcut Şifre</label><input name="mevcut_sifre" type="password" class="sy-input" autocomplete="current-password" required></div>
            <div class="sy-form-group"><label>Yeni Şifre (en az 6 karakter)</label><input name="yeni_sifre" type="password" class="sy-input" autocomplete="new-password" minlength="6" required></div>
            <div class="sy-form-group"><label>Yeni Şifre (Tekrar)</label><input name="yeni_sifre_confirmation" type="password" class="sy-input" autocomplete="new-password" minlength="6" required></div>
            <button class="sy-btn sy-btn-primary"><span class="mdi mdi-key-change"></span> Şifre Güncelle</button>
          </form>
        </div>
      </div>
    </div>
    <div class="sy-grid-2 sy-mt-24">
      <div class="sy-card">
        <div class="sy-card-head"><h3>Son Girişler</h3></div>
        <div class="sy-card-body tight">${v.son_girisler.length ? v.son_girisler.map(girisSatiri).join('') : bos('', 'Kayıt yok')}</div>
      </div>
      <div class="sy-card">
        <div class="sy-card-head"><h3>Son Aktiviteleriniz</h3></div>
        <div class="sy-card-body tight">${v.son_aktiviteler.length ? v.son_aktiviteler.map(aktiviteSatiri).join('') : bos('', 'Henüz aktivite yok')}</div>
      </div>
    </div>`;

  el('#prForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const f = e.currentTarget;
    const { tamam, veri } = await syYaz('PUT', '/profil', { name: f.elements.name.value.trim(), email: f.email.value.trim(), telefon: f.telefon.value.trim() || null });
    if (tamam && veri) {
      el('#syKullaniciAd').textContent = veri.ad;
      el('#syAvatar').textContent = (veri.ad || '?').charAt(0);
    }
  });
  el('#prSifre').addEventListener('submit', async (e) => {
    e.preventDefault();
    const f = e.currentTarget;
    const { tamam } = await syYaz('POST', '/profil/sifre', {
      mevcut_sifre: f.mevcut_sifre.value, yeni_sifre: f.yeni_sifre.value, yeni_sifre_confirmation: f.yeni_sifre_confirmation.value,
    });
    if (tamam) f.reset();
  });
}

baslat();
