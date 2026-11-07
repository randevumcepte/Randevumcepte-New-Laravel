<!DOCTYPE html>
<html lang="tr">
<head>
   <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
   <meta http-equiv="Content-language" content="tr">
   <meta name="viewport" content="width=device-width, initial-scale=1.0">
   <title>Riskli Saç Hizmeti Sözleşmesi</title>
   <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: DejaVu Sans, sans-serif;
        }
        
        body {
            background: linear-gradient(135deg, #f9f7f7 0%, #f0e6e6 100%);
            color: #333;
            line-height: 1.5;
            padding: 20px;
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            font-size: 13px;
        }
        
        .contract-container {
            width: 100%;
            max-width: 900px;
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
            overflow: hidden;
        }
        
        .page {
            padding: 30px;
            min-height: 1123px; /* A4 yüksekliği */
            position: relative;
        }
        
        .header {
            text-align: center;
            border-bottom: 2px solid #b76e79;
        }
        
        h1 {
            color: #b76e79;
            font-size: 24px;
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        h2 {
            color: #b76e79;
            font-size: 16px;
            margin: 18px 0 10px;
            border-bottom: 1px solid #f0e6e6;
        }
        
        p {
            margin-bottom: 5px;
            text-align: justify;
        }
        
        ul {
            margin-left: 18px;
            margin-bottom: 10px;
        }
        
        li {
            margin-bottom: 5px;
            text-align: justify;
        }
        
        .highlight {
            font-weight: bold;
            color: #b76e79;
        }
        
        .signature-section {
            margin-top: 30px;
            width: 100%;
        }
        
        .signature-box {
            width: 48%;
            margin-bottom: 15px;
            float: left;
        }
        
        .signature-line {
            border-bottom: 1px solid #333;
            padding-top: 30px;
            margin-bottom: 5px;
        }
        
        .stamp-area {
            position: absolute;
            bottom: 60px;
            right: 30px;
            width: 100px;
            height: 100px;
            border: 1px dashed #ccc;
            border-radius: 5px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #888;
            font-size: 11px;
        }
        
        .compact-list {
            margin-bottom: 8px;
        }
        
        .compact-list li {
            margin-bottom: 4px;
        }
        
        @media print {
            body {
                background: white;
                padding: 0;
            }
            
            .contract-container {
                box-shadow: none;
                border-radius: 0;
            }
            
            .page {
                page-break-after: always;
            }
        }
        
        @media (max-width: 768px) {
            .page {
                padding: 15px;
            }
            
            .signature-section {
                flex-direction: column;
            }
            
            .signature-box {
                width: 100%;
                margin-bottom: 20px;
            }
        }
    </style>
</head>
<body>
    <div class="contract-container">
        <!-- SAYFA 1 -->
        <div class="page page-1">
            <div class="header">
                <h1>RİSKLİ SAÇ HİZMETİ SÖZLEŞMESİ</h1>
            </div>
            
            <section>
                <h2>1. SÖZLEŞMENİN KONUSU</h2>
                <p>Bu sözleşme, riskli saç uygulamaları (kimyasal işlemler, renk açma, saç düzleştirme, keratin, protez vb.) kapsamı, koşulları, bedeli ve tarafların sorumluluklarını düzenler.</p>
                
                <h2>2. HİZMETİN KAPSAMI</h2>
                <ul class="compact-list">
                    <li>Paket içeriği: Seçilen saç işlemleri ve ek bakım hizmetleri</li>
                    <li>Ek hizmetler (ek kimyasal uygulamalar, saç protezleri) ayrı ücretlendirilir</li>
                    <li>Kullanılan ürünler ve teknikler, müşterinin saç/deri yapısına göre belirlenir</li>
                    <li>Olası saç hasarı, kırılma, dökülme veya alerjik reaksiyonlardan Hizmet Veren sorumlu değildir</li>
                </ul>
                
                <h2>3. ÜCRET VE ÖDEME</h2>
                <ul class="compact-list">
                     <li>Toplam Ücret: {{$arsiv->toplam_ucret}}₺ </li>
                    <li>Kapora (rezervasyon teminatı): {{$arsiv->kapora}}₺ (iade edilmez)</li>
                    <li>Kalan Tutar: Hizmet günü tahsil edilir</li>
                    <li>Ödeme Yöntemleri: Nakit / Kart / Havale</li>
                </ul>
                
                <h2>4. RANDEVU, TARİH VE SAAT</h2>
                <ul class="compact-list">
                    <li>Hizmet, taraflarca belirlenen tarihte ve saatte yapılacaktır</li>
                    <li>Gecikmelerde hizmet süresi kısalabilir, ek ücret talep edilebilir</li>
                    <li>Müşteri belirtilen saatte hazır bulunmazsa, sözleşme tek taraflı feshedilmiş sayılır</li>
                </ul>
                
                <h2>5. DEĞİŞİKLİK VE İPTAL</h2>
                <ul class="compact-list">
                    <li>Randevu değişikliği/iptali: En az 15 gün öncesine kadar yazılı bildirim</li>
                    <li>Daha geç iptallerde kapora iadesi yapılmaz</li>
                    <li>Randevu tarihindeki değişiklik, Hizmet Veren'in uygunluk durumuna göre değerlendirilir</li>
                </ul>
                
                <h2>6. PROVA VE MEMNUNİYET</h2>
                <ul class="compact-list">
                    <li>Hizmet öncesi saç testi yapılabilir ve sonuçlar uygulamaya esas alınır</li>
                    <li>Uygulama günü yapılan değişiklikler ek ücret gerektirir</li>
                </ul>
                
                <h2>7. HİZMET SONRASI MEMNUNİYET</h2>
                <p>Hizmet sonrası saçın zarar görmesi veya kişisel memnuniyetsizlik durumunda <span class="highlight">Hizmet Veren sorumlu değildir</span></p>
                
                <h2>8. GÖRSEL KULLANIM</h2>
                <ul class="compact-list">
                    <li>Hizmet öncesi ve sonrası fotoğraf/video çekimi tanıtım ve portföy amaçlı yapılabilir</li>
                    <li>Kişisel bilgiler paylaşılmaz</li>
                    <li>Bu görseller için ek ücret talep edilmez</li>
                </ul>
                <h2>9. MÜCBİR SEBEPLER</h2>
                <ul class="compact-list">
                    <li>Doğal afet, hastalık veya resmi yasaklama gibi durumlarda Hizmet Veren sorumlu değildir</li>
                    <li>Hizmet yeni bir tarihe ertelenir; ödemeler iade edilmez</li>
                </ul>
                
                <h2>10. SORUMLULUK REDDİ</h2>
                <ul class="compact-list">
                    <li>Müşteri, saç ve sağlık geçmişi hakkında doğru bilgi vermekle yükümlüdür</li>
                    <li>İşlem sonrası saç hasarı, kırılma, dökülme veya renk değişikliklerinden Hizmet Veren sorumlu <span class="highlight">değildir</span></li>
                    <li>Geçici bozulmalar veya uygulama sonucu farklılıklar iade nedeni değildir</li>
                </ul>
                
                <h2>11. YETKİLİ MAHKEME</h2>
                <p>İşbu sözleşmeden doğacak uyuşmazlıklarda <span class="highlight">Hizmet Veren'in bulunduğu yer mahkemeleri ve icra daireleri yetkilidir</span></p>
                
                <h2>12. İMZALAR</h2>
               <div class="signature-section">
         
                <div class="signature-box">
                    <p><strong>Hizmet Alan (Müşteri):</strong></p>
                    <p>Ad Soyad: {{$arsiv->musteri->name}}</p>
                   
                    <p>İmza:  <img src="{{$arsiv->musteri_imza}}" style="height: 70px;"></p>
                    <p>Tarih:{{date('d/m/Y',strtotime($arsiv->created_at))}}</p>
                </div>
            </div>
         
            </section>
        </div>
        
     
            
            <div class="stamp-area">MÜHÜR</div>
        </div>
    </div>
</body>
</html>