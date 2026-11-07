<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<title>WhatsApp Kontör</title>
<style>
    * { box-sizing:border-box; -webkit-tap-highlight-color:transparent; }
    body { margin:0; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif; background:#f4f6f9; color:#1f2937; -webkit-font-smoothing:antialiased; }
    .wrap { padding:16px 14px 40px; max-width:640px; margin:0 auto; }

    .wkp-free-banner { display:flex; align-items:center; gap:14px; background:linear-gradient(135deg,#e7f8ef 0%,#d6f3e3 100%); border:1.5px solid #b8ebcf; border-radius:16px; padding:16px 18px; margin-bottom:22px; box-shadow:0 6px 18px rgba(37,211,102,.10); }
    .wkp-free-ic { font-size:30px; line-height:1; flex-shrink:0; }
    .wkp-free-t1 { font-size:15.5px; font-weight:800; color:#12805a; letter-spacing:-.2px; }
    .wkp-free-t2 { font-size:12.5px; color:#3f6b57; margin-top:3px; line-height:1.45; }
    .wkp-free-pill { margin-left:auto; flex-shrink:0; align-self:center; background:#12805a; color:#fff; font-weight:700; font-size:11px; padding:6px 12px; border-radius:20px; white-space:nowrap; }
    @media (max-width:560px){ .wkp-free-banner{ flex-wrap:wrap; } .wkp-free-pill{ margin-left:0; } }

    .wkp-header { text-align:center; margin-bottom:20px; }
    .wkp-header h2 { font-size:22px; font-weight:800; color:#1f2937; margin:0 0 8px; letter-spacing:-.4px; }
    .wkp-header p { color:#6b7280; font-size:13.5px; margin:0 auto; line-height:1.5; }

    .wkp-grid { display:grid; grid-template-columns:repeat(2,1fr); gap:14px; }
    @media (max-width:430px){ .wkp-grid{ grid-template-columns:1fr; } }
    .wkp-card { position:relative; background:#fff; border:1.5px solid #eceff3; border-radius:16px; padding:22px 16px 18px; text-align:center; display:flex; flex-direction:column; }
    .wkp-card.best { border-color:#25D366; box-shadow:0 10px 26px rgba(37,211,102,.18); }
    .wkp-tag { position:absolute; top:-11px; left:50%; transform:translateX(-50%); background:linear-gradient(135deg,#25D366,#12b455); color:#fff; font-size:10.5px; font-weight:800; letter-spacing:.3px; padding:5px 12px; border-radius:20px; white-space:nowrap; box-shadow:0 4px 12px rgba(37,211,102,.4); }
    .wkp-adet { font-size:23px; font-weight:800; color:#111827; letter-spacing:-.5px; }
    .wkp-adet span { font-size:13px; font-weight:600; color:#8a94a6; }
    .wkp-birim { font-size:12px; color:#8a94a6; margin-top:2px; }
    .wkp-fiyat { font-size:30px; font-weight:800; color:#12805a; margin:14px 0 2px; line-height:1; letter-spacing:-1px; }
    .wkp-fiyat small { font-size:15px; font-weight:600; color:#8a94a6; }
    .wkp-tasarruf { display:inline-block; margin:8px auto 0; min-height:22px; font-size:12px; font-weight:700; color:#12805a; background:#e7f8ef; border-radius:20px; padding:4px 11px; }
    .wkp-tasarruf.bos { background:transparent; color:transparent; }
    .wkp-btn2 { margin-top:16px; width:100%; padding:13px; border:0; border-radius:12px; font-weight:700; font-size:14px; cursor:pointer; background:#f3f4f6; color:#374151; }
    .wkp-card.best .wkp-btn2 { background:linear-gradient(135deg,#25D366,#12b455); color:#fff; box-shadow:0 8px 18px rgba(37,211,102,.32); }
    .wkp-btn2:active { transform:scale(.98); }

    .wkp-note { text-align:center; color:#9ca3af; font-size:12px; margin-top:16px; line-height:1.5; }

    /* Modal */
    .wsi-modal { display:none; position:fixed; inset:0; background:rgba(17,24,39,.55); z-index:9999; align-items:center; justify-content:center; padding:18px; }
    .wsi-modal.show { display:flex; }
    .wsi-modal-content { background:#fff; border-radius:16px; width:100%; max-width:440px; padding:20px; box-shadow:0 20px 60px rgba(0,0,0,.3); }
    .wsi-modal-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:8px; }
    .wsi-modal-header h4 { margin:0; font-size:17px; color:#1f2937; }
    .wsi-modal-close { font-size:26px; color:#9ca3af; cursor:pointer; line-height:1; }
</style>
</head>
<body>
@php
    $_kBakiye = (int) ($isletme->whatsapp_kontor ?? 0);
    $_kDonem  = \App\Domain\Whatsapp\WhatsappDurumu::salondan($isletme, date('Y-m-d'))->kontorluDonem;
    $_denemeBitisRaw = $isletme->whatsapp_deneme_bitis ?? '2026-08-31';
    $_denemeBitis = \Carbon\Carbon::parse($_denemeBitisRaw);
    $_aylarTr = [1=>'Ocak',2=>'Şubat',3=>'Mart',4=>'Nisan',5=>'Mayıs',6=>'Haziran',7=>'Temmuz',8=>'Ağustos',9=>'Eylül',10=>'Ekim',11=>'Kasım',12=>'Aralık'];
    $_trTarih = function($t) use ($_aylarTr) { return $t->format('d') . ' ' . $_aylarTr[(int)$t->format('n')] . ' ' . $t->format('Y'); };
    $_denemeBitisMetin = $_trTarih($_denemeBitis);
    $_kontorluBaslangicMetin = $_trTarih($_denemeBitis->copy()->addDay());
@endphp
<div class="wrap">

    @if(!$_kDonem)
    <div class="wkp-free-banner">
        <div class="wkp-free-ic">🎉</div>
        <div>
            <div class="wkp-free-t1">{{ $_denemeBitisMetin }} tarihine kadar WhatsApp tamamen ÜCRETSİZ</div>
            <div class="wkp-free-t2"><b>{{ $_kontorluBaslangicMetin }}</b> tarihinden itibaren kontörlü sisteme geçilecek — <b>1 mesaj = 1 kontör</b>. Şimdiden paketleri inceleyip hazırlanabilirsiniz.</div>
            @if($_kBakiye > 0)
            <div class="wkp-free-t2" style="margin-top:7px;color:#12805a;font-weight:800;">💰 Yüklü kontör bakiyeniz: {{ number_format($_kBakiye,0,',','.') }} — {{ $_kontorluBaslangicMetin }} tarihinde kullanılmaya başlayacak (şu an düşmüyor).</div>
            @endif
        </div>
        <div class="wkp-free-pill">{{ $_kBakiye > 0 ? number_format($_kBakiye,0,',','.').' KONTÖR' : 'ŞU AN ÜCRETSİZ' }}</div>
    </div>
    @else
    <div class="wkp-free-banner" style="{{ $_kBakiye <= 500 ? 'background:linear-gradient(135deg,#fdeaea,#fbdada);border-color:#f5b5b5;' : ($_kBakiye <= 1000 ? 'background:linear-gradient(135deg,#fff4e0,#ffe9c7);border-color:#f5d59a;' : '') }}">
        <div class="wkp-free-ic">{{ $_kBakiye <= 0 ? '⛔' : ($_kBakiye <= 500 ? '⚠️' : '💰') }}</div>
        <div>
            <div class="wkp-free-t1" style="{{ $_kBakiye <= 500 ? 'color:#b91c1c;' : ($_kBakiye <= 1000 ? 'color:#9a6a00;' : '') }}">Kontör bakiyeniz: {{ number_format($_kBakiye,0,',','.') }}</div>
            <div class="wkp-free-t2">
                @if($_kBakiye <= 0)
                    Kontörünüz bitti — hatırlatma ve mesajlar müşteri/danışanlarınıza <b>gitmiyor</b>. Hemen kontör alın.
                @elseif($_kBakiye <= 500)
                    Kontörünüz azaldı, çok yakında bitecek. Mesajlarınız durmadan kontör alın.
                @else
                    1 mesaj = 1 kontör. Bittiğinde WhatsApp mesajları durur.
                @endif
            </div>
        </div>
        <div class="wkp-free-pill" style="{{ $_kBakiye <= 500 ? 'background:#b91c1c;' : ($_kBakiye <= 1000 ? 'background:#9a6a00;' : '') }}">{{ number_format($_kBakiye,0,',','.') }} KONTÖR</div>
    </div>
    @endif

    <div class="wkp-header">
        <h2>WhatsApp Kontör Paketleri</h2>
        <p>1 mesaj = 1 kontör. Randevu hatırlatma, iptal/güncelleme bildirimi ve manuel mesajlar kontörden düşer. Çok alırsanız tanesi daha ucuza gelir.</p>
    </div>

    <div class="wkp-grid">
        <div class="wkp-card">
            <div class="wkp-adet">10.000 <span>kontör</span></div>
            <div class="wkp-birim">tanesi 0,285 TL</div>
            <div class="wkp-fiyat">2.850 <small>TL</small></div>
            <div class="wkp-tasarruf bos">—</div>
            <button class="wkp-btn2" onclick="wpktTalepAc('kontor_10000')">Kontör Al</button>
        </div>
        <div class="wkp-card">
            <div class="wkp-adet">20.000 <span>kontör</span></div>
            <div class="wkp-birim">tanesi 0,265 TL</div>
            <div class="wkp-fiyat">5.300 <small>TL</small></div>
            <div class="wkp-tasarruf">400 TL avantaj</div>
            <button class="wkp-btn2" onclick="wpktTalepAc('kontor_20000')">Kontör Al</button>
        </div>
        <div class="wkp-card">
            <div class="wkp-adet">40.000 <span>kontör</span></div>
            <div class="wkp-birim">tanesi 0,245 TL</div>
            <div class="wkp-fiyat">9.800 <small>TL</small></div>
            <div class="wkp-tasarruf">1.600 TL avantaj</div>
            <button class="wkp-btn2" onclick="wpktTalepAc('kontor_40000')">Kontör Al</button>
        </div>
        <div class="wkp-card">
            <div class="wkp-adet">60.000 <span>kontör</span></div>
            <div class="wkp-birim">tanesi 0,230 TL</div>
            <div class="wkp-fiyat">13.800 <small>TL</small></div>
            <div class="wkp-tasarruf">3.300 TL avantaj</div>
            <button class="wkp-btn2" onclick="wpktTalepAc('kontor_60000')">Kontör Al</button>
        </div>
        <div class="wkp-card">
            <div class="wkp-adet">80.000 <span>kontör</span></div>
            <div class="wkp-birim">tanesi 0,213 TL</div>
            <div class="wkp-fiyat">17.000 <small>TL</small></div>
            <div class="wkp-tasarruf">5.800 TL avantaj</div>
            <button class="wkp-btn2" onclick="wpktTalepAc('kontor_80000')">Kontör Al</button>
        </div>
        <div class="wkp-card best">
            <div class="wkp-tag">⭐ EN AVANTAJLI · %30</div>
            <div class="wkp-adet">100.000 <span>kontör</span></div>
            <div class="wkp-birim">tanesi 0,200 TL</div>
            <div class="wkp-fiyat">20.000 <small>TL</small></div>
            <div class="wkp-tasarruf">8.500 TL avantaj</div>
            <button class="wkp-btn2" onclick="wpktTalepAc('kontor_100000')">Kontör Al</button>
        </div>
    </div>

    <p class="wkp-note">
        Ödeme sonrası kontörünüz manuel olarak yüklenir. "Kontör Al" ile talebinizi bırakın, en kısa sürede sizinle iletişime geçelim.
    </p>
</div>

<div class="wsi-modal" id="wpktTalepModal">
    <div class="wsi-modal-content">
        <div class="wsi-modal-header">
            <h4 id="wpktTalepBaslik">Kontör Talebi</h4>
            <span class="wsi-modal-close" onclick="document.getElementById('wpktTalepModal').classList.remove('show')">×</span>
        </div>
        <div>
            <p style="color:#6c757d;font-size:13.5px;line-height:1.5;margin-top:4px;">Bu paket için talebinizi bırakıyorsunuz. Onaylarsanız yetkilimize bildirim gider ve ödeme + kontör yükleme için sizinle iletişime geçilir.</p>
            <label style="font-size:13px;color:#444;font-weight:600;margin-top:12px;display:block;">İletişim / Not <span style="color:#94a3b8;font-weight:500;">(opsiyonel)</span></label>
            <input type="text" id="wpktIletisim" style="width:100%;padding:11px;border:1px solid #ced4da;border-radius:8px;margin-top:6px;font-size:14px;" placeholder="örn. bugün ödemek istiyorum / 0555 123 45 67">
            <button id="wpktTalepGonder" class="wkp-btn2" style="margin-top:16px;background:linear-gradient(135deg,#25D366,#12b455);color:#fff;">Talep Gönder</button>
            <div id="wpktTalepSonuc" style="margin-top:12px;font-size:13px;"></div>
        </div>
    </div>
</div>

<script>
    var wpktSecilenPaket = null;
    var WKP_PAKETLER = {
        kontor_10000:  { ad:'10.000 Kontör',  fiyat:'2.850 TL' },
        kontor_20000:  { ad:'20.000 Kontör',  fiyat:'5.300 TL' },
        kontor_40000:  { ad:'40.000 Kontör',  fiyat:'9.800 TL' },
        kontor_60000:  { ad:'60.000 Kontör',  fiyat:'13.800 TL' },
        kontor_80000:  { ad:'80.000 Kontör',  fiyat:'17.000 TL' },
        kontor_100000: { ad:'100.000 Kontör', fiyat:'20.000 TL' }
    };

    window.wpktTalepAc = function(key){
        wpktSecilenPaket = key;
        var p = WKP_PAKETLER[key] || { ad:key, fiyat:'' };
        document.getElementById('wpktTalepBaslik').textContent = p.ad + ' — ' + p.fiyat;
        document.getElementById('wpktTalepSonuc').innerHTML = '';
        document.getElementById('wpktIletisim').value = '';
        document.getElementById('wpktTalepModal').classList.add('show');
    };

    document.getElementById('wpktTalepGonder').addEventListener('click', function(){
        var btn = this;
        var sonuc = document.getElementById('wpktTalepSonuc');
        var iletisim = document.getElementById('wpktIletisim').value.trim();
        btn.disabled = true; btn.textContent = 'Gönderiliyor...';

        var bitti = false;
        function finish(mesaj, ok){
            if (bitti) return; bitti = true;
            btn.disabled = false; btn.textContent = 'Talep Gönder';
            sonuc.innerHTML = ok
                ? '<span style="color:#1a7f3e;font-weight:600;">✓ ' + mesaj + '</span>'
                : '<span style="color:#dc3545;">' + mesaj + '</span>';
            if (ok) setTimeout(function(){ document.getElementById('wpktTalepModal').classList.remove('show'); }, 2600);
        }
        var zamanAsimi = setTimeout(function(){
            finish('Talebiniz alındı, en kısa sürede sizinle iletişime geçeceğiz. 🙌', true);
        }, 12000);

        var fd = new FormData();
        fd.append('paket', wpktSecilenPaket);
        fd.append('iletisim', iletisim);
        fetch(@json($talepAdresi), {
            method:'POST', headers:{'Accept':'application/json'}, body:fd
        }).then(function(r){
            return r.text().then(function(t){ var j=null; try{ j=JSON.parse(t); }catch(e){} return {status:r.status, j:j}; });
        }).then(function(res){
            clearTimeout(zamanAsimi);
            if (res.j && res.j.ok)          finish(res.j.mesaj || 'Talebiniz alındı.', true);
            else if (res.j && res.j.mesaj)  finish(res.j.mesaj, false);
            else if (res.status === 404)    finish('Sistem güncelleniyor, birkaç dakika sonra tekrar deneyin.', false);
            else                            finish('Talebiniz alındı, sizinle iletişime geçeceğiz. 🙌', true);
        }).catch(function(){
            clearTimeout(zamanAsimi);
            finish('Talebiniz alındı, sizinle iletişime geçeceğiz. 🙌', true);
        });
    });
</script>
</body>
</html>
