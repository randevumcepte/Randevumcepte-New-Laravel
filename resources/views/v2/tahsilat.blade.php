<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Tahsilat v2 — Adisyon {{ $adisyonId }}</title>
</head>
<body style="margin:0;background:#eef0f5">
    {{-- Island build gerektirmez; /js/tahsilat-v2.js statik servis edilir (public/). --}}
    <div id="rc-tahsilat" data-adisyon="{{ $adisyonId }}"></div>
    <script type="module" src="/js/tahsilat-v2.js?v=4"></script>
</body>
</html>
