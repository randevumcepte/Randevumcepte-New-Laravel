<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="utf-8">
<style>
   body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #111; margin: 0; padding: 0; }
   .header-isletme { text-align: center; font-size: 12px; font-weight: bold; margin-bottom: 2px; }
   .header-form-adi { text-align: center; font-size: 14px; font-weight: bold; text-transform: uppercase; margin-bottom: 8px; }
   .ust-aciklama { background: #f0f0f0; border: 1px solid #ccc; padding: 6px 8px; font-size: 10px; color: #333; margin-bottom: 10px; font-style: italic; }
   hr { border: 0; border-top: 1px solid #aaa; margin: 6px 0; }
   .info-tablo { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
   .info-tablo td { padding: 3px 4px; font-size: 11px; }
   .info-tablo .etiket { font-weight: bold; width: 22%; }
   .bolum-basligi { background: #d0d0d0; padding: 5px 8px; font-weight: bold; font-size: 12px; text-transform: uppercase; margin: 10px 0 4px 0; }
   .alt-baslik { font-weight: bold; font-size: 11px; text-align: center; margin: 6px 0 4px 0; text-decoration: underline; }
   .metin-blogu { font-size: 10.5px; margin: 4px 0 6px 0; line-height: 1.5; text-align: justify; }
   .madde-listesi { margin: 4px 0 6px 16px; padding: 0; }
   .madde-listesi li { font-size: 10.5px; margin-bottom: 2px; }
   .not-kutusu { background: #f5f5f5; border: 1px solid #bbb; padding: 6px 8px; font-size: 10px; color: #333; margin: 6px 0; }
   .bilgi-metni { background: #f5f5f5; padding: 5px 8px; font-size: 10px; color: #444; margin: 4px 0; }
   .eh-tablo { width: 100%; border-collapse: collapse; margin-bottom: 2px; }
   .eh-tablo tr td { padding: 3px 4px; font-size: 10.5px; vertical-align: middle; border-bottom: 1px dotted #ddd; }
   .eh-tablo tr td:first-child { width: 78%; }
   .eh-tablo tr td:last-child { width: 22%; text-align: right; white-space: nowrap; }
   .metin-soru-blok { margin-bottom: 6px; border-bottom: 1px dotted #ddd; padding-bottom: 4px; }
   .metin-soru-metin { font-weight: bold; font-size: 10.5px; }
   .metin-soru-cevap { font-size: 10.5px; padding-left: 8px; color: #222; margin-top: 2px; }
   .imza-alani { margin-top: 20px; }
   .imza-kutu { border: 1px dashed #888; height: 70px; text-align: center; vertical-align: middle; }
</style>
</head>
<body>

<div class="header-isletme">{{ $isletme->salon_adi }}</div>
<div class="header-form-adi">{{ $form_adi }}</div>
<hr>

@if($aciklama)
<div class="ust-aciklama">{{ $aciklama }}</div>
@endif

<table class="info-tablo">
   <tr>
      <td class="etiket">Ad Soyad:</td>
      <td>{{ $arsiv->musteri ? $arsiv->musteri->name : '-' }}</td>
      <td class="etiket">Telefon:</td>
      <td>{{ $arsiv->musteri ? $arsiv->musteri->cep_telefon : '-' }}</td>
   </tr>
   <tr>
      <td class="etiket">Tarih:</td>
      <td>{{ $arsiv->created_at ? date('d.m.Y H:i', strtotime($arsiv->created_at)) : '-' }}</td>
      <td class="etiket">Personel:</td>
      <td>{{ $arsiv->personel ? $arsiv->personel->adi_soyadi : '-' }}</td>
   </tr>
</table>

<hr>

@php
   $elemanlar = [];
   foreach ($sorular as $idx => $soru) {
      $elemanlar[] = ['idx' => $idx, 'soru' => $soru];
   }
   $toplam = count($elemanlar);
   $i = 0;
@endphp

@while($i < $toplam)
@php $item = $elemanlar[$i]; $soru = $item['soru']; $idx = $item['idx']; $tip = $soru['tip']; @endphp

@if($tip === 'bolum_basligi')
   <div class="bolum-basligi">{{ $soru['soru'] }}</div>
   @php $i++; @endphp

@elseif($tip === 'alt_baslik')
   <div class="alt-baslik">{{ $soru['soru'] }}</div>
   @php $i++; @endphp

@elseif($tip === 'metin_blogu')
   <div class="metin-blogu">{{ $soru['soru'] }}</div>
   @php $i++; @endphp

@elseif($tip === 'madde_listesi')
   <ul class="madde-listesi">
      @foreach(array_filter(array_map('trim', explode("\n", $soru['soru']))) as $madde)
         <li>{{ $madde }}</li>
      @endforeach
   </ul>
   @php $i++; @endphp

@elseif($tip === 'not_kutusu')
   <div class="not-kutusu">{{ $soru['soru'] }}</div>
   @php $i++; @endphp

@elseif($tip === 'bilgi_metni')
   <div class="bilgi-metni">{{ $soru['soru'] }}</div>
   @php $i++; @endphp

@elseif($tip === 'musteri_bilgi_tablosu')
   <table style="width:100%; border-collapse:collapse; margin:6px 0; font-size:10.5px;">
      <tr><td style="padding:4px; background:#f5f5f5; width:35%; font-weight:bold;">Ad Soyad:</td><td style="padding:4px;">{{ $arsiv->musteri ? $arsiv->musteri->name : '-' }}</td></tr>
      <tr><td style="padding:4px; background:#f5f5f5; font-weight:bold;">Telefon:</td><td style="padding:4px;">{{ $arsiv->musteri ? $arsiv->musteri->cep_telefon : '-' }}</td></tr>
      <tr><td style="padding:4px; background:#f5f5f5; font-weight:bold;">Tarih:</td><td style="padding:4px;">{{ $arsiv->created_at ? date('d.m.Y H:i', strtotime($arsiv->created_at)) : '-' }}</td></tr>
   </table>
   @php $i++; @endphp

@elseif($tip === 'hizmet_paket_bilgisi')
   @php
      $h_adi = null; $p_adi = null;
      if($arsiv->hizmet_id){
         try { $sh = \DB::table('salon_sunulan_hizmetler')->leftJoin('hizmetler','salon_sunulan_hizmetler.hizmet_id','=','hizmetler.id')->where('salon_sunulan_hizmetler.id',$arsiv->hizmet_id)->select('hizmetler.hizmet_adi')->first(); $h_adi = $sh ? $sh->hizmet_adi : null; } catch(\Exception $e){}
      }
      if($arsiv->paket_id){
         try { $p_adi = \DB::table('paketler')->where('id',$arsiv->paket_id)->value('paket_adi'); } catch(\Exception $e){}
      }
   @endphp
   <div style="background:#fff8e1; border:1px solid #d4a017; padding:6px 8px; margin:6px 0; font-size:10.5px;">
      @if($h_adi)<b>Hizmet:</b> {{ $h_adi }}<br>@endif
      @if($p_adi)<b>Paket:</b> {{ $p_adi }}@endif
   </div>
   @php $i++; @endphp

@elseif($tip === 'ucret_bilgisi')
   <div style="background:#e7f5ff; border:1px solid #0dcaf0; padding:6px 8px; margin:6px 0; font-size:10.5px;">
      <b>Toplam Ucret:</b> {{ number_format($arsiv->toplam_ucret ?? 0, 2, ',', '.') }} TL
      @if(($arsiv->kapora ?? 0) > 0)<br>
      <b>Kapora / On Odeme:</b> {{ number_format($arsiv->kapora, 2, ',', '.') }} TL<br>
      <b>Kalan Bakiye:</b> {{ number_format(($arsiv->toplam_ucret - $arsiv->kapora), 2, ',', '.') }} TL
      @endif
   </div>
   @php $i++; @endphp

@elseif($tip === 'seans_bilgisi')
   <div style="background:#f5f5f5; border:1px solid #ccc; padding:6px 8px; margin:6px 0; font-size:10.5px;">
      <b>Seans Sayisi:</b> {{ $arsiv->seans_sayisi ?? '-' }}
   </div>
   @php $i++; @endphp

@elseif($tip === 'tarih_yer')
   <div style="background:#f5f5f5; border:1px solid #ccc; padding:6px 8px; margin:6px 0; font-size:10.5px;">
      <b>Tarih:</b> {{ $arsiv->created_at ? date('d.m.Y H:i', strtotime($arsiv->created_at)) : date('d.m.Y H:i') }}<br>
      <b>Isletme:</b> {{ $isletme->salon_adi }}
      @if(!empty($isletme->adres))<br><b>Adres:</b> {{ $isletme->adres }}@endif
   </div>
   @php $i++; @endphp

@elseif($tip === 'evet_hayir')
   <table class="eh-tablo">
   @while($i < $toplam && $elemanlar[$i]['soru']['tip'] === 'evet_hayir')
      @php $ehItem = $elemanlar[$i]; $ehIdx = $ehItem['idx']; $ehSoru = $ehItem['soru']; @endphp
      <tr>
         <td>&#9702; {{ $ehSoru['soru'] }}</td>
         <td>
            @if(isset($cevaplar[$ehIdx]))
               @if($cevaplar[$ehIdx] === 'evet')
                  <b style="color:#27ae60;">&#9745; Evet</b>&nbsp;&#9744; Hayir
               @elseif($cevaplar[$ehIdx] === 'hayir')
                  &#9744; Evet&nbsp;<b style="color:#c0392b;">&#9745; Hayir</b>
               @else
                  &#9744; Evet&nbsp;&#9744; Hayir
               @endif
            @else
               &#9744; Evet&nbsp;&#9744; Hayir
            @endif
         </td>
      </tr>
      @php $i++; @endphp
   @endwhile
   </table>

@elseif($tip === 'checkbox_grup')
   @php $secilenler = isset($cevaplar[$idx]) ? array_map('trim', explode('|', $cevaplar[$idx])) : []; @endphp
   @foreach(array_filter(array_map('trim', explode("\n", $soru['soru']))) as $secenek)
      <div style="font-size:10.5px; margin:2px 0;">
         @if(in_array($secenek, $secilenler))<b style="color:#27ae60;">&#9745;</b>@else &#9744; @endif {{ $secenek }}
      </div>
   @endforeach
   @php $i++; @endphp

@elseif($tip === 'onay_kutusu')
   <div style="font-size:10.5px; margin:6px 0; font-weight:bold;">
      @if(!empty($cevaplar[$idx]))<b style="color:#27ae60;">&#9745;</b>@else &#9744; @endif {{ $soru['soru'] }}
   </div>
   @php $i++; @endphp

@elseif($tip === 'metin' || $tip === 'uzun_metin' || $tip === 'metin_ops')
   <div class="metin-soru-blok">
      <div class="metin-soru-metin">{{ $soru['soru'] }}</div>
      <div class="metin-soru-cevap">{{ isset($cevaplar[$idx]) ? $cevaplar[$idx] : '-' }}</div>
   </div>
   @php $i++; @endphp

@else
   @php $i++; @endphp
@endif

@endwhile

<div class="imza-alani">
   <table width="100%">
      <tr>
         <td style="width:55%; vertical-align:bottom;">
            <table width="100%"><tr><td class="imza-kutu">
               @if($arsiv->musteri_imza && strpos($arsiv->musteri_imza, 'data:') === 0)
                  <img src="{{ $arsiv->musteri_imza }}" style="max-height:65px; max-width:180px;">
               @else
                  <span style="color:#aaa; font-size:10px;">Imza</span>
               @endif
            </td></tr></table>
            <div style="text-align:center; font-size:10px; margin-top:3px;">Musteri Imzasi</div>
         </td>
         <td style="width:45%; font-size:9px; color:#555; padding-left:15px; vertical-align:bottom;">
            <p style="margin:2px 0;">Tarih: {{ $arsiv->created_at ? date('d.m.Y', strtotime($arsiv->created_at)) : '' }}</p>
            @if($arsiv->imza_zaman)
            <p style="margin:2px 0;">Imza Zamani: {{ date('d.m.Y H:i:s', strtotime($arsiv->imza_zaman)) }}</p>
            @endif
            @if($arsiv->imza_ip)<p style="margin:2px 0;">IP: {{ $arsiv->imza_ip }}</p>@endif
            @if($arsiv->imza_cihaz)<p style="margin:2px 0; font-size:8px; color:#777;">Cihaz: {{ \Illuminate\Support\Str::limit($arsiv->imza_cihaz, 80) }}</p>@endif
            @if($arsiv->kvkk_onay)<p style="margin:2px 0; color:#28a745;"><b>&#10003; KVKK Onayi Verildi</b></p>@endif
            <p style="margin-top:6px; padding:4px 8px; background:#fff8dc; border:1px solid #d4a017; border-radius:3px; font-size:10px; color:#333;">
               <b>SMS Onay Kodu:</b> <span style="font-family:'DejaVu Sans Mono', monospace; letter-spacing:2px; font-weight:bold;">{{ $arsiv->dogrulama_kodu ?? '-' }}</span>
            </p>
         </td>
      </tr>
   </table>
</div>

</body>
</html>
