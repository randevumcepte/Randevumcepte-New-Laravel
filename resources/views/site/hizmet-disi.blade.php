<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Web sitemiz hizmet dışıdır | {{ $salon->salon_adi }}</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px 16px; background: #f4f2f8; color: #2b2438; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
        main { width: 100%; max-width: 480px; padding: 40px 28px; text-align: center; background: #fff; border-radius: 16px; box-shadow: 0 8px 30px rgba(60, 30, 100, .1); }
        svg { width: 56px; height: 56px; color: #5c008e; }
        h1 { margin: 20px 0 12px; font-size: 24px; line-height: 1.3; color: #5c008e; }
        p { margin: 0; font-size: 16px; line-height: 1.6; color: #5a5366; }
        .salon { margin-bottom: 6px; font-weight: 600; color: #2b2438; }
    </style>
</head>
<body>
    <main>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <circle cx="12" cy="12" r="10"></circle>
            <line x1="12" y1="7" x2="12" y2="13"></line>
            <line x1="12" y1="16.5" x2="12" y2="16.5"></line>
        </svg>
        <h1>Web sitemiz hizmet dışıdır</h1>
        <p class="salon">{{ $salon->salon_adi }}</p>
        <p>Web sitemize ve online randevu hizmetimize şu anda ulaşılamıyor. Anlayışınız için teşekkür ederiz.</p>
    </main>
</body>
</html>
