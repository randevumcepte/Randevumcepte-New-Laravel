/**
 * Softphone zil sesleri: gelen çağrı zili + giden çağrı geri-zili (ringback).
 * Gelen zil kulaklık takılıyken de duyulsun diye mümkünse HOPARLÖR çıkışına yönlendirilir
 * (eski layout'taki audioOutputSelect/setSinkId mantığı; sayfa açılışında mikrofon izni
 * İSTEMEZ — cihaz adları yalnız izin zaten verilmişse okunabilir, yoksa varsayılan çıkış).
 */
export class Zil {
  /** @param {{zil:string, geri_zil:string}} sesler */
  constructor(sesler) {
    this._gelen = Zil._ses(sesler.zil);
    this._giden = Zil._ses(sesler.geri_zil);
    this._hoparlorSecildi = false;
  }

  async gelenBaslat() {
    this.durdur();
    await this._hoparloreYonlendir();
    this._gelen.play().catch(() => { /* kullanıcı etkileşimi yoksa tarayıcı engelleyebilir */ });
  }

  gidenBaslat() {
    this.durdur();
    this._giden.play().catch(() => {});
  }

  durdur() {
    for (const s of [this._gelen, this._giden]) { s.pause(); s.currentTime = 0; }
  }

  async _hoparloreYonlendir() {
    if (this._hoparlorSecildi || typeof this._gelen.setSinkId !== 'function') return;
    this._hoparlorSecildi = true;
    try {
      const cikislar = (await navigator.mediaDevices.enumerateDevices()).filter((d) => d.kind === 'audiooutput');
      const hoparlor = cikislar.find((d) => /speaker|hoparl/i.test(d.label || ''));
      if (hoparlor) await this._gelen.setSinkId(hoparlor.deviceId);
    } catch (_) { /* varsayılan çıkış */ }
  }

  static _ses(src) {
    const a = new Audio(src);
    a.loop = true;
    a.preload = 'none';
    return a;
  }
}
