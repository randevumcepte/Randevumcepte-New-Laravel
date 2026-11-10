@php
   $_baslik = ucwords($arama_terimleri[0] ?? '');
   $_tamBaslik = trim($_baslik.' | '.$salon->salon_adi, ' |');
   $_siteUrl = 'https://'.($salon->domain ?: request()->getHost());
   $_gorsel = $kapak_gorseli ?: \App\Support\EskiPanelLinki::mutlak('public/img/randevumcepte.jpg');
   $_ldJson = array_filter([
      '@context' => 'https://schema.org', '@type' => 'LocalBusiness', 'name' => $salon->salon_adi,
      'description' => $salon->meta_description, 'url' => $_siteUrl, 'telephone' => $salon->telefon_1,
      'address' => ['@type' => 'PostalAddress', 'streetAddress' => $salon->adres, 'addressCountry' => 'TR'],
      'image' => $kapak_gorseli, 'sameAs' => array_values(array_filter([$salon->facebook_sayfa, $salon->instagram_sayfa])) ?: null,
   ]);
@endphp
<!doctype html>
<html lang="tr">
   <head>
      <meta charset="UTF-8">
      <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
      <meta name="robots" content="{{ $indekslenebilir ? 'index, follow' : 'noindex, nofollow' }}">
      <title>{{ $_tamBaslik }}</title>
      <meta name="description" content="{{ $salon->meta_description }}">
      @if($arama_terimleri)<meta name="keywords" content="{{ implode(',', $arama_terimleri) }}">@endif
      <link rel="canonical" href="{{ $_siteUrl }}">
      <meta property="og:locale" content="tr_TR">
      <meta property="og:site_name" content="{{ $salon->salon_adi }}">
      <meta property="og:url" content="{{ $_siteUrl }}">
      <meta property="og:type" content="website">
      <meta property="og:title" content="{{ $_tamBaslik }}">
      <meta property="og:description" content="{{ $salon->meta_description }}">
      <meta property="og:image" content="{{ $_gorsel }}">
      <meta property="og:image:width" content="1200">
      <meta property="og:image:height" content="630">
      <meta name="twitter:card" content="summary_large_image">
      <meta name="twitter:title" content="{{ $_tamBaslik }}">
      <meta name="twitter:description" content="{{ $salon->meta_description }}">
      <link href="https://fonts.googleapis.com/css?family=Poppins:300,400,500,600,700|Varela+Round" rel="stylesheet">
      <link rel="stylesheet" href="{{ asset('site/bootstrap/css/bootstrap.css') }}">
      <link rel="stylesheet" href="{{ asset('site/fonts/font-awesome.css') }}">
      <link rel="stylesheet" href="{{ asset('site/css/style.css') }}?v=1">
      <link rel="stylesheet" href="{{ asset('site/css/user.css') }}">
      <link rel="stylesheet" href="{{ asset('site/css/randevu-luxe.css') }}?v=1">
      <link rel="stylesheet" href="{{ asset('site/css/salon-landing.css') }}?v=3">
      {!! $tema_css !!}
      <style>.tel-grup{display:flex;gap:8px}.tel-grup .tel-ulke{flex:0 0 auto;max-width:130px}.tel-grup .tel-num{flex:1 1 auto;min-width:0}</style>
      <link rel="stylesheet" href="{{ asset('site/css/navigationmobilemenu.css') }}">
      <script type="application/ld+json">{!! json_encode($_ldJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) !!}</script>
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
      <script src="{{ asset('js/site/vitrin-sayfa.js') }}?v=1"></script>
      <script src="{{ asset('js/site/vitrin-personel.js') }}?v=1"></script>
      <script type="module" src="{{ asset('js/site/vitrin-randevu.js') }}?v=2"></script>
      @if(!empty($cark))<script type="module" src="{{ asset('js/site/vitrin-cark.js') }}?v=1"></script>@endif
      @stack('sayfa')
   </body>
</html>
