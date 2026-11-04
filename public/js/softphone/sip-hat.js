/**
 * SIP hattı — sip.js SimpleUser sarmalayıcısı (DOM bilmez). Eski public/js/santral/webphone.js'in
 * bağlantı + çağrı mantığı; arayüz softphone-panel.js'te.
 *
 * Olaylar (EventTarget):
 *   'durum' → {durum: 'baglaniyor'|'bagli'|'kayitsiz'|'yeniden'|'kopuk', deneme?}
 *             ('bagli' = santrala KAYITLI, yalnız WebSocket açık olması yetmez)
 *   'cagri' → {asama: 'cikiyor'|'geliyor'|'baglandi'|'bitti', numara, cagriId}
 *   'beklet' → {beklemede: bool}
 *
 * Yeniden bağlanmanın TEK sahibi bu sınıf: kopmada artan gecikmeyle (2 sn … 30 sn), tek
 * zamanlayıcıyla dener (sip.js'in kendi denemesi kapalı, ikisi birlikte çalışıp yarışıyordu).
 *
 * durdur() sonrası baslat() çağrılabilir ve her baslat() TEMİZ bir SimpleUser kurar; böylece
 * sayfa tarayıcı önbelleğinden (geri/ileri, bfcache) dönünce eski durumdan bağımsız bağlanır.
 * Eski örneğin geç gelen olayları nesil kontrolüyle yok sayılır.
 */
export class SipHat extends EventTarget {
  /** @param {{dahili:string, sifre:string, sip_sunucu:string, wss_url:string}} ayar  @param {HTMLAudioElement} uzakSes */
  constructor(ayar, uzakSes) {
    super();
    this.ayar = ayar;
    this.uzakSes = uzakSes;
    this.cagriVar = false;
    this.beklemede = false;
    this._kapatildi = true;
    this._deneme = 0;
    this._zaman = null;
    this._nesil = 0;
    this._yon = null; // 'giden' | 'gelen'
    this._kullanici = null;
  }

  baslat() {
    this._zamanlayiciTemizle();
    this._kapatildi = false;
    this._deneme = 0;
    this._kullaniciKur();
    this._yay('durum', { durum: 'baglaniyor' });
    this._baglan();
  }

  durdur() {
    if (this.cagriVar) this._cagriBitti(); // arayüz + zil temizlensin (sayfa geri dönebilir)
    this._kapatildi = true;
    this._zamanlayiciTemizle();
    this._nesil += 1;
    try { this._kullanici?.disconnect(); } catch (_) { /* sayfa kapanıyor */ }
  }

  /** İnternet döndü / sekme öne geldi: bekleme süresini atlayıp hemen dene. */
  hemenDene() {
    if (this._kapatildi || this.bagliMi()) return;
    this._zamanlayiciTemizle();
    this._deneme = 0;
    this._baglan();
  }

  /** Transport gerçekten bağlı mı (bayrağa değil SIP durumuna bakar). */
  bagliMi() {
    try { return !!this._kullanici?.isConnected(); } catch (_) { return false; }
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
  _kullaniciKur() {
    const nesil = ++this._nesil;
    const canli = (f) => (...a) => { if (nesil === this._nesil) f(...a); };
    const ayar = this.ayar;
    this._kullanici = new window.SIP.Web.SimpleUser(ayar.wss_url, {
      aor: `sip:${ayar.dahili}@${ayar.sip_sunucu}`,
      media: { remote: { audio: this.uzakSes } },
      reconnectionAttempts: 0,
      delegate: {
        onCallCreated: canli(() => this._cagriOlustu()),
        onCallReceived: canli(() => this._cagriGeldi()),
        onCallAnswered: canli(() => this._yay('cagri', { asama: 'baglandi', ...this._karsi() })),
        onCallHangup: canli(() => this._cagriBitti()),
        onCallHold: canli((h) => this._bekletmeDegisti(h)),
        onServerConnect: canli(() => this._sunucuBaglandi()),
        onServerDisconnect: canli(() => this._sunucuKoptu()),
        onRegistered: canli(() => this._yay('durum', { durum: 'bagli' })),
        // Reddedilen kaydı sip.js birkaç saniye içinde kendisi yeniden dener.
        onUnregistered: canli(() => { if (this.bagliMi()) this._yay('durum', { durum: 'kayitsiz' }); }),
      },
      userAgentOptions: {
        logLevel: 'warn',
        displayName: ayar.dahili,
        authorizationUsername: ayar.dahili,
        authorizationPassword: ayar.sifre,
        // server transportOptions içinde OLMALI: SimpleUser ilk argümanı yalnız
        // transportOptions yoksa enjekte eder (eski "Invalid WebSocket Server URL" hatası).
        transportOptions: { server: ayar.wss_url, connectionTimeout: 5 },
      },
    });
  }

  _baglan() {
    const nesil = this._nesil;
    this._kullanici.connect().catch((e) => {
      if (nesil !== this._nesil) return;
      console.warn('[softphone] bağlanamadı', e);
      this._yenidenBaglan();
    });
  }

  _yenidenBaglan() {
    if (this._kapatildi || this._zaman !== null) return; // bekleyen deneme varsa yenisi kurulmaz
    this._deneme += 1;
    this._yay('durum', { durum: 'yeniden', deneme: this._deneme });
    this._zaman = setTimeout(() => { this._zaman = null; this._baglan(); }, Math.min(this._deneme * 2000, 30000));
  }

  _zamanlayiciTemizle() {
    if (this._zaman !== null) clearTimeout(this._zaman);
    this._zaman = null;
  }

  _sunucuBaglandi() {
    this._deneme = 0;
    this._zamanlayiciTemizle();
    this._kullanici.register().catch((e) => console.warn('[softphone] kayıt olunamadı', e));
  }

  _sunucuKoptu() {
    if (this.cagriVar) this._cagriBitti();
    this._yay('durum', { durum: 'kopuk' });
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
    if (!this.cagriVar) return; // kopma + hangup aynı çağrı için iki kez bildirmesin
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
