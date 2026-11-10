@extends('site.layout')

{{-- Personel tanıtım sayfası (eski personeldetay_public): salon sitesi düzeninde, renkler salon temasından. --}}
@section('content')
@php
   $_unvan = $personel['uzmanlik'] ?: $personel['unvan'];
   $_ig = \App\Support\SiteSeo::sosyalUrl($personel['instagram'] ?? null, 'https://instagram.com/');
@endphp
<style>
   .perdet{background:var(--slp-bg,#f7f5fb);padding:28px 0 48px}
   .perdet-ust{display:flex;align-items:center;gap:14px;margin-bottom:18px}
   .perdet-ust img{height:44px;width:auto;border-radius:10px}
   .perdet-iz{font-size:13px;color:var(--slp-muted,#6e6b80)}
   .perdet-iz a{color:var(--slp-muted,#6e6b80);text-decoration:none}
   .perdet-iz a:hover{color:var(--slp-brand,#5c008e)}
   .perdet-kart{background:#fff;border-radius:18px;box-shadow:0 10px 30px rgba(var(--slp-brand-rgb,92,0,142),.08);overflow:hidden;display:grid;grid-template-columns:280px 1fr}
   @media (max-width:768px){.perdet-kart{grid-template-columns:1fr}}
   .perdet-resim{background:rgba(var(--slp-brand-rgb,92,0,142),.05);padding:30px;display:flex;align-items:center;justify-content:center}
   .perdet-resim img{width:200px;height:200px;border-radius:50%;object-fit:cover;border:6px solid #fff;box-shadow:0 4px 14px rgba(0,0,0,.1)}
   .perdet-govde{padding:30px}
   .perdet-ad{font-size:28px;font-weight:700;margin:0 0 4px;color:var(--slp-ink,#1c1b29)}
   .perdet-unvan{font-size:15px;color:var(--slp-brand,#5c008e);font-weight:600;margin-bottom:14px}
   .perdet-rozetler{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:18px}
   .perdet-rozet{display:inline-flex;align-items:center;gap:6px;padding:6px 12px;background:rgba(var(--slp-brand-rgb,92,0,142),.07);color:var(--slp-ink-2,#3a384e);border-radius:20px;font-size:13px;text-decoration:none}
   .perdet-rozet i{color:var(--slp-brand,#5c008e)}
   .perdet-rozet.ig{background:linear-gradient(45deg,#f09433,#dc2743 50%,#bc1888);color:#fff}
   .perdet-rozet.ig i{color:#fff}
   .perdet-bio{font-size:15px;line-height:1.7;color:var(--slp-ink-2,#3a384e);background:#fafafc;padding:18px 20px;border-left:3px solid var(--slp-brand,#5c008e);border-radius:0 8px 8px 0}
   .perdet-bio.bos{color:#888;font-style:italic;border-left-color:#ccc}
   .perdet-cta{margin-top:26px}
   .perdet-bolum{margin-top:40px}
   .perdet-bolum h2{font-size:20px;font-weight:700;color:var(--slp-ink,#1c1b29);margin-bottom:18px;padding-bottom:10px;border-bottom:2px solid var(--slp-line,#eeebf4)}
   .perdet-hizmetler{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:12px}
   .perdet-hizmet{background:#fff;padding:14px 16px;border:1px solid var(--slp-line,#eeebf4);border-radius:10px;display:flex;align-items:center;gap:10px}
   .perdet-hizmet i{color:var(--slp-brand,#5c008e)}
   .perdet-digerleri{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:16px}
   .perdet-diger{background:#fff;border:1px solid var(--slp-line,#eeebf4);border-radius:12px;padding:16px;text-align:center;text-decoration:none;color:var(--slp-ink,#1c1b29);transition:transform .2s,box-shadow .2s}
   .perdet-diger:hover{transform:translateY(-3px);box-shadow:0 6px 16px rgba(var(--slp-brand-rgb,92,0,142),.12);color:var(--slp-ink,#1c1b29);text-decoration:none}
   .perdet-diger img{width:72px;height:72px;border-radius:50%;object-fit:cover;margin:0 auto 10px;display:block}
   .perdet-diger .ad{font-weight:600;font-size:14px}
   .perdet-diger .uzmanlik{font-size:11px;color:var(--slp-muted,#6e6b80);margin-top:3px}
</style>

<section class="perdet">
   <div class="container">
      <div class="perdet-ust">
         <a href="{{ $linkler['ana'] }}" aria-label="Anasayfa">
            <img src="{{ $salon->logo ?: \App\Support\EskiPanelLinki::mutlak('public/img/randevumcepte.jpg') }}" alt="{{ $salon->salon_adi }}">
         </a>
         <nav class="perdet-iz" aria-label="Sayfa yolu">
            <a href="{{ $linkler['ana'] }}"><i class="fa fa-chevron-left"></i> {{ $salon->salon_adi }}</a> / <span>{{ $personel['personel_adi'] }}</span>
         </nav>
      </div>

      <div class="perdet-kart">
         <div class="perdet-resim">
            <img src="{{ $resim }}" alt="{{ $personel['personel_adi'] }}">
         </div>
         <div class="perdet-govde">
            <h1 class="perdet-ad">{{ $seo['h1'] }}</h1>
            @if(!empty($_unvan))
               <div class="perdet-unvan">{{ $_unvan }}</div>
            @endif

            <div class="perdet-rozetler">
               @if(!empty($personel['yillik_tecrube']))
                  <span class="perdet-rozet"><i class="fa fa-star"></i> {{ $personel['yillik_tecrube'] }}+ yıl tecrübe</span>
               @endif
               @if(!empty($personel['unvan']) && !empty($personel['uzmanlik']))
                  <span class="perdet-rozet"><i class="fa fa-user"></i> {{ $personel['unvan'] }}</span>
               @endif
               @if($_ig)
                  <a class="perdet-rozet ig" href="{{ $_ig }}" target="_blank" rel="noopener">
                     <i class="fa fa-instagram"></i> {{ '@'.ltrim($personel['instagram'], '@') }}
                  </a>
               @endif
            </div>

            @if(!empty($personel['aciklama']))
               <div class="perdet-bio">{!! nl2br(e($personel['aciklama'])) !!}</div>
            @else
               <div class="perdet-bio bos">Bu personel için henüz detaylı açıklama eklenmemiş.</div>
            @endif

            @if(!empty($salon->musteri_online_randevu_aktif))
            <div class="perdet-cta">
               <a href="{{ $linkler['ana'] }}#randevu-al" class="slp-btn slp-btn--primary"><i class="fa fa-calendar-plus-o"></i> {{ $personel['personel_adi'] }} ile Randevu Al</a>
            </div>
            @endif
         </div>
      </div>

      @if($hizmetler)
      <div class="perdet-bolum">
         <h2><i class="fa fa-check-circle"></i> Sunduğu Hizmetler</h2>
         <div class="perdet-hizmetler">
            @foreach($hizmetler as $h)
               <div class="perdet-hizmet"><i class="fa fa-check"></i> <span>{{ $h }}</span></div>
            @endforeach
         </div>
      </div>
      @endif

      @if($digerleri)
      <div class="perdet-bolum">
         <h2><i class="fa fa-users"></i> Ekibimizden Diğer Kişiler</h2>
         <div class="perdet-digerleri">
            @foreach($digerleri as $dp)
               @php $_dpUnvan = $dp['uzmanlik'] ?: $dp['unvan']; @endphp
               <a class="perdet-diger" href="{{ $salonYolu }}/personel/{{ $dp['id'] }}">
                  <img src="{{ \App\Support\SiteSeo::personelResmi($dp) }}" alt="{{ $dp['personel_adi'] }}" loading="lazy">
                  <div class="ad">{{ $dp['personel_adi'] }}</div>
                  @if(!empty($_dpUnvan))<div class="uzmanlik">{{ $_dpUnvan }}</div>@endif
               </a>
            @endforeach
         </div>
      </div>
      @endif
   </div>
</section>
@endsection
