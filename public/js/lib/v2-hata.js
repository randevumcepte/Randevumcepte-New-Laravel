/** 422 doğrulama zarfında ({veri: {alan: [mesaj]}}) ilk alan mesajı; yoksa null. */
export function ilkAlanHatasi(govde) {
  const v = govde && !govde.basarili && govde.veri;
  if (!v || typeof v !== 'object') return null;
  const ilk = Object.values(v).find((m) => Array.isArray(m) && m.length);
  return ilk ? String(ilk[0]) : null;
}
