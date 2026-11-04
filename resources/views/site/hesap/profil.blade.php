@extends('site.hesap.layout')
@section('baslik', 'Profilim')
@section('content')
<form class="form" id="profilFormu">
   <div class="row">
      <div class="col-lg-8 col-md-7">
         <div class="profile-card">
            <div class="profile-card-header"><h2><i class="fa fa-id-card"></i> Profil Bilgilerim</h2></div>
            <div class="profile-card-body">
               <div class="profile-form-row">
                  <div class="modern-form-group">
                     <label for="name" class="required"><i class="fa fa-user"></i> Ad Soyad</label>
                     <input name="name" type="text" class="modern-form-control" id="name" placeholder="Adınız ve Soyadınız" required>
                  </div>
                  <div class="modern-form-group">
                     <label for="cinsiyet"><i class="fa fa-venus-mars"></i> Cinsiyet</label>
                     <select name="cinsiyet" class="modern-form-control" id="cinsiyet">
                        <option value="0">Kadın</option>
                        <option value="1">Erkek</option>
                     </select>
                  </div>
               </div>
               <div class="profile-form-row">
                  <div class="modern-form-group">
                     <label for="cep_telefon" class="required"><i class="fa fa-mobile"></i> Cep Telefonu</label>
                     <input name="cep_telefon" type="tel" class="modern-form-control" id="cep_telefon" placeholder="5XX XXX XX XX" required>
                  </div>
                  <div class="modern-form-group">
                     <label for="email"><i class="fa fa-envelope"></i> E-posta</label>
                     <input name="email" type="email" class="modern-form-control" id="email" placeholder="ornek@email.com">
                  </div>
               </div>
               <div class="profile-form-row">
                  <div class="modern-form-group">
                     <label for="ev_telefon"><i class="fa fa-phone"></i> Ev Telefonu</label>
                     <input name="ev_telefon" type="tel" class="modern-form-control" id="ev_telefon" placeholder="0XXX XXX XX XX">
                  </div>
                  <div class="modern-form-group">
                     <label for="dogum_tarihi"><i class="fa fa-birthday-cake"></i> Doğum Tarihi</label>
                     <input name="dogum_tarihi" type="text" class="modern-form-control" id="dogum_tarihi" placeholder="GG.AA.YYYY" inputmode="numeric" maxlength="10">
                  </div>
               </div>
            </div>
         </div>
      </div>
      <div class="col-lg-4 col-md-5">
         <div class="profile-card">
            <div class="profile-card-header"><h2><i class="fa fa-camera"></i> Profil Resmi</h2></div>
            <div class="profile-card-body">
               <div class="profile-image-section">
                  <div class="profile-image-wrapper">
                     <div class="profile-image-container">
                        <img id="profilResim" src="{{ \App\Support\EskiPanelLinki::mutlak('public/img/author-09.jpg') }}" data-varsayilan="{{ \App\Support\EskiPanelLinki::mutlak('public/img/author-09.jpg') }}" alt="Profil resmi">
                     </div>
                     <div class="profile-image-badge"><i class="fa fa-star"></i></div>
                  </div>
                  <div class="profile-image-actions">
                     <div class="file-input-wrapper">
                        <input type="file" id="profil_resim" accept="image/*">
                        <button type="button" class="btn-upload"><i class="fa fa-upload"></i> <span>Resim Yükle</span></button>
                     </div>
                     <a href="#" id="resimSil" class="btn-delete"><i class="fa fa-trash"></i> <span>Resmi Sil</span></a>
                  </div>
               </div>
            </div>
         </div>
      </div>
   </div>
   <div class="row">
      <div class="col-12">
         <div class="btn-submit-wrapper">
            <button type="submit" class="btn-submit"><span>Bilgileri Güncelle</span> <i class="fa fa-check"></i></button>
         </div>
      </div>
   </div>
</form>
@endsection
