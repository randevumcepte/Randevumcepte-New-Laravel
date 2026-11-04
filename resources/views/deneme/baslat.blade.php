@php
    // Tanitim sayfalari WordPress'te kalir; bu sayfa yalniz formu sunar.
    $site = 'https://randevumcepte.com.tr';
    $menu = [
        ['Anasayfa', '/'],
        ['Özellikler', '/ozellikler', [
            ['Randevu Yönetimi', '/ozellikler#randevu-yonetimi'], ['Adınıza Özel Uygulama', '/ozellikler#uygulama'],
            ['Santral Sistemi', '/ozellikler#santral'], ['Reklam Yönetimi', '/ozellikler#reklam-yonetimi'],
            ['Etkinlik Yönetimi', '/ozellikler#etkinlik-yonetimi'], ['Arşiv Yönetimi', '/ozellikler#arsiv'],
            ['Ajanda Sistemi', '/ozellikler#ajanda'], ['Adisyon Yönetimi', '/ozellikler#adisyon-yonetimi'],
            ['Telefon ve SMS Hatırlatmaları', '/ozellikler#hatirlatmalar'], ['Ön Görüşme Sistemi', '/ozellikler#on-gorusme'],
            ['Seans Takibi', '/ozellikler#seans-takibi'], ['Diğer Özellikler', '/ozellikler#diger-ozellikler'],
        ]],
        ['Uygulama', '/uygulama'],
        ['Kimler Kullanıyor', '#', [
            ['Güzellik Merkezleri', '/guzellik-merkezi-randevu-sistemi/'], ['Estetik Merkezleri', '/estetik-merkezi-programi/'],
            ['Doktorlar', '/doktor-randevu-sistemi/'], ['Diyetisyenler', '/diyetisyen-randevu-sistemi/'],
            ['Kaş ve Kirpik Merkezleri', '/kas-ve-kirpik-merkezleri-randevu-sistemi/'], ['Klinikler', '/klinik-merkezleri-randevu-sistemi/'],
            ['Makyaj & Tırnak Stüdyoları', '/makyaj-ve-tirnak-studyosu-randevu-sistemi/'], ['Masaj & Spa Salonları', '/masaj-ve-spa-salonlari-randevu-sistemi/'],
            ['Solaryum Merkezleri', '/solaryum-merkezleri-randevu-sistemi/'], ['Tattoo & Piercing Salonları', '/tattoo-ve-piercing-salonlari-randevu-sistemi/'],
        ]],
        ['Ortaklık', '/satis-ortakligi/'],
    ];
    $kurumsal = [
        ['Mesafeli Satış Sözleşmesi', '/mesafeli-satis-sozlesmesi'], ['Gizlilik ve Güvenlik', '/gizlilik-politikasi-ve-guvenlik'],
        ['KVKK Aydınlatma Metni', '/kvkk-aydinlatma-metni'], ['Blog', '/blog/'],
    ];
    $satisTel = '0541 294 81 44';
    $satisTelLink = 'tel:+905412948144';
    $adres = 'Adalet, Şht. Polis Fethi Sekin Cd Ventus Tower No:6 Kat: 3 D:32, 35530 Bayraklı/İzmir';
    $eposta = 'info@randevumcepte.com.tr';
@endphp
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Ücretsiz Denemenizi Başlatın | RandevumCepte</title>
    <link rel="icon" type="image/png" href="/img/oturum/ikon.png">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="/css/deneme.css?v=1">
