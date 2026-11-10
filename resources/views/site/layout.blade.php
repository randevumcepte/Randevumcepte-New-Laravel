{{-- Baslik, aciklama, h1, canonical ve JSON-LD sayfaya gore App\Support\SiteSeo'dan ($seo) gelir. --}}
<!doctype html>
<html lang="tr">
   <head>
      <meta charset="UTF-8">
      <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
      @if($indekslenebilir)
      <meta name="robots" content="index, follow">
      <meta name="googlebot" content="index, follow">
      <link rel="sitemap" type="application/xml" title="Sitemap" href="/sitemap.xml">
      @else
      <meta name="robots" content="noindex, nofollow">
      @endif
      <title>{{ $seo['baslik'] }}</title>
      <meta name="description" content="{{ $seo['aciklama'] }}">
      @if($seo['anahtar_kelimeler'])<meta name="keywords" content="{{ implode(',', $seo['anahtar_kelimeler']) }}">@endif
      <link rel="canonical" href="{{ $seo['canonical'] }}">
      <meta property="og:locale" content="tr_TR">
      <meta property="og:site_name" content="{{ $salon->salon_adi }}">
      <meta property="og:url" content="{{ $seo['canonical'] }}">
      <meta property="og:type" content="{{ $seo['og_tur'] }}">
      <meta property="og:title" content="{{ $seo['baslik'] }}">
      <meta property="og:description" content="{{ $seo['aciklama'] }}">
      <meta property="og:image" content="{{ $seo['gorsel'] }}">
      <meta property="og:image:width" content="1200">
      <meta property="og:image:height" content="630">
      <meta name="twitter:card" content="summary_large_image">
      <meta name="twitter:title" content="{{ $seo['baslik'] }}">
      <meta name="twitter:description" content="{{ $seo['aciklama'] }}">
      <meta name="twitter:image" content="{{ $seo['gorsel'] }}">
      <link href="https://fonts.googleapis.com/css?family=Poppins:300,400,500,600,700|Varela+Round" rel="stylesheet">
      <link rel="stylesheet" href="{{ asset('site/bootstrap/css/bootstrap.css') }}">
      <link rel="stylesheet" href="{{ asset('site/fonts/font-awesome.css') }}">
      <link rel="stylesheet" href="{{ asset('site/css/style.css') }}?v=1">
      <link rel="stylesheet" href="{{ asset('site/css/user.css') }}">
      <link rel="stylesheet" href="{{ asset('site/css/randevu-luxe.css') }}?v=1">
      <link rel="stylesheet" href="{{ asset('site/css/salon-landing.css') }}?v=4">
      {!! $tema_css !!}
      <style>.tel-grup{display:flex;gap:8px}.tel-grup .tel-ulke{flex:0 0 auto;max-width:130px}.tel-grup .tel-num{flex:1 1 auto;min-width:0}</style>
      <link rel="stylesheet" href="{{ asset('site/css/navigationmobilemenu.css') }}">
      <script type="application/ld+json">{!! json_encode($seo['ld_json'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) !!}</script>
   </head>
   <body data-salon-id="{{ $salon->id }}" data-randevularim="{{ $linkler['randevular'] }}" data-ana="{{ $linkler['ana'] }}">
      <div id="preloader"><div id="loaderstatus">&nbsp;</div></div>
      <div class="page home-page">
         <section class="content">
            @yield('content')
         </section>
         <footer class="footer">
            <div class="wrapper">
               <div class="container">
                  <div class="row">
                     <div class="col-md-12">
                        <p style="text-align: center;">
                           Her Hakkı Saklıdır. 2018-{{ date('Y') }} © Tasarlayan
                           <a href="#" target="_blank"><img src="{{ \App\Support\EskiPanelLinki::mutlak('public/yeni_panel/vendors/images/randevumcepte.png') }}" style="height: 30px;" alt="Randevumcepte"></a>
                        </p>
                     </div>
                  </div>
               </div>
               <div class="background">
                  <div class="background-image original-size">
                     <img src="{{ \App\Support\EskiPanelLinki::mutlak('public/img/randevumcepte.jpg') }}" alt="">
                  </div>
               </div>
            </div>
         </footer>
      </div>
      <script src="{{ asset('site/js/jquery-3.3.1.min.js') }}"></script>
      <script src="{{ asset('site/js/popper.min.js') }}"></script>
      <script src="{{ asset('site/bootstrap/js/bootstrap.min.js') }}"></script>
      <script src="{{ asset('site/js/icheck.min.js') }}"></script>
      <script type="module" src="/js/telefon-ulke.js?v=1"></script>
      {{-- Personel sayfasi gibi vitrin olmayan sayfalar vitrinBetikleri=false verir. --}}
      @if($vitrinBetikleri ?? true)
      <script src="{{ asset('js/site/vitrin-sayfa.js') }}?v=1"></script>
      <script src="{{ asset('js/site/vitrin-personel.js') }}?v=1"></script>
      <script type="module" src="{{ asset('js/site/vitrin-randevu.js') }}?v=3"></script>
      @if(!empty($cark))<script type="module" src="{{ asset('js/site/vitrin-cark.js') }}?v=1"></script>@endif
      @endif
      @stack('sayfa')
   </body>
</html>
