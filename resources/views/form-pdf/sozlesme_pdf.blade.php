<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="utf-8">
<style>
   body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #111; }
   .header-isletme { text-align:center; font-size:12px; font-weight:bold; }
   .header-form-adi { text-align:center; font-size:14px; font-weight:bold; text-transform:uppercase; margin-bottom:10px; text-decoration:underline; }
   hr { border:0; border-top:1px solid #aaa; margin:6px 0; }
   .bilgi-tablo { width:100%; border-collapse:collapse; margin-bottom:14px; }
   .bilgi-tablo td { padding:5px 8px; font-size:11px; border-bottom:1px solid #eee; }
   .bilgi-tablo td.etiket { font-weight:bold; width:35%; background:#f5f5f5; }
   .sozlesme-metni { font-size:11px; line-height:1.7; text-align:justify; margin:14px 0; padding:10px; border:1px solid #ddd; background:#fafafa; }
   .imza-kutu { border:1px dashed #888; height:80px; text-align:center; vertical-align:middle; }
</style>
</head>
<body>
<div class="header-isletme">{{ $isletme->salon_adi }}</div>
<div class="header-form-adi">HİZMET SÖZLEŞMESİ</div>
<hr>

<table class="bilgi-tablo">
   <tr><td class="etiket">Müşteri Ad Soyad</td><td>{{ $arsiv->musteri ? $arsiv->musteri->name : '-' }}</td></tr>
   <tr><td class="etiket">Telefon</td><td>{{ $arsiv->musteri ? $arsiv->musteri->cep_telefon : '-' }}</td></tr>
   <tr><td class="etiket">Sözleşme Tarihi</td><td>{{ $arsiv->created_at ? date('d.m.Y H:i', strtotime($arsiv->created_at)) : '-' }}</td></tr>
   @if($hizmet_adi)<tr><td class="etiket">Hizmet</td><td>{{ $hizmet_adi }}</td></tr>@endif
   @if($paket_adi)<tr><td class="etiket">Paket</td><td>{{ $paket_adi }}</td></tr>@endif
   @if($arsiv->seans_sayisi)<tr><td class="etiket">Seans Sayisi</td><td>{{ $arsiv->seans_sayisi }}</td></tr>@endif
   <tr><td class="etiket">Toplam Ucret</td><td><b>{{ number_format($arsiv->toplam_ucret ?? 0, 2, ',', '.') }} TL</b></td></tr>
   @if($arsiv->kapora > 0)
   <tr><td class="etiket">Kapora / On Odeme</td><td>{{ number_format($arsiv->kapora, 2, ',', '.') }} TL</td></tr>
   <tr><td class="etiket">Kalan Bakiye</td><td><b>{{ number_format(($arsiv->toplam_ucret - $arsiv->kapora), 2, ',', '.') }} TL</b></td></tr>
   @endif
   @php $odemeSekliEtiket = ['pesin'=>'Pesin','taksit'=>'Taksitli','kredi_karti'=>'Kredi Karti']; @endphp
   @if(!empty($arsiv->odeme_sekli))<tr><td class="etiket">Odeme Sekli</td><td>{{ $odemeSekliEtiket[$arsiv->odeme_sekli] ?? $arsiv->odeme_sekli }}</td></tr>@endif
   @if(!empty($arsiv->gecerlilik_tarihi))<tr><td class="etiket">Gecerlilik Tarihi</td><td>{{ date('d.m.Y', strtotime($arsiv->gecerlilik_tarihi)) }}</td></tr>@endif
</table>

@php
   $odemePlani = [];
   if(!empty($arsiv->odeme_plani_json)){ try { $odemePlani = json_decode($arsiv->odeme_plani_json, true) ?: []; } catch(\Exception $e){ $odemePlani = []; } }
@endphp
@if(count($odemePlani) > 0)
<div style="font-size:12px; font-weight:bold; margin:6px 0 4px;">ODEME PLANI ({{ $arsiv->taksit_sayisi }} Taksit)</div>
<table class="bilgi-tablo" style="margin-bottom:14px;">
   <tr style="background:#f5f5f5;"><td class="etiket" style="width:15%;">#</td><td class="etiket">Vade Tarihi</td><td class="etiket" style="text-align:right;">Tutar</td></tr>
   @foreach($odemePlani as $t)
   <tr>
      <td>{{ $t['sira'] ?? '-' }}</td>
      <td>{{ !empty($t['tarih']) ? date('d.m.Y', strtotime($t['tarih'])) : '-' }}</td>
      <td style="text-align:right;"><b>{{ number_format($t['tutar'] ?? 0, 2, ',', '.') }} TL</b></td>
   </tr>
   @endforeach
</table>
@endif

<div class="sozlesme-metni">
<b>SOZLESME SARTLARI:</b><br>
@if($arsiv->sozlesme_metni)
   {!! nl2br(e($arsiv->sozlesme_metni)) !!}
@else
   1. Bu sozlesme <b>{{ $isletme->salon_adi }}</b> ile <b>{{ $arsiv->musteri ? $arsiv->musteri->name : '-' }}</b> arasinda akdedilmistir.<br>
   2. Hizmet bedeli toplam <b>{{ number_format($arsiv->toplam_ucret ?? 0, 2, ',', '.') }} TL</b>'dir.
@endif
@if($arsiv->sozlesme_notu)<br><br><b>Ek Not:</b> {{ $arsiv->sozlesme_notu }}@endif
</div>

<table width="100%" style="margin-top:20px;">
   <tr>
      <td style="width:50%; vertical-align:top; padding-right:8px;">
         <table width="100%"><tr><td class="imza-kutu">
            @if($arsiv->salon_imza && strpos($arsiv->salon_imza,'data:') === 0)
               <img src="{{ $arsiv->salon_imza }}" style="max-height:75px; max-width:200px;">
            @else
               <span style="color:#aaa; font-size:10px;">Imza</span>
            @endif
         </td></tr></table>
         <div style="text-align:center; font-size:10px; margin-top:3px;"><b>Salon Yetkilisi Imzasi</b></div>
         <div style="font-size:9px; color:#555; margin-top:4px;">
            @if($arsiv->salon_yetkili_ad)<p style="margin:2px 0;">Ad Soyad: {{ $arsiv->salon_yetkili_ad }}</p>@endif
            @if($arsiv->salon_yetkili_telefon)<p style="margin:2px 0;">Telefon: {{ $arsiv->salon_yetkili_telefon }}</p>@endif
            @if($arsiv->salon_imza_zaman)<p style="margin:2px 0;">Imza Zamani: {{ date('d.m.Y H:i:s', strtotime($arsiv->salon_imza_zaman)) }}</p>@endif
            @if($arsiv->salon_imza_ip)<p style="margin:2px 0;">IP: {{ $arsiv->salon_imza_ip }}</p>@endif
         </div>
      </td>
      <td style="width:50%; vertical-align:top; padding-left:8px;">
         <table width="100%"><tr><td class="imza-kutu">
            @if($arsiv->musteri_imza && strpos($arsiv->musteri_imza,'data:') === 0)
               <img src="{{ $arsiv->musteri_imza }}" style="max-height:75px; max-width:200px;">
            @else
               <span style="color:#aaa; font-size:10px;">Imza (bekleniyor)</span>
            @endif
         </td></tr></table>
         <div style="text-align:center; font-size:10px; margin-top:3px;"><b>Musteri Imzasi</b></div>
         <div style="font-size:9px; color:#555; margin-top:4px;">
            @if($arsiv->musteri)<p style="margin:2px 0;">Ad Soyad: {{ $arsiv->musteri->name }}</p>@endif
            @if($arsiv->imza_zaman)<p style="margin:2px 0;">Imza Zamani: {{ date('d.m.Y H:i:s', strtotime($arsiv->imza_zaman)) }}</p>@endif
            @if($arsiv->imza_ip)<p style="margin:2px 0;">IP: {{ $arsiv->imza_ip }}</p>@endif
            @if($arsiv->imza_cihaz)<p style="margin:2px 0; font-size:8px; color:#777;">Cihaz: {{ \Illuminate\Support\Str::limit($arsiv->imza_cihaz, 60) }}</p>@endif
            @if($arsiv->kvkk_onay)<p style="margin:2px 0; color:#28a745;"><b>&#10003; KVKK Onayi Verildi</b></p>@endif
            <p style="margin-top:4px; padding:3px 6px; background:#fff8dc; border:1px solid #d4a017; font-size:9px;">
               <b>SMS Onay Kodu:</b> <span style="letter-spacing:2px; font-weight:bold;">{{ $arsiv->dogrulama_kodu ?? '-' }}</span>
            </p>
         </div>
      </td>
   </tr>
</table>

</body>
</html>
