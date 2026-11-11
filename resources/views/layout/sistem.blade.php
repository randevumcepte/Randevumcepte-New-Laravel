<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@yield('baslik', 'Sistem Yönetim') | randevumcepte</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@mdi/font@7.4.47/css/materialdesignicons.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    {{-- Ortak tablo/modal/toast (rc-*) + sistem yonetimi temasi (sy-*) --}}
    <link rel="stylesheet" href="/css/panel.css?v=24">
    <link rel="stylesheet" href="/css/v2-bilesen.css?v=9">
    <link rel="stylesheet" href="/css/sistem-yonetim.css?v=1">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/l10n/tr.js"></script>
    @stack('stil')
</head>
{{-- Menu, rozetler, kullanici ve bildirimler /api/v2/sistem/kabuk'tan (js/sistem/kabuk.js). data-aktif: vurgulanacak menu kodu. --}}
<body data-aktif="@yield('aktif')">
<div class="sy-app">
    <aside class="sy-sidebar" id="sySidebar">
        <div class="sy-brand">
            <div class="logo-mark">RC</div>
            <div class="brand-text">
                <div class="name">randevumcepte</div>
                <div class="sub">Sistem Yönetim</div>
            </div>
        </div>
        <nav class="sy-nav" id="syMenu"></nav>
        <div class="sy-sidebar-foot">
            <div>{{ now()->format('d.m.Y') }}</div>
            <div style="margin-top:4px"><a href="#" data-cikis><span class="mdi mdi-logout"></span> Çıkış</a></div>
        </div>
    </aside>

    <header class="sy-topbar">
        <div class="sy-flex-row">
            <span class="sy-sidebar-toggle mdi mdi-menu" id="syMenuTetik" style="font-size:22px"></span>
            <h1>@yield('baslik')</h1>
        </div>
        <div class="sy-search-box">
            <span class="mdi mdi-magnify"></span>
            <input type="text" id="syGlobalAra" placeholder="Salon, ticket, ekip ara... (Ctrl+K)" autocomplete="off">
            <div id="syGlobalAraSonuc"></div>
        </div>
        <div class="sy-topbar-right">
            <a href="/sistemyonetim/ticket/yeni" class="sy-btn sy-btn-soft sy-btn-sm" title="Yeni Talep"><span class="mdi mdi-plus"></span> Yeni Talep</a>
            <div class="sy-bell-wrap">
                <button class="sy-bell" id="syBell" type="button" title="Bildirimler">
                    <span class="mdi mdi-bell"></span>
                    <span class="sy-bell-badge" id="syBellBadge" style="display:none">0</span>
                </button>
                <div class="sy-bell-panel" id="syBellPanel">
                    <div class="sy-bell-head">Bildirimler</div>
                    <div class="sy-bell-list" id="syBellList"></div>
                </div>
            </div>
            <div class="sy-user-chip" id="syUserMenuTrigger" style="cursor:pointer">
                <div class="avatar" id="syAvatar"></div>
                <span id="syKullaniciAd"></span>
                <span class="rol" id="syKullaniciRol"></span>
                <div class="sy-user-menu" id="syUserMenu">
                    <a href="/sistemyonetim/profil"><span class="mdi mdi-account-cog"></span> Profilim</a>
                    <a href="/sistemyonetim/duyuru"><span class="mdi mdi-bullhorn"></span> Duyurular</a>
                    <hr style="margin:6px 0;border:0;border-top:1px solid var(--sy-border)">
                    <a href="#" data-cikis style="color:var(--sy-danger)"><span class="mdi mdi-logout"></span> Çıkış Yap</a>
                </div>
            </div>
        </div>
    </header>

    <main class="sy-main">@yield('icerik')</main>
</div>
<script type="module" src="/js/sistem/kabuk.js?v=1"></script>
@stack('betik')
</body>
</html>
