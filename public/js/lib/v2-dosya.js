/**
 * v2 ÇOK PARÇALI (dosyalı) istek — TEK KAYNAK. api() (v2-ortak.js) yalnız JSON gönderir.
 * Import yolu her yerde AYNI olmalı:
 *   import { apiForm } from '/js/lib/v2-dosya.js?v=3';
 * Dönüş api() ile aynı: {ok, kod, govde}.
 */
import { BASE, token } from '/js/lib/v2-ortak.js?v=2';

export async function apiForm(yol, formData, method = 'POST', { kok = BASE, tokenOku = token } = {}) {
  const r = await fetch(kok + yol, {
    method, cache: 'no-store', body: formData,
    headers: { Accept: 'application/json', Authorization: 'Bearer ' + tokenOku() },
  });
  let govde = null;
  try { govde = await r.json(); } catch (_) { govde = null; }
  return { ok: r.ok, kod: r.status, govde };
}

/**
 * Bearer'lı İKİLİ yanıt (görsel / PDF) — <img src> ve <a download> Authorization başlığı
 * gönderemediği için blob olarak alınır. Dönüş: {ok, kod, blob, govde}; hata yanıtında
 * blob null, govde ApiCevap zarfı (mesaj için).
 */
export async function apiBlob(yol, params, { kok = BASE, tokenOku = token } = {}) {
  const qs = params ? new URLSearchParams(Object.entries(params).filter(([, v]) => v !== null && v !== undefined && v !== '')).toString() : '';
  const url = (yol.startsWith(kok) ? yol : kok + yol) + (qs ? (yol.includes('?') ? '&' : '?') + qs : '');
  const r = await fetch(url, { cache: 'no-store', headers: { Authorization: 'Bearer ' + tokenOku() } });
  if (r.ok) return { ok: true, kod: r.status, blob: await r.blob(), govde: null };
  let govde = null;
  try { govde = await r.json(); } catch (_) { govde = null; }
  return { ok: false, kod: r.status, blob: null, govde };
}

/** Blob'u dosya olarak indirir (geçici object URL; iş bitince serbest bırakılır). */
export function blobIndir(blob, dosyaAdi) {
  const url = URL.createObjectURL(blob);
  const a = Object.assign(document.createElement('a'), { href: url, download: dosyaAdi });
  document.body.appendChild(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
}
