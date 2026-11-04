@extends('layout.oturum')

@section('baslik', 'İşletme Yönetim Paneli')

@section('icerik')
    <form id="girisFormu" novalidate>
        <p class="ot-mesaj" id="girisMesaj" role="alert" hidden></p>
        <input class="ot-alan" type="text" name="kimlik" placeholder="Cep Telefonu" autocomplete="username" required autofocus>
        <div class="ot-sifre">
            <input class="ot-alan" type="password" name="password" placeholder="Şifre" autocomplete="current-password" required>
            <i class="bi bi-eye" data-sifre-goster aria-hidden="true"></i>
        </div>
        <a class="ot-baglanti ot-sag" href="/v2/sifremi-unuttum">Şifremi Unuttum</a>
        <button class="ot-dugme" type="submit">GİRİŞ YAP</button>
        <div class="ot-ayrac"><span>VEYA</span></div>
        <p class="ot-alt">Hesabınız yok mu? <a class="ot-baglanti" href="/ucretsiz-denemenizi-baslatin">Hemen kayıt olun!</a></p>
    </form>
@endsection

@push('betik')
    <script type="module" src="/js/oturum/giris.js?v=2"></script>
@endpush
