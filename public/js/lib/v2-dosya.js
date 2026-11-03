/**
 * v2 ÇOK PARÇALI (dosyalı) istek — TEK KAYNAK. api() (v2-ortak.js) yalnız JSON gönderir.
 * Import yolu her yerde AYNI olmalı:
 *   import { apiForm } from '/js/lib/v2-dosya.js?v=1';
 * Dönüş api() ile aynı: {ok, kod, govde}.
 */
import { BASE, token } from '/js/lib/v2-ortak.js?v=1';

export async function apiForm(yol, formData, method = 'POST') {
  const r = await fetch(BASE + yol, {
    method, cache: 'no-store', body: formData,
    headers: { Accept: 'application/json', Authorization: 'Bearer ' + token() },
  });
  let govde = null;
  try { govde = await r.json(); } catch (_) { govde = null; }
  return { ok: r.ok, kod: r.status, govde };
}
