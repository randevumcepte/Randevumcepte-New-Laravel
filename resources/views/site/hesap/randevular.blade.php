@extends('site.hesap.layout')
@section('baslik', 'Randevularım')
@section('content')
<div class="profile-card profile-card--list">
   <div class="profile-card-header">
      <h2><i class="fa fa-calendar-check-o"></i> Randevularım</h2>
      <a href="{{ $linkler['ana'] }}#randevu-al" class="profile-action-btn"><i class="fa fa-plus"></i> <span>Yeni Randevu Al</span></a>
   </div>
   <div class="profile-card-body">
      <div class="profile-empty" id="randevuBos" hidden>
         <i class="fa fa-calendar-o"></i>
         <p><strong>Henüz randevunuz yok</strong></p>
         <small>Yeni bir randevu oluşturmak için yukarıdaki butonu kullanabilirsiniz.</small>
      </div>
      <div class="appointment-grid" id="randevuListe"></div>
      <div style="text-align:center; margin-top:16px;">
         <button type="button" id="dahaFazla" class="profile-action-btn" hidden><span>Daha Fazla</span></button>
      </div>
   </div>
</div>
@endsection
