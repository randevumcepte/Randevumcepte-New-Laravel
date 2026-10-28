/**
 * apiV2 — Web panelinin /api/v2 uçlarına eriştiği ince istemci.
 *
 * Çerçeveden bağımsızdır (Vue, vanilla, hepsi kullanabilir). jQuery/axios YOK;
 * native `fetch` kullanır. Amaç: Flutter uygulamasının API katmanıyla AYNI
 * sözleşmeyi (Bearer token + JSON) web tarafında da tek yerden yönetmek, böylece
 * her ekranda tekrar tekrar fetch/hata/token mantığı yazılmasın (reusable).
 *
 * Token: Passport Bearer token (eski monolitle paylaşılan oturumdan gelir).
 * Geçici olarak localStorage'tan okunur; Passport köprüsü kurulunca bu
 * fonksiyon merkezi olarak güncellenecek — çağıran ekranlar değişmeyecek.
 */

const BASE_URL = '/api/v2';

/** O anki erişim tokenını döndürür (Passport Bearer). */
function tokenAl() {
  return localStorage.getItem('rc_access_token') || '';
}

/**
 * Düşük seviye istek. Genelde doğrudan çağrılmaz; get/post sarmalayıcılarını kullan.
 *
 * @param {string} yol      - /tahsilat/gecmis gibi v2 göreli yol
 * @param {object} secenek  - { method, body, params, headers }
 * @returns {Promise<any>}  - Çözümlenmiş JSON gövdesi
 * @throws {ApiV2Hatasi}    - HTTP 2xx dışı veya ağ hatasında
 */
async function istek(yol, { method = 'GET', body = null, params = null, headers = {} } = {}) {
  let url = BASE_URL + yol;

  if (params) {
    const qs = new URLSearchParams(params).toString();
    if (qs) url += (url.includes('?') ? '&' : '?') + qs;
  }

  const token = tokenAl();

  const yanit = await fetch(url, {
    method,
    headers: {
      'Accept': 'application/json',
      'Content-Type': 'application/json',
      ...(token ? { 'Authorization': `Bearer ${token}` } : {}),
      ...headers,
    },
    body: body !== null ? JSON.stringify(body) : undefined,
  });

  // 204 veya boş gövde ihtimaline karşı güvenli ayrıştırma.
  const metin = await yanit.text();
  const veri = metin ? JSON.parse(metin) : null;

  if (!yanit.ok) {
    throw new ApiV2Hatasi(yanit.status, veri);
  }

  return veri;
}

/** API hatalarını tek tipte temsil eder (ekranlar tek şekilde yakalar). */
export class ApiV2Hatasi extends Error {
  constructor(durumKodu, govde) {
    super((govde && (govde.mesaj || govde.message)) || `API hatası (${durumKodu})`);
    this.name = 'ApiV2Hatasi';
    this.durumKodu = durumKodu;
    this.govde = govde;
  }
}

export const apiV2 = {
  get: (yol, params) => istek(yol, { method: 'GET', params }),
  post: (yol, body) => istek(yol, { method: 'POST', body }),
  put: (yol, body) => istek(yol, { method: 'PUT', body }),
  delete: (yol, body) => istek(yol, { method: 'DELETE', body }),
};

export default apiV2;
