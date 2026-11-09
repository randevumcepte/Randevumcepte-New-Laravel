<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('baslik', 'Panel') — Randevumcepte</title>

    {{-- Bootstrap Icons: eski menüyle BİREBİR ikon sınıfları (bi bi-*) --}}
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    {{-- Ortak panel kabuğu stili (tek kaynak; tüm v2 ekranları paylaşır) --}}
    <link rel="stylesheet" href="/css/panel.css?v=20">
    {{-- Ortak UI bileşenleri (modal, form alanı, buton, sekme, rozet) — tek kaynak --}}
    <link rel="stylesheet" href="/css/v2-bilesen.css?v=3">
    {{-- flatpickr datepicker (tüm v2 ekranları paylaşır; window.flatpickr) --}}
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/l10n/tr.js"></script>
    @stack('stil')
</head>
{{--
    v2 panel kabuğu (strangler): sol menü + header eski panelle aynı işlevde ama
    MODERN. Menü/şube linkleri eski panele (app.*) gider → PARALEL kullanım.
    Dinamik veri /api/v2/kabuk'tan (Bearer token) gelir; sayfa içeriği @yield('icerik').
    data-aktif: aktif menü slug'ı (vurgu).
--}}
<body class="rc-panel" data-aktif="@yield('aktif', '')">

    {{-- Mobil overlay (menü açıkken arkaplan) --}}
    <div class="rc-overlay" id="rcOverlay"></div>

    {{-- ───────────────────────── SOL MENÜ ───────────────────────── --}}
    <aside class="rc-sidebar" id="rcSidebar">
        @php $eskiPanel = rtrim(config('panel.eski_panel_url', 'https://app.randevumcepte.com.tr'), '/'); @endphp
        <div class="rc-brand">
            {{-- İkonlar görünürken (daraltılmış): küçük kare; menü açılınca (hover/sabit): tam logo --}}
            <img class="rc-brand-mini" alt="Randevumcepte" src="{{ $eskiPanel }}/public/profil_resimleri/6adf2d1dbc531.jpg">
            <img class="rc-brand-tam"  alt="Randevumcepte" src="{{ $eskiPanel }}/public/yeni_panel/vendors/images/randevumcepte.png">
        </div>
        <nav class="rc-menu" id="rcMenu" aria-label="Ana menü">
            <div class="rc-menu-iskele">Menü yükleniyor…</div>
        </nav>
    </aside>

    {{-- ───────────────────────── İÇERİK SÜTUNU ───────────────────────── --}}
    <div class="rc-govde">

        {{-- HEADER --}}
        <header class="rc-header">
            <button class="rc-ic-btn rc-menu-tetik" id="rcMenuTetik" title="Menü" aria-label="Menü">
                <i class="bi bi-list"></i>
            </button>

            <div class="rc-ara">
                <i class="bi bi-search"></i>
                <input type="search" id="rcAra" placeholder="Müşteri / Danışan arayın" autocomplete="off">
            </div>

            <div class="rc-sube-sec" id="rcSubeSec" title="İşletme / Şube"></div>

            <div class="rc-lisans" id="rcLisans" title="Kalan lisans süresi" style="display:none">
                <i class="bi bi-shop"></i>
                <span class="rc-lisans-ad" id="rcSalonAd"></span>
                <span class="rc-lisans-gun" id="rcLisansGun"></span>
            </div>

            <div class="rc-header-sag">
                {{-- Kalan SMS + WhatsApp durumu + WebRTC softphone (header-araclar.js doldurur; kurallar sunucuda) --}}
                <div class="rc-header-araclar">
                    <div id="rcSmsRozet"></div>
                    <div id="rcWaRozet"></div>
                    <div id="rcSoftphone" class="rc-sp"></div>
                </div>
                {{-- Bildirim zili: rozet + liste bildirim-zil.js ile /api/v2/bildirim'den --}}
                <div class="rc-acilir rc-zil" id="rcBildirim">
                    <button class="rc-header-ic rc-zil-btn" type="button" data-acilir-tetik title="Bildirimler" aria-label="Bildirimler" aria-haspopup="true" aria-expanded="false">
                        <i class="bi bi-bell"></i>
                        <span class="rc-zil-rozet" id="rcBildirimRozet" hidden></span>
                    </button>
                    <div class="rc-acilir-menu rc-zil-menu" id="rcBildirimMenu"></div>
                </div>
                <a class="rc-header-ic" id="rcAyarBtn" title="Ayarlar" style="display:none"><i class="bi bi-gear"></i></a>
                {{-- "+" ve kullanıcı menüsü öğeleri sunucudan (/api/v2/kabuk: hizli_ekle, kullanici_menu) --}}
                <div class="rc-acilir" id="rcHizliEkle" hidden>
                    <button class="rc-header-ic rc-hizli-btn" type="button" data-acilir-tetik title="Yeni ekle" aria-label="Yeni ekle" aria-haspopup="menu" aria-expanded="false"><i class="bi bi-plus-lg"></i></button>
                    <div class="rc-acilir-menu" role="menu"></div>
                </div>
                <div class="rc-acilir" id="rcKullaniciKutu">
                    <button class="rc-kullanici" id="rcKullanici" type="button" data-acilir-tetik aria-haspopup="menu" aria-expanded="false">
                        <div class="rc-avatar" id="rcAvatar">R</div>
                        <span class="rc-kullanici-ad" id="rcKullaniciAd">…</span>
                        <i class="bi bi-chevron-down rc-kullanici-ok"></i>
                    </button>
                    <div class="rc-acilir-menu" role="menu"></div>
                </div>
            </div>
        </header>

        {{-- SAYFA İÇERİĞİ --}}
        <main class="rc-icerik">
            @yield('icerik')
        </main>
    </div>

    {{-- Ortak kabuk JS (menü/header'ı /api/v2/kabuk ile doldurur) --}}
    {{-- Aranabilir select: TUM select'leri (sonradan eklenenler dahil) otomatik donusturur. --}}
    <script type="module" src="/js/arama-select.js?v=2"></script>
    {{-- Telefon: TUM input[type=tel]'lere ulke kodu secici + ulkeye gore bicim (sonradan eklenenler dahil). --}}
    <script type="module" src="/js/telefon-ulke.js?v=1"></script>
    {{-- Header rozetleri 'rc:kabuk' olayini dinler → kabuktan ONCE yuklenir --}}
    <script type="module" src="/js/header-araclar.js?v=4"></script>
    <script type="module" src="/js/bildirim-zil.js?v=1"></script>
    <script type="module" src="/js/hizli-ekle.js?v=6"></script>
    <script type="module" src="/js/panel-kabuk.js?v=11"></script>
    @stack('betik')
</body>
</html>
