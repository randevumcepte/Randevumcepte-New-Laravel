<!DOCTYPE >
<html>
   <head>
      <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
      <meta http-equiv="Content-language" content="tr">
      <title>{{ $title }}</title>
      <style type="text/css">
         li{font-size: 12px;}
         body {
            font-family: DejaVu Sans, sans-serif;
            padding: 20px;
         }  
         .clearfix {
            overflow: auto;
            clear: both;
         }
         .signature-img {
            height: 70px;
            margin-top: 12px;
            vertical-align: middle;
         }
         .left {
            float: left;
            width: 48%;
         }
         .right {
            float: right;
            width: 48%;
         }
      </style>
   </head>
   <body>
      <div style="max-height: auto;">
         {!!csrf_field()!!}
         <input id='arsiv_id' name='arsiv_id' type="hidden" value='{{$arsiv->id}}'>
         <h5 style="text-align: center">{{$isletme->salon_adi}}</h5>
         <h5 style="text-align: center; margin-top: -27px;">LİPOSONİX KONSTÜLTASYON FORMU</h5>
      </div> 
       
      <div style="margin-top: 30px; max-height: auto">
         <ul style="list-style-type: none;">
            <li style="border-bottom: 1px solid black; padding-bottom: 5px; overflow: auto;">
               Şeker hastalığınız var mı?
               <div style="float: right;">
                  <input type="checkbox" style="width:13px;height:13px;margin:0 5px;" {{($arsiv->seker) ? 'checked' : ''}}> Evet 
                  <input type="checkbox" style="width:13px;height:13px;margin:0 5px;" {{(!$arsiv->seker) ? 'checked' : ''}}> Hayır
               </div>
            </li>
            <li style="border-bottom: 1px solid black; padding-bottom: 5px; overflow: auto;">
               Herhangi bir alerjiniz var mı?
               <div style="float: right;">
                  <input type="checkbox" style="width:13px;height:13px;margin:0 5px;" {{($arsiv->alerji_bagisiklik_romatizma) ? 'checked' : ''}}> Evet 
                  <input type="checkbox" style="width:13px;height:13px;margin:0 5px;" {{(!$arsiv->alerji_bagisiklik_romatizma) ? 'checked' : ''}}> Hayır
               </div>
            </li>
            <li style="border-bottom: 1px solid black; padding-bottom: 5px; overflow: auto;">
              Herhangi bir kronik rahatsızlığınız var mı?
               <div style="float: right;">
                  <input type="checkbox" style="width:13px;height:13px;margin:0 5px;" {{($arsiv->kronik) ? 'checked' : ''}}> Evet 
                  <input type="checkbox" style="width:13px;height:13px;margin:0 5px;" {{(!$arsiv->kronik) ? 'checked' : ''}}> Hayır
               </div>
            </li>
            <li style="border-bottom: 1px solid black; padding-bottom: 5px; overflow: auto;">
             Doktor tarafından reçeteli kullandığınız ilaç var mı?
               <div style="float: right;">
                  <input type="checkbox" style="width:13px;height:13px;margin:0 5px;" {{($arsiv->receteli_ilaclar_var) ? 'checked' : ''}}> Evet 
                  <input type="checkbox" style="width:13px;height:13px;margin:0 5px;" {{(!$arsiv->receteli_ilaclar_var) ? 'checked' : ''}}> Hayır
               </div>
            </li>
            <li style="border-bottom: 1px solid black; padding-bottom: 5px; overflow: auto;">
               Gebelik var mı?
               <div style="float: right;">
                  <input type="checkbox" style="width:13px;height:13px;margin:0 5px;" {{($arsiv->gebelik) ? 'checked' : ''}}> Evet 
                  <input type="checkbox" style="width:13px;height:13px;margin:0 5px;" {{(!$arsiv->gebelik) ? 'checked' : ''}}> Hayır
               </div>
            </li>
         </ul>
      </div>
       
      <div style="height: 210px; margin-top: 20px;">
         <ul style="list-style-type: circle; padding-left: 20px;">
            <li> Tarafıma uygulanacak LİPOSONİX uygulaması izah edilmiştir. İşlem sırasında ve sonrasında ortaya çıkabilecek riskler ve komplikasyonlar konusunda bilgilendirildim.</li>
            <li> Nadiren de olsa ödem ve kızarıklık yaşanabilir bunlar geçici komplikasyonlardır işlem sırasında oluşabilecek riskleri kabul ediyorum.</li>
            <li> İŞLEMDEN SONRAKİ 1 AY BOYUNCA YAĞLI GIDALAR TÜKETİLMEYECEKTİR. KARBONİDRAT, ŞEKER, ALKOL TÜKETİMİ OLMAYACAKTIR.</li>
            <li> İŞLEMİN ETKİSİ VÜCUTTA 3 AY ÇALIŞMAYA DEVAM EDECEKTİR. BU SÜREÇTE BU GIDALAR TÜKETİLMEMEYE ÖZEN GÖSTERİLMELİDİR.</li>
            <li> İşlem tamamlandıktan sonra evde YAPILMASI VE YAPILMAMASI GEREKEN hususlar tarafıma tebliğ edilmiştir.</li>
            <li> LİPOSONİX uygulaması tek seanslık bir işlemdir EK SEANS gerektirmez, kontrol seansı aşamasında ihtiyaca göre VÜCUT GERME YAPILACAKTIR.</li>
            <li> Uygulama öncesi ve sonrası resimlerinin paylaşılmasına izin veriyorum.</li>
         </ul>
      </div>
       
      <div style="width: 100%; margin-top: 40px; overflow: auto;">
         <div style="width: 45%; float: right;">
            <h5 style="text-align: center;"><u>OKUDUM ANLADIM</u></h5>
            <p style="font-size: 12px;">Ad Soyad : {{$arsiv->musteri->name}}</p>
            <p style="font-size: 12px;">Telefon : +90{{$arsiv->musteri->cep_telefon}}</p>
            <p style="font-size: 12px;">İmza : <img src="{{$arsiv->musteri_imza}}" style="height: 70px; margin-top: 5px;"></p>
            <p style="font-size: 12px;">Tarih : {{date('d/m/Y',strtotime($arsiv->created_at))}}</p>
         </div>
         <div style="display: none">
            <h5 style="text-align: center;"><u>İŞLEMi YAPACAK KİŞİ</u></h5>
            <p style="font-size: 12px;">Ad Soyad : {{$arsiv->personel->personel_adi}}</p>
            <p style="font-size: 12px;">İmza : <img src="{{$arsiv->personel_imza}}" style="height: 70px; margin-top: 5px;"></p>
            <p style="font-size: 12px;">Tarih : {{date('d/m/Y',strtotime($arsiv->created_at))}}</p>
         </div>
      </div>
   </body>
</html>