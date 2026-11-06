@extends('layout.oturum')

@section('baslik', 'Sistem Yönetim Paneli')

@section('icerik')
    <form id="girisFormu" novalidate>
        <p class="ot-mesaj" id="girisMesaj" role="alert" hidden></p>
        <input class="ot-alan" type="email" name="email" placeholder="E-posta" autocomplete="username" required autofocus>
        <div class="ot-sifre">
            <input class="ot-alan" type="password" name="password" placeholder="Şifre" autocomplete="current-password" required>
            <i class="bi bi-eye" data-sifre-goster aria-hidden="true"></i>
        </div>
        <button class="ot-dugme" type="submit">GİRİŞ YAP</button>
    </form>
@endsection

@push('betik')
    <script type="module" src="/js/sistem/giris.js?v=1"></script>
@endpush
