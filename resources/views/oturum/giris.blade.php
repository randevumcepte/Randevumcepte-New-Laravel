@extends('layout.oturum')

@section('baslik', 'İşletme Yönetim Paneli')

@section('icerik')
    <p class="ot-mesaj" id="girisMesaj" role="alert" hidden></p>

    <form id="kodIsteFormu" novalidate>
        <input class="ot-alan" type="tel" name="cep_telefon" placeholder="Cep Telefonu" autocomplete="tel" required autofocus>
        <button class="ot-dugme" type="submit">KOD GÖNDER</button>
    </form>

    <form id="kodGirisFormu" novalidate hidden>
        <input class="ot-alan" type="text" name="kod" inputmode="numeric" maxlength="6" placeholder="Giriş Kodu" autocomplete="one-time-code" required>
        <button class="ot-metin-dugme" type="button" id="kodYenidenGonder" disabled></button>
        <button class="ot-dugme" type="submit">GİRİŞ YAP</button>
        <button class="ot-metin-dugme" type="button" id="numaraDegistir">Numarayı değiştir</button>
    </form>

    <form id="girisFormu" novalidate hidden>
        <input class="ot-alan" type="text" name="kimlik" placeholder="Cep Telefonu" autocomplete="username" required>
        <div class="ot-sifre">
            <input class="ot-alan" type="password" name="password" placeholder="Şifre" autocomplete="current-password" required>
            <i class="bi bi-eye" data-sifre-goster aria-hidden="true"></i>
        </div>
        <a class="ot-baglanti ot-sag" href="/v2/sifremi-unuttum">Şifremi Unuttum</a>
        <button class="ot-dugme" type="submit">GİRİŞ YAP</button>
    </form>

    <button class="ot-metin-dugme" type="button" id="yontemDegistir">Şifre ile giriş</button>
    <div class="ot-ayrac"><span>VEYA</span></div>
    <p class="ot-alt">Hesabınız yok mu? <a class="ot-baglanti" href="/ucretsiz-denemenizi-baslatin">Hemen kayıt olun!</a></p>
@endsection

@push('betik')
    <script type="module" src="/js/oturum/giris.js?v=3"></script>
@endpush
