@extends('layout.oturum')

@section('baslik', 'Şifremi Unuttum')

@section('icerik')
    <h1 class="ot-baslik">Şifremi unuttum</h1>
    <p class="ot-mesaj" id="sifreMesaj" role="alert" hidden></p>

    <form id="kodFormu" novalidate>
        <input class="ot-alan" type="tel" name="cep_telefon" placeholder="Cep Telefonu" autocomplete="tel" required autofocus>
        <button class="ot-dugme" type="submit">Gönder</button>
    </form>

    <form id="sifreFormu" novalidate hidden>
        <input class="ot-alan" type="text" name="kod" inputmode="numeric" maxlength="4" placeholder="SMS ile gönderilen doğrulama kodu" autocomplete="one-time-code" required>
        <div class="ot-sifre">
            <input class="ot-alan" type="password" name="yeni_sifre" placeholder="Yeni şifre" autocomplete="new-password" required>
            <i class="bi bi-eye" data-sifre-goster aria-hidden="true"></i>
        </div>
        <div class="ot-sifre">
            <input class="ot-alan" type="password" name="yeni_sifre_tekrar" placeholder="Yeni şifre (tekrar)" autocomplete="new-password" required>
            <i class="bi bi-eye" data-sifre-goster aria-hidden="true"></i>
        </div>
        <button class="ot-dugme" type="submit">Şifremi Değiştir</button>
        <button class="ot-metin-dugme" type="button" id="yeniKodIste">Yeni kod iste</button>
    </form>

    <div class="ot-ayrac"><span>VEYA</span></div>
    <p class="ot-alt"><a class="ot-baglanti" href="/v2/giris">Giriş Sayfasına Dön</a></p>
@endsection

@push('betik')
    <script type="module" src="/js/oturum/sifremi-unuttum.js?v=1"></script>
@endpush
