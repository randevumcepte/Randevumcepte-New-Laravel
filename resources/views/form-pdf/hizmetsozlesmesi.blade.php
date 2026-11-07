<!DOCTYPE html>
<html>
   <head>
      <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
      <meta http-equiv="Content-language" content="tr">
      <title>{{ $title }}</title>
      <style type="text/css">
         * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
         }
         
         body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 11px;
            padding: 20px;
            margin: 0;
            line-height: 1.4;
         }
         
         .container {
            width: 100%;
            max-width: 100%;
            margin: 0 auto;
         }
         
         /* Header */
         .header {
            text-align: center;
            margin-bottom: 20px;
         }
         
         .header h3 {
            text-decoration: underline;
            margin: 5px 0;
            font-size: 14px;
         }
         
         /* Başlık */
         .title {
            text-align: center;
            font-size: 16px;
            font-weight: bold;
            margin: 20px 0;
         }
         
         /* Bölüm stilleri */
         .section {
            margin: 15px 0;
            page-break-inside: avoid;
         }
         
         .section-title {
            text-decoration: underline;
            font-weight: bold;
            font-size: 12px;
            margin-bottom: 8px;
         }
         
         /* Madde işaretleri */
         ul {
            list-style-type: none;
            padding-left: 15px;
         }
         
         li {
            margin-bottom: 5px;
            font-size: 11px;
         }
         
         li:before {
            content: "•";
            position: relative;
            left: -10px;
            margin-right: 5px;
         }
         
         /* Özel şartlar için */
         .special-terms {
            list-style-type: none;
            padding-left: 15px;
         }
         
         .special-terms li {
            margin-bottom: 8px;
        }
         
         .special-terms li:before {
            content: "★";
            left: -12px;
            color: #333;
         }
         
         /* İmza bölümü */
         .signature-area {
            width: 100%;
            margin-top: 40px;
            overflow: auto;
            page-break-inside: avoid;
         }
         
         .signature-box {
            width: 45%;
            float: left;
            margin-top: 20px;
        }
         
         .signature-box.right {
            float: right;
         }
         
         .signature-line {
            margin-top: 30px;
            border-top: 1px solid #000;
            width: 80%;
            margin-bottom: 5px;
        }
         
         .signature-img {
            height: 60px;
            margin-top: 5px;
            vertical-align: middle;
        }
         
         /* Alt notlar */
         .footer-notes {
            margin-top: 30px;
            font-size: 10px;
            border-top: 1px solid #ccc;
            padding-top: 10px;
         }
         
         /* Clearfix */
         .clearfix {
            overflow: hidden;
            clear: both;
         }
         
         /* Sayfa sonu */
         .page-break {
            page-break-inside: avoid;
         }
         
         /* Kalın yazı */
         .bold {
            font-weight: bold;
         }
         
         .underline {
            text-decoration: underline;
        }
      </style>
   </head>
   <body>
      <div class="container">
         <!-- Header -->
         <div class="header">
            <h3>{{$isletme->salon_adi}}</h3>
         </div>
         
         <div class="title">HİZMET SÖZLEŞMESİ</div>
         
         <!-- Giriş -->
         <div class="section">
            <p>İşbu sözleşme metni, ekleri ile birlikte bir bütün arz eder ve SÖZLEŞME olarak adlandırılır. Sözleşme metnindeki hükümler ile sözleşmenin ekleri arasında çıkabilecek her türlü ihtilaf durumunda işbu ana sözleşme maddeleri geçerli olacaktır.</p>
         </div>
         
         <!-- 1. TARAFLAR -->
         <div class="section">
            <div class="section-title">1. TARAFLAR</div>
            <p>Taraflar bu sözleşmede kısaca Hizmet veren ve Hizmet alan olarak adlandırılacaktır.</p>
            <p style="margin-top: 5px;">İşbu sözleşme Atıfbey Mah. Türkan saylan caddesi no16/A Gaziemir İzmir adresinde faaliyet gösteren EBRU METE & GÜZELLİK İşletmecisi ile <u>{{$arsiv->musteri->adres ?? '.................................'}}</u> adresinde bulunan <u>{{$arsiv->musteri->name}}</u> arasında imzalanmıştır.</p>
         </div>
         
         <!-- 2. YAPILACAK İŞ -->
         <div class="section">
            <div class="section-title">2. YAPILACAK İŞ</div>
            <p>{{$arsiv->hizmet->hizmet_adi}}</p>
         </div>
         
         <!-- 3. ÜCRET -->
         <div class="section">
            <div class="section-title">3. ÜCRET</div>
            <p>{{number_format($arsiv->toplam_ucret,2,',','.')}} ₺</p>
         </div>
         
         <!-- 4. SÜRE -->
         <div class="section">
            <div class="section-title">4. SÜRE</div>
            <p>Bu sözleşme; 2 nolu maddesinde belirtilen işlemin yapıldığı tarih ile işlem bitiş tarihinde kendiliğinden son bulur.</p>
         </div>
         
         <!-- 5. GENEL ŞARTLAR -->
         <div class="section">
            <div class="section-title">5. GENEL ŞARTLAR</div>
            <p>Hizmet alan işyerinde yer alan iş emniyeti ve sağlığı kurullarına aynen uyacaktır.</p>
         </div>
         
         <!-- 6. ÖZEL ŞARTLAR -->
         <div class="section">
            <div class="section-title">6. ÖZEL ŞARTLAR</div>
            <ul class="special-terms">
               <li>Hizmet alan kendisine verilen Randevu bilgileri doğrultusunda, Hizmet verence bildirilen saatin en fazla 10 dk sonrasında işleme hazır bir şekilde salonda bulunmalıdır. Randevu saatinde hazır olmaması durumunda, Hizmet verenin diğer işlerinin aksamasına sebebiyet vereceğinden o gün işlemi ifa edilmeyecektir.</li>
               <li>Hizmet Alan, yapılacak işlem ile ilgili bilgilendirilmiş, Uygulama esnasında öncesinin ve sonrasının net bir şekilde görülmesi amacıyla fotoğraf veya video görüntülerinin alınabileceğini ve bunların eğitsel ve bilimsel çalışmalarda kullanılabileceğini kabul etmiştir. (BELİRLİ UYGULAMALARDA) KALICI MAKYAJ VB UYGULAMALAR İÇİN GEÇERLİ</li>
               <li>Hizmet Alana uygulanacak işlemden önce nelere dikkat etmesi gerektiği ve işlem sonrasında oluşabilecek yan etkiler anlatılmıştır.</li>
               <li>Hizmet alan, yapılacak işleme engel olan herhangi bir sağlık probleminin olmadığını beyan etmiştir.</li>
               <li>Salondan alınan hizmetlerin kullanım süresi, satın alınan tarihten itibaren 6 aydır.</li>
               <li>Cihazlar Yıllık, Aylık, Günlük Bakımlara tabi tutulur. Bu süreçte işlem verilemediği durumlarda kişiye önceden bilgilendirme yapılır. Ve yeni seansları oluşturulur.</li>
               <li>Seanslar Haber Verilmeden gelinmediği takdirde, kişinin o seansı yanar.</li>
               <li>Satın Alınan Paket, Alınan Tarihten İtibaren 6 ay içinde salona herhangi bir bilgi vermez ise ve seanslarına gelmez ise paket hakkı yanar.</li>
            </ul>
         </div>
         
         <!-- 7. CAYMA HAKKI -->
         <div class="section">
            <div class="section-title">7. CAYMA HAKKI</div>
            <p>Sözleşmenin imzalanmasından itibaren 14 gün içinde herhangi gerekçe göstermeksizin ve cezai şart ödemeksizin, hiçbir hukuki ve cezai sorumluluk üstlenmeksizin sözleşmeden cayma hakkına sahiptir.</p>
         </div>
         
         <!-- 8. İPTAL ŞARTLARI -->
         <div class="section">
            <div class="section-title">8. İPTAL ŞARTLARI</div>
            <p>Bu sözleşme imzalandığı tarihte yürürlülüğe girecek olup hizmetlerden yararlanıp yararlanmadığına bakılmaksızın sözleşme imzalandığı tarihten itibaren yürürlükte kalacaktır. Hizmet alan sözleşmesinin sona ereceği tarihten önce sözleşmeyi herhangi bir şekilde feshederse Hizmet verene aldığı işlem hizmeti karşılığında belirtilen KDV dahil toplam hizmet bedelinin %25'i kadar tazminat ödemeyi kabul eder.</p>
         </div>
         
         <!-- 9. UYUŞMAZLIK -->
         <div class="section">
            <div class="section-title">9. UYUŞMAZLIK</div>
            <p>Bu sözleşmeden doğacak uyuşmazlık {{$isletme->il->il_adi}} Mahkemelerince çözümlenir.</p>
         </div>
         
         <!-- 10. HÜKÜM OLMAYAN HALLER -->
         <div class="section">
            <div class="section-title">10. HÜKÜM OLMAYAN HALLER</div>
            <p>Sözleşmede hüküm bulunmayan hallerde 4857 sayılı İş Kanunu hükümleri uygulanır.</p>
         </div>
         
         <!-- 11. İMZA -->
         <div class="section">
            <div class="section-title">11. İMZA</div>
            <p>Bir sayfadan oluşan işbu belirli süreli hizmet sözleşmesi, taraflarca <u>{{date('d.m.Y',strtotime($arsiv->created_at)) ?? '.........'}}</u> tarihinde tanzim edilip, okunarak imzalanmakla, belirtilen şartlarla iş görmeyi karşılıklı olarak kabul, beyan ve taahhüt etmişlerdir.</p>
         </div>
         
         <!-- Alt Notlar -->
         <div class="footer-notes">
            <p>• Salondan alınan hizmetlerin kullanım süresi, satın alınan tarihten itibaren 6 aydır.</p>
            <p>• Seanslar Haber Verilmeden iptal edildiği takdirde, kişinin o seansı iptal olur.</p>
            <p>• Satın Almış Olduğunuz Hizmet, Herhangi 2. Şahısa Devredilemez.</p>
            <p>• Satın Almış Olduğunuz Hizmet Herhangi Bir İşlemle Değiştirilemez.</p>
            <p>• Salonda Kullanılan Cihazlar Bakıma/Tamire Gidebilir Ve Bu Süreç 30-45 Gün Arası Sürebilir. Bu Süreçte Kişiyi Mağdur Etmemek Adına Paketine Artı Seans İlave Edilir.</p>
         </div>
         
         <!-- İmza Bölümü -->
         <div class="signature-area">
            <div class="signature-box">
                <div class="bold">HİZMET VEREN</div>
                <div style="margin-top: 30px;">
                    <div>Adı Soyadı : {{$arsiv->personel->personel_adi}}</div>
                    <div style="margin-top: 20px;">
                        İmzası : 
                        @if(!empty($arsiv->personel_imza))
                            <img src="{{$arsiv->personel_imza}}" class="signature-img" alt="İmza">
                        @else
                            <span style="border-bottom: 1px solid #000; display: inline-block; width: 150px;"></span>
                        @endif
                    </div>
                </div>
            </div>
            <div class="signature-box right">
                <div class="bold">HİZMET ALAN</div>
                <div style="margin-top: 30px;">
                    <div>Adı Soyadı : {{$arsiv->musteri->name}}</div>
                    <div style="margin-top: 20px;">
                        İmzası : 
                        @if(!empty($arsiv->musteri_imza))
                            <img src="{{$arsiv->musteri_imza}}" class="signature-img" alt="İmza">
                        @else
                            <span style="border-bottom: 1px solid #000; display: inline-block; width: 150px;"></span>
                        @endif
                    </div>
                </div>
            </div>
         </div>
      </div>
   </body>
</html>