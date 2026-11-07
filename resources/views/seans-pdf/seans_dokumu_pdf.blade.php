<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="utf-8">
<style>
    @page { margin: 22px 26px; }
    * { box-sizing: border-box; }
    body {
        font-family: DejaVu Sans, sans-serif;
        color: #1f2937;
        font-size: 11px;
        margin: 0;
        padding: 0;
    }
    .hdr { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
    .hdr td { vertical-align: middle; }
    .hdr .logo { width: 58px; }
    .hdr .logo img { width: 54px; height: 54px; object-fit: contain; }
    .salon-adi { font-size: 16px; font-weight: bold; color: #5C008E; }
    .salon-alt { font-size: 10px; color: #6b7280; margin-top: 2px; }
    .baslik-kutu {
        text-align: right;
    }
    .baslik-kutu .rozet {
        display: inline-block;
        background: #5C008E;
        color: #fff;
        font-size: 13px;
        font-weight: bold;
        letter-spacing: .5px;
        padding: 7px 16px;
        border-radius: 6px;
    }
    .cizgi { height: 3px; background: #5C008E; border-radius: 2px; margin-bottom: 12px; }

    .bilgi { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
    .bilgi td {
        padding: 6px 10px;
        border: 1px solid #ead4ff;
        font-size: 11px;
        background: #faf7fe;
    }
    .bilgi .lbl { color: #6b7280; font-weight: bold; width: 90px; font-size: 9.5px; text-transform: uppercase; letter-spacing: .3px; }
    .bilgi .val { color: #1f2937; font-weight: bold; }

    .bolge-baslik {
        margin: 14px 0 0;
        padding: 7px 12px;
        background: #ead4ff;
        color: #4a0072;
        font-size: 12px;
        font-weight: bold;
        border-radius: 6px 6px 0 0;
        border: 1px solid #d9b8f5;
        border-bottom: none;
    }
    table.veri { width: 100%; border-collapse: collapse; }
    table.veri thead th {
        background: #5C008E;
        color: #fff;
        font-size: 10px;
        font-weight: bold;
        text-transform: uppercase;
        letter-spacing: .3px;
        padding: 7px 6px;
        text-align: center;
        border: 1px solid #4a0072;
    }
    table.veri tbody td {
        padding: 7px 6px;
        border: 1px solid #e5e0ee;
        text-align: center;
        font-size: 11px;
    }
    table.veri tbody tr:nth-child(even) td { background: #fbfaff; }
    table.veri .c-no { font-weight: bold; color: #5C008E; width: 40px; }
    table.veri .c-tarih { width: 90px; }
    table.veri .c-yapan { text-align: left; padding-left: 9px; }

    .ozet {
        margin-top: 14px;
        border: 1px solid #ead4ff;
        border-radius: 6px;
        background: #faf7fe;
        padding: 9px 12px;
        font-size: 11px;
    }
    .ozet .k { display: inline-block; margin-right: 22px; }
    .ozet .k b { color: #5C008E; font-size: 13px; }

    .footer {
        margin-top: 16px;
        text-align: center;
        font-size: 8.5px;
        color: #9ca3af;
    }
    .bos {
        text-align: center;
        color: #9ca3af;
        font-style: italic;
        padding: 24px;
    }
</style>
</head>
<body>

    <table class="hdr">
        <tr>
            @if($logo)
            <td class="logo"><img src="{{ $logo }}" alt=""></td>
            @endif
            <td>
                <div class="salon-adi">{{ optional($isletme)->isletme_adi ?: (optional($isletme)->salon_adi ?: 'Salon') }}</div>
                @if(optional($isletme)->cep_telefon)
                <div class="salon-alt">{{ $isletme->cep_telefon }}</div>
                @endif
            </td>
            <td class="baslik-kutu"><span class="rozet">SEANS DÖKÜMÜ</span></td>
        </tr>
    </table>

    <div class="cizgi"></div>

    <table class="bilgi">
        <tr>
            <td class="lbl">Ad Soyad</td>
            <td class="val">{{ optional($musteri)->name ?: '-' }}</td>
            <td class="lbl">Telefon</td>
            <td class="val">{{ optional($musteri)->cep_telefon ?: '-' }}</td>
        </tr>
        @if(optional($musteri)->tc_kimlik_no)
        <tr>
            <td class="lbl">TC Kimlik No</td>
            <td class="val" colspan="3">{{ $musteri->tc_kimlik_no }}</td>
        </tr>
        @endif
        <tr>
            <td class="lbl">Paket / Hizmet</td>
            <td class="val">{{ $baslik ?: '-' }}</td>
            <td class="lbl">Başlangıç</td>
            <td class="val">{{ $baslangic }}</td>
        </tr>
    </table>

    @if(count($bolgeler) === 0)
        <div class="bos">Bu pakete ait girilmiş cihaz seans verisi bulunmuyor.</div>
    @else
        @foreach($bolgeler as $bolge)
        <div class="bolge-baslik">{{ $bolge['ad'] }}</div>
        <table class="veri">
            <thead>
                <tr>
                    <th class="c-no">Seans</th>
                    <th class="c-tarih">Tarih</th>
                    <th>Enerji (Jül)</th>
                    <th>Hız</th>
                    <th>MS</th>
                    <th>Atış Sayısı</th>
                    <th>Uygulama Yapan</th>
                </tr>
            </thead>
            <tbody>
                @foreach($bolge['satirlar'] as $s)
                <tr>
                    <td class="c-no">{{ $s['no'] }}</td>
                    <td class="c-tarih">{{ $s['tarih'] }}</td>
                    <td>{{ $s['enerji'] }}</td>
                    <td>{{ $s['hiz'] }}</td>
                    <td>{{ $s['ms'] }}</td>
                    <td>{{ $s['atis'] }}</td>
                    <td class="c-yapan">{{ $s['personel'] }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @endforeach

        <div class="ozet">
            <span class="k">Toplam Seans: <b>{{ $toplam !== null ? $toplam : '-' }}</b></span>
            <span class="k">Kullanılan: <b>{{ $kullanilan }}</b></span>
            <span class="k">Kalan: <b>{{ is_numeric($toplam) ? max(0, $toplam - $kullanilan) : ($toplam !== null ? $toplam : '-') }}</b></span>
        </div>
    @endif

    <div class="footer">
        Bu belge {{ date('d.m.Y H:i') }} tarihinde Randevumcepte üzerinden oluşturulmuştur.
    </div>

</body>
</html>
