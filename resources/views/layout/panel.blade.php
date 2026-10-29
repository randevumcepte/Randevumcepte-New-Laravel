<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('baslik', 'Panel') — Randevumcepte</title>

    {{-- Bootstrap Icons: eski menüyle BİREBİR ikon sınıfları (bi bi-*) --}}
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    {{-- Ortak panel kabuğu stili (tek kaynak; tüm v2 ekranları paylaşır) --}}
    <link rel="stylesheet" href="/css/panel.css?v=3">
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
                <a class="rc-header-ic" id="rcAyarBtn" title="Ayarlar"><i class="bi bi-gear"></i></a>
                <a class="rc-header-ic rc-geri" href="https://app.randevumcepte.com.tr/isletmeyonetim"
                   title="Eski panele dön"><i class="bi bi-box-arrow-left"></i><span>Panel</span></a>
                <div class="rc-kullanici" id="rcKullanici">
                    <div class="rc-avatar" id="rcAvatar">R</div>
                    <span class="rc-kullanici-ad" id="rcKullaniciAd">…</span>
                </div>
            </div>
        </header>

        {{-- SAYFA İÇERİĞİ --}}
        <main class="rc-icerik">
            @yield('icerik')
        </main>
    </div>

    {{-- Ortak kabuk JS (menü/header'ı /api/v2/kabuk ile doldurur) --}}
    <script type="module" src="/js/panel-kabuk.js?v=3"></script>
    @stack('betik')
</body>
</html>