</head>
<body>
    <div class="dn-ust-serit">
        <span>Satış ortağımız olun, yüksek komisyon kazanın!</span>
        <a href="{{ $satisTelLink }}">{{ $satisTel }} <i class="bi bi-telephone-fill"></i></a>
    </div>

    <header class="dn-baslik">
        <a href="{{ $site }}"><img class="dn-logo" src="/img/oturum/logo-yatay.png" alt="RandevumCepte"></a>
        <input class="dn-menu-anahtar" type="checkbox" id="dnMenuAnahtar" aria-label="Menüyü aç">
        <label class="dn-menu-tetik" for="dnMenuAnahtar"><i class="bi bi-list"></i></label>
        <nav class="dn-menu" aria-label="Ana menü">
            <ul>
                @foreach ($menu as $oge)
                    <li>
                        <a href="{{ $oge[1] === '#' ? '#' : $site.$oge[1] }}">{{ $oge[0] }} @isset($oge[2])<i class="bi bi-chevron-down"></i>@endisset</a>
                        @isset($oge[2])
                            <ul class="dn-alt-menu">
                                @foreach ($oge[2] as [$altAd, $altYol])
                                    <li><a href="{{ $site.$altYol }}">{{ $altAd }}</a></li>
                                @endforeach
                            </ul>
                        @endisset
                    </li>
                @endforeach
            </ul>
            <div class="dn-baslik-dugmeler">
                <a class="dn-dugme" href="/v2/giris"><i class="bi bi-box-arrow-in-right"></i> Giriş</a>
                <a class="dn-dugme dn-dugme-cerceve" href="/ucretsiz-denemenizi-baslatin">Ücretsiz Deneyin!</a>
            </div>
        </nav>
    </header>

    <section class="dn-kahraman">
        <div class="dn-kap dn-iki-sutun">
            <div>
                <span class="dn-rozet dn-rozet-mavi">Şimdiye kadarki en güçlü ve size özel oluşturulan randevu sistemi.</span>
                <h1>İşletmenizi kontrol altına almak için <b>ÜCRETSİZ DENEMEYİ HEMEN BAŞLATIN</b></h1>
            </div>

            <div class="dn-form-kart">
                <h2>Üye Ol</h2>
                <form id="denemeFormu" novalidate>
                    <label class="dn-etiket" for="salonTuru">İşletme Türü</label>
                    <select class="dn-secim" id="salonTuru" name="salon_turu_id" required><option value="">Seçiniz</option></select>
                    <input class="dn-alan" type="text" name="isletme_adi" maxlength="120" placeholder="İşletme Adı" aria-label="İşletme Adı" required>
                    <input class="dn-alan" type="text" name="isletme_adresi" maxlength="255" placeholder="İşletme adresi" aria-label="İşletme adresi" required>
                    <input class="dn-alan" type="text" name="ad_soyad" maxlength="100" placeholder="Yetkili Adı Soyadı" aria-label="Yetkili Adı Soyadı" required>
                    <input class="dn-alan" type="tel" name="cep_telefon" maxlength="30" placeholder="Telefon (5XX XXX XX XX)" aria-label="Telefon" autocomplete="tel" required>
                    <input class="dn-alan" type="email" name="email" maxlength="190" placeholder="Email" aria-label="Email" autocomplete="email" required>
                    <button class="dn-gonder" type="submit">ÜYE OL <i class="bi bi-arrow-right"></i></button>
                </form>

                <form id="kodFormu" novalidate hidden>
                    <p class="dn-kod-aciklama" id="kodAciklama"></p>
                    <input class="dn-kod" type="text" name="kod" inputmode="numeric" maxlength="4" placeholder="SMS ile gelen 4 haneli kod" aria-label="Doğrulama kodu" autocomplete="one-time-code" required>
                    <button class="dn-gonder" type="submit">Doğrula ve Hesabı Aç <i class="bi bi-arrow-right"></i></button>
                    <button class="dn-metin-dugme" type="button" id="formaDon">Bilgilerimi düzelt</button>
                </form>

                <p class="dn-mesaj" id="denemeMesaj" role="alert" hidden></p>
            </div>
        </div>
    </section>

    <section class="dn-iletisim">
        <div class="dn-kap">
            <div class="dn-ortala">
                <span class="dn-rozet dn-rozet-mor">Yardıma mı ihtiyacınız var?</span>
                <h2>Her türlü sorunuz ve önerileriniz <br>için buradayız!</h2>
            </div>
            <div class="dn-iki-sutun dn-iletisim-kartlar">
                <div class="dn-iletisim-kart">
                    <i class="bi bi-headset"></i>
                    <h3>Telefon / Email</h3>
                    <a href="tel:+908503801035">+90 850 380 10 35</a>
                    <a href="tel:+905417840119">+90 541 784 01 19</a>
                    <a class="dn-bosluk" href="mailto:{{ $eposta }}">{{ $eposta }}</a>
                </div>
                <div class="dn-iletisim-kart">
                    <i class="bi bi-geo-alt"></i>
                    <h3>Neredeyiz?</h3>
                    <p>{{ $adres }}</p>
                </div>
            </div>
        </div>
    </section>

    <footer class="dn-alt">
        <div class="dn-kap">
            <div class="dn-ortala">
                <h2>Biz Sizi Arayalım!</h2>
                <p>Müşteri temsilcilerimiz ihtiyaçlarınız doğrultusunda size yardımcı olmak için burada.</p>
            </div>
            <div class="dn-ara-serit">
                <a class="dn-ara-dugme" href="{{ $satisTelLink }}"><i class="bi bi-telephone-fill"></i> {{ $satisTel }}</a>
                <p>Bize hemen ulaşabilir, tüm sorularınızı müşteri temsilcilerimize iletebilirsiniz.</p>
            </div>
            <div class="dn-alt-sutunlar">
                <div>
                    <img class="dn-alt-logo" src="/img/oturum/logo-yatay.png" alt="RandevumCepte">
                    <p>RandevumCepte, tüm müşterilerinizin randevu, alacak, ödeme takibini kolayca her yerden yapabilmenize olanak sağlar.</p>
                </div>
                <div>
                    <h3>Kurumsal</h3>
                    @foreach ($kurumsal as [$ad, $yol])
                        <a class="dn-alt-link" href="{{ $site.$yol }}">{{ $ad }}</a>
                    @endforeach
                </div>
                <div>
                    <h3>İletişim Bilgileri</h3>
                    <a class="dn-alt-tel" href="{{ $satisTelLink }}"><i class="bi bi-telephone-fill"></i> {{ $satisTel }}</a>
                    <p><i class="bi bi-geo-alt-fill"></i> {{ $adres }}</p>
                    <p><i class="bi bi-envelope-fill"></i> {{ $eposta }}</p>
                </div>
            </div>
            <p class="dn-telif">{{ date('Y') }} © RandevumCepte</p>
        </div>
    </footer>

    <a class="dn-whatsapp" href="https://wa.me/905412948144" target="_blank" rel="noopener" aria-label="WhatsApp"><i class="bi bi-whatsapp"></i></a>

    <script type="module" src="/js/oturum/deneme.js?v=1"></script>
</body>
</html>
