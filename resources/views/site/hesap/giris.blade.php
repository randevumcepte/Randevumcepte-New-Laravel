@extends('site.hesap.layout')
@section('baslik', 'Giriş Yap')
@section('content')
<div class="row justify-content-center">
   <div class="col-lg-6 col-md-9">
      <div class="profile-card">
         <div class="profile-card-header"><h2><i class="fa fa-sign-in"></i> <span id="girisBaslik">Giriş Yap</span></h2></div>
         <div class="profile-card-body">
            <form id="girisFormu">
               <input type="text" name="_hp" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px;top:-9999px;opacity:0;height:0;width:0;" aria-hidden="true">
               <div class="modern-form-group" data-kayit-alani hidden>
                  <label for="ad_soyad" class="required"><i class="fa fa-user"></i> Ad Soyad</label>
                  <input id="ad_soyad" type="text" class="modern-form-control" maxlength="100" autocomplete="name">
               </div>
               <div class="modern-form-group">
                  <label for="cep_telefon" class="required"><i class="fa fa-mobile"></i> Cep Telefonu</label>
                  <input type="tel" id="cep_telefon" class="modern-form-control" required>
               </div>
               <div class="modern-form-group" data-giris-alani>
                  <label for="sifre" class="required"><i class="fa fa-lock"></i> Şifre</label>
                  <input id="sifre" type="password" class="modern-form-control" autocomplete="current-password">
               </div>
               <div class="modern-form-group" data-yenile-alani hidden>
                  <label for="sifre_kod" class="required"><i class="fa fa-key"></i> SMS Kodu</label>
                  <input id="sifre_kod" type="text" inputmode="numeric" maxlength="6" class="modern-form-control" autocomplete="one-time-code">
               </div>
               <div class="modern-form-group" data-yenile-alani hidden>
                  <label for="yeni_sifre" class="required"><i class="fa fa-lock"></i> Yeni Şifre</label>
                  <input id="yeni_sifre" type="password" class="modern-form-control" placeholder="En az 6 karakter" autocomplete="new-password">
               </div>
               {{-- Uye Ol: telefona gelen tek kullanimlik kodla kayit/giris (kod istenince gorunur) --}}
               <div class="modern-form-group" id="kayitKodGrup" hidden>
                  <label for="kayit_kod" class="required"><i class="fa fa-key"></i> Doğrulama Kodu</label>
                  <input id="kayit_kod" type="text" inputmode="numeric" maxlength="6" class="modern-form-control" autocomplete="one-time-code">
               </div>
               <label data-kayit-alani hidden style="display:block;margin-bottom:12px;">
                  <input type="checkbox" id="kvkk"> <a href="{{ $linkler['gizlilik'] }}" target="_blank" rel="noopener">Kullanım ve gizlilik koşulları</a>nı okudum, kabul ediyorum.
               </label>
               <div class="btn-submit-wrapper" style="margin-top: 24px;">
                  <button type="submit" class="btn-submit"><span id="girisButon">Giriş Yap</span> <i class="fa fa-check"></i></button>
               </div>
               <p style="margin-top:16px; text-align:center;">
                  <a href="#" id="sifremiUnuttum">Şifremi unuttum</a> ·
                  <a href="#kayit" id="modDegistir">Üye Ol</a>
               </p>
            </form>
         </div>
      </div>
   </div>
</div>
@endsection
