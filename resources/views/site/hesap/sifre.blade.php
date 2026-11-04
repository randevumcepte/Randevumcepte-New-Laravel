@extends('site.hesap.layout')
@section('baslik', 'Şifrem')
@section('content')
<div class="row justify-content-center">
   <div class="col-lg-7 col-md-10">
      <div class="profile-card">
         <div class="profile-card-header"><h2><i class="fa fa-lock"></i> Şifre Değiştir</h2></div>
         <div class="profile-card-body">
            <p class="profile-card-help">Hesabınızın güvenliği için lütfen güçlü bir şifre seçin.</p>
            <form id="sifreFormu">
               <div class="modern-form-group">
                  <label for="mevcut_sifre" class="required"><i class="fa fa-key"></i> Mevcut Şifreniz</label>
                  <input id="mevcut_sifre" type="password" class="modern-form-control" placeholder="••••••••" autocomplete="current-password" required>
               </div>
               <div class="modern-form-group">
                  <label for="yeni_sifre" class="required"><i class="fa fa-lock"></i> Yeni Şifre</label>
                  <input id="yeni_sifre" type="password" class="modern-form-control" placeholder="En az 6 karakter" minlength="6" autocomplete="new-password" required>
               </div>
               <div class="modern-form-group">
                  <label for="yeni_sifre_tekrar" class="required"><i class="fa fa-check-circle"></i> Yeni Şifre (Tekrar)</label>
                  <input id="yeni_sifre_tekrar" type="password" class="modern-form-control" placeholder="Yeni şifreyi tekrar girin" autocomplete="new-password" required>
               </div>
               <div class="btn-submit-wrapper" style="margin-top: 24px;">
                  <button type="submit" class="btn-submit"><span>Şifreyi Değiştir</span> <i class="fa fa-check"></i></button>
               </div>
            </form>
         </div>
      </div>
   </div>
</div>
@endsection
