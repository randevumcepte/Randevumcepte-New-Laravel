/**
 * SIP hattı — sip.js SimpleUser sarmalayıcısı (DOM bilmez). Eski public/js/santral/webphone.js'in
 * bağlantı + çağrı mantığı; arayüz softphone-panel.js'te.
 *
 * Olaylar (EventTarget):
 *   'durum' → {durum: 'baglaniyor'|'bagli'|'yeniden'|'kopuk', deneme?, hata?}
 *   'cagri' → {asama: 'cikiyor'|'geliyor'|'baglandi'|'bitti', numara, cagriId}
 *   'beklet' → {beklemede: bool}
 *
 * Kopmada (Asterisk reload / ağ) artan gecikmeyle (2 sn … 30 sn) kendiliğinden yeniden bağlanır.
 */
export class SipHat extends EventTarget {
  /** @param {{dahili:string, sifre:string, sip_sunucu:string, wss_url:string}} ayar  @param {HTMLAudioElement} uzakSes */
  constructor(ayar, uzakSes) {
    super();
    this.ayar = ayar;
    this.cagriVar = false;
    this.beklemede = false;
    this._kapatildi = false;
    this._deneme = 0;
    this._yon = null; // 'giden' | 'gelen'
    this._kullanici = new window.SIP.Web.SimpleUser(ayar.wss_url, {
      aor: `sip:${ayar.dahili}@${ayar.sip_sunucu}`,
      media: { remote: { audio: uzakSes } },
      delegate: {
        onCallCreated: () => this._cagriOlustu(),
        onCallReceived: () => this._cagriGeldi(),
        onCallAnswered: () => this._yay('cagri', { asama: 'baglandi', ...this._karsi() }),
        onCallHangup: () => this._cagriBitti(),
        onCallHold: (h) => this._bekletmeDegisti(h),
        onServerConnect: () => this._sunucuBaglandi(),
        onServerDisconnect: (hata) => this._sunucuKoptu(hata),
      },
      userAgentOptions: {
        logLevel: 'warn',
        displayName: ayar.dahili,
        authorizationUsername: ayar.dahili,
        authorizationPassword: ayar.sifre,
        // server transportOptions içinde OLMALI: SimpleUser ilk argümanı yalnız
        // transportOptions yoksa enjekte eder (eski "Invalid WebSocket Server URL" hatası).
        transportOptions: { server: ayar.wss_url, connectionTimeout: 3 },
      },
    });
  }

  baslat() {
    this._yay('durum', { durum: 'baglaniyor' });
    this._baglan();
  }

  durdur() {
    this._kapatildi = true;
    try { this._kullanici.disconnect(); } catch (_) { /* sayfa kapanıyor */ }
  }

  /** Transport gerçekten bağlı mı (bayrağa değil SIP durumuna bakar). */
  bagliMi() {
    try { return !!this._kullanici.isConnected(); } catch (_) { return false; }
  }

  /** Rakam dışı karakterler atılır; bağlı değilse / çağrı sürüyorsa false. */
  ara(numara) {
    const hedef = String(numara || '').replace(/[^\d]/g, '');
    if (!hedef || !this.bagliMi() || this.cagriVar) return false;
    this._yon = 'giden';
    this._hedef = hedef;
    this._kullanici.call(`sip:${hedef}@${this.ayar.sip_sunucu}`, { inviteWithoutSdp: false })
      .catch((e) => { this._yon = null; this._hataYay('Arama başlatılamadı', e); });
    return true;
  }

  cevapla() { return this._kullanici.answer().catch((e) => this._hataYay('Çağrı cevaplanamadı', e)); }

  kapat() { return this._kullanici.hangup().catch((e) => this._hataYay('Çağrı kapatılamadı', e)); }

  bekletDegistir() {
    const islem = this.beklemede ? this._kullanici.unhold() : this._kullanici.hold();
    return islem.catch((e) => this._hataYay('Bekletme değiştirilemedi', e));
  }

  /** Görüşme sırasında tuş tonu (sesli yanıt menüleri). */
  dtmf(ton) {
    if (!this.cagriVar) return;
    this._kullanici.sendDTMF(String(ton)).catch(() => {});
  }

  // ───────────── iç ─────────────
  _baglan() {
    this._kullanici.connect().catch((e) => {
      console.warn('[softphone] bağlanamadı', e);
      this._yenidenBaglan();
    });
  }

  _yenidenBaglan() {
    if (this._kapatildi) return;
    this._deneme += 1;
    this._yay('durum', { durum: 'yeniden', deneme: this._deneme });
    setTimeout(() => { if (!this._kapatildi) this._baglan(); }, Math.min(this._deneme * 2000, 30000));
  }

  _sunucuBaglandi() {
    this._deneme = 0;
    this._kullanici.register().catch((e) => console.warn('[softphone] kayıt olunamadı', e));
    this._yay('durum', { durum: 'bagli' });
  }

  _sunucuKoptu(hata) {
    this._yay('durum', { durum: 'kopuk', hata: hata ? String(hata) : '' });
    this._yenidenBaglan();
  }

  _cagriOlustu() {
    this.cagriVar = true;
    // SimpleUser gelen çağrıda da önce onCallCreated tetikler; yön _cagriGeldi'de düzeltilir.
    if (this._yon === 'giden') this._yay('cagri', { asama: 'cikiyor', numara: this._hedef, cagriId: this._cagriId() });
  }

  _cagriGeldi() {
    this.cagriVar = true;
    this._yon = 'gelen';
    this._yay('cagri', { asama: 'geliyor', ...this._karsi() });
  }

  _cagriBitti() {
    const bilgi = this._karsi();
    this.cagriVar = false;
    this.beklemede = false;
    this._yon = null;
    this._yay('cagri', { asama: 'bitti', ...bilgi });
  }

  _bekletmeDegisti(beklemede) {
    this.beklemede = !!beklemede;
    this._yay('beklet', { beklemede: this.beklemede });
  }

  _karsi() {
    const s = this._kullanici.session;
    const numara = this._yon === 'giden' ? this._hedef : s?.remoteIdentity?.uri?.user;
    return { numara: numara || '', cagriId: this._cagriId(), yon: this._yon };
  }

  _cagriId() { return this._kullanici.session?.id || ''; }

  _hataYay(mesaj, e) {
    console.error('[softphone]', mesaj, e);
    this._yay('hata', { mesaj });
  }

  _yay(tur, detail) { this.dispatchEvent(new CustomEvent(tur, { detail })); }
}
