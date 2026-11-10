@extends('layout.oturum')

@section('baslik', 'İşletme Yönetim Paneli')

@section('icerik')
    <p class="ot-mesaj" id="girisMesaj" role="alert" hidden></p>

    {{-- Web panel sifreyle girer; kodla (OTP) giris yalniz mobil uygulamada. --}}
    <form id="girisFormu" novalidate>
        <input class="ot-alan" type="text" name="kimlik" placeholder="Cep Telefonu" autocomplete="username" required autofocus>
        <div class="ot-sifre">
            <input class="ot-alan" type="password" name="password" placeholder="Şifre" autocomplete="current-password" required>
            <i class="bi bi-eye" data-sifre-goster aria-hidden="true"></i>
        </div>
        <a class="ot-baglanti ot-sag" href="/v2/sifremi-unuttum">Şifremi Unuttum</a>
        <button class="ot-dugme" type="submit">GİRİŞ YAP</button>
    </form>

    <div class="ot-ayrac"><span>VEYA</span></div>
    <p class="ot-alt">Hesabınız yok mu? <a class="ot-baglanti" href="/ucretsiz-denemenizi-baslatin">Hemen kayıt olun!</a></p>
@endsection

@push('betik')
    <script type="module" src="/js/oturum/giris.js?v=4"></script>
@endpush
