{{-- Herkese açık salon dizini (eski salonlar-dizini): her salon sitesine iç link; ana siteden buraya tek link verilir. --}}
<!doctype html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="index, follow">
    <title>Salon Dizini | Randevumcepte</title>
    <meta name="description" content="Randevumcepte'de online randevu alabileceğiniz tüm güzellik salonları, kuaförler ve estetik merkezleri.">
    <link rel="canonical" href="{{ $canonical }}">
    <link rel="sitemap" type="application/xml" title="Sitemap" href="/salonlar-sitemap.xml">
    <style>
        :root { --mor:#5C008E; --mor2:#7B2FB8; }
        * { box-sizing:border-box; }
        body { margin:0; font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif; color:#1f2937; background:#f8f7fb; line-height:1.5; }
        .wrap { max-width:1100px; margin:0 auto; padding:32px 20px 60px; }
        header.hero { background:linear-gradient(135deg,var(--mor) 0%,var(--mor2) 100%); color:#fff; padding:44px 20px; text-align:center; }
        header.hero h1 { margin:0 0 8px; font-size:30px; }
        header.hero p { margin:0; opacity:.9; font-size:15px; }
        .count { display:inline-block; margin-top:14px; background:rgba(255,255,255,.15); padding:6px 16px; border-radius:20px; font-size:13px; font-weight:600; }
        .grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(280px,1fr)); gap:14px; margin-top:26px; }
        a.salon { display:block; text-decoration:none; color:inherit; background:#fff; border:1px solid #ece9f3; border-radius:12px; padding:16px 18px; transition:box-shadow .15s, transform .15s; }
        a.salon:hover { box-shadow:0 10px 26px -12px rgba(92,0,142,.35); transform:translateY(-2px); border-color:var(--mor2); }
        a.salon .ad { display:block; font-weight:600; font-size:16px; color:var(--mor); }
        a.salon .yer { display:block; font-size:13px; color:#6b7280; margin-top:3px; }
        .bos { text-align:center; color:#6b7280; margin-top:40px; }
        footer { text-align:center; color:#9ca3af; font-size:12px; margin-top:40px; }
        footer a { color:var(--mor2); }
    </style>
</head>
<body>
    <header class="hero">
        <h1>Salon Dizini</h1>
        <p>Randevumcepte üzerinden online randevu alabileceğiniz tüm işletmeler</p>
        <span class="count">{{ $salonlar->count() }} işletme</span>
    </header>
    <div class="wrap">
        @if($salonlar->isNotEmpty())
        <div class="grid">
            @foreach($salonlar as $s)
            <a class="salon" href="https://{{ $s->host }}/" rel="noopener">
                <span class="ad">{{ $s->salon_adi }}</span>
                <span class="yer">{{ implode(' / ', array_filter([$s->ilce_adi, $s->il_adi])) ?: 'Türkiye' }}</span>
            </a>
            @endforeach
        </div>
        @else
        <p class="bos">Henüz listelenecek işletme yok.</p>
        @endif
        <footer>
            <a href="https://randevumcepte.com.tr">Randevumcepte</a> &middot; İşletmenizi eklemek için bize ulaşın.
        </footer>
    </div>
</body>
</html>
