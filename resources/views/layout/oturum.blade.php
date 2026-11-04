<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@yield('baslik') | RandevumCepte</title>
    <link rel="icon" type="image/png" href="/img/oturum/ikon.png">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="/css/oturum.css?v=1">
</head>
<body>
    <div class="ot-arkaplan" id="oturumArkaplan"></div>
    <main class="ot-kap">
        <div class="ot-kart">
            <img class="ot-logo" src="/img/oturum/logo.png" alt="RandevumCepte">
            @yield('icerik')
        </div>
    </main>
    <script src="/js/vendor/particles.min.js"></script>
    <script src="/js/oturum/arkaplan.js?v=1"></script>
    @stack('betik')
</body>
</html>
