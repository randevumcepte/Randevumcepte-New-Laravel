<!doctype html>
<html lang="tr">
   <head>
      <meta charset="UTF-8">
      <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
      <meta name="robots" content="noindex, nofollow">
      <title>@yield('baslik') | {{ $salon->salon_adi }}</title>
      <link href="https://fonts.googleapis.com/css?family=Poppins:300,400,500,600,700|Varela+Round" rel="stylesheet">
      <link rel="stylesheet" href="{{ asset('site/bootstrap/css/bootstrap.css') }}">
      <link rel="stylesheet" href="{{ asset('site/fonts/font-awesome.css') }}">
      <link rel="stylesheet" href="{{ asset('site/css/style.css') }}?v=1">
      <link rel="stylesheet" href="{{ asset('site/css/user.css') }}">
      <link rel="stylesheet" href="{{ asset('site/css/navigationmobilemenu.css') }}">
      <link rel="stylesheet" href="{{ asset('site/css/modern-profile.css') }}?v=1">
      {!! $tema_css !!}
      <style>.tel-grup{display:flex;gap:8px}.tel-grup .tel-ulke{flex:0 0 auto;max-width:130px}.tel-grup .tel-num{flex:1 1 auto;min-width:0}</style>
   </head>
   <body data-salon-id="{{ $salon->id }}" data-ana="{{ $linkler['ana'] }}" data-giris="{{ $linkler['giris'] }}" data-randevularim="{{ $linkler['randevular'] }}" data-sayfa="{{ $aktif }}">
      <div id="preloader"><div id="loaderstatus">&nbsp;</div></div>
      <div class="page home-page">
         <header class="customer-app-header">
            <div class="customer-app-header-inner container">
               <a href="{{ $linkler['ana'] }}" class="customer-app-logo">
                  <img src="{{ $salon->logo ?: \App\Support\EskiPanelLinki::mutlak('public/img/randevumcepte.jpg') }}" alt="{{ $salon->salon_adi }}">
               </a>
               <div class="customer-user-pill" id="customerUserPill" data-musteri-oturum hidden>
                  <button type="button" class="customer-user-btn" id="customerUserBtn">
                     <span class="customer-user-avatar"><img data-musteri-resim src="{{ \App\Support\EskiPanelLinki::mutlak('public/img/auth.png') }}" alt=""></span>
                     <span class="customer-user-name" data-musteri-ad></span>
                     <i class="fa fa-chevron-down customer-user-caret"></i>
                  </button>
                  <div class="customer-user-menu" id="customerUserMenu">
                     <a href="{{ $linkler['profil'] }}" class="customer-user-item"><i class="fa fa-user"></i> Profilim</a>
                     <a href="{{ $linkler['ana'] }}#randevu-al" class="customer-user-item primary"><i class="fa fa-calendar-plus-o"></i> Randevu Al</a>
                     <hr class="customer-user-divider">
                     <a href="#" class="customer-user-item logout" data-musteri-cikis><i class="fa fa-sign-out"></i> Çıkış Yap</a>
                  </div>
               </div>
            </div>
         </header>
         <section class="content">
            @unless($aktif === 'giris')
            <div class="modern-nav-pills">
               <a href="{{ $linkler['ana'] }}" class="nav-pill-item"><i class="fa fa-home"></i><span>Anasayfa</span></a>
               <a href="{{ $linkler['profil'] }}" class="nav-pill-item {{ $aktif === 'profil' ? 'active' : '' }}"><i class="fa fa-user"></i><span>Profilim</span></a>
               <a href="{{ $linkler['randevular'] }}" class="nav-pill-item {{ $aktif === 'randevular' ? 'active' : '' }}"><i class="fa fa-calendar"></i><span>Randevularım</span></a>
               <a href="{{ $linkler['sifre'] }}" class="nav-pill-item {{ $aktif === 'sifre' ? 'active' : '' }}"><i class="fa fa-lock"></i><span>Şifrem</span></a>
            </div>
            @endunless
            <section class="profile-main-container">
               <div class="container">
                  <div id="hesapBildirim" class="modern-form-error" style="display:none; margin-bottom:16px;" role="alert"></div>
                  @yield('content')
               </div>
            </section>
         </section>
         <footer class="footer">
            <div class="wrapper">
               <div class="container">
                  <p>Her Hakkı Saklıdır. 2018-{{ date('Y') }} © {{ $salon->salon_adi }}.</p>
               </div>
            </div>
         </footer>
      </div>
      <script type="module" src="/js/telefon-ulke.js?v=1"></script>
      <script type="module" src="{{ asset('js/site/hesap.js') }}?v=2"></script>
   </body>
</html>
