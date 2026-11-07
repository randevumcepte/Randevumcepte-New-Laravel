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
        }   </style>
</head>
<body>
	<div style=" max-height: auto; margin-top: -50px;">
		{!!csrf_field()!!}

    <input id='arsiv_id' name='arsiv_id' type="hidden" value='{{$arsiv->id}}'>
		<h5 style="text-align: center" >{{$isletme->salon_adi}}</h5>
		<h5  style="text-align: center;margin-top: -27px;">DÖVME SİLME UYGULAMASI HAKKINDA BİLGİLENDİRME VE ONAM FORMU</h5>
	</div>
	
	<div style=" border:1px solid black;width:100%;margin-top: -20px;  font-size: 11px">
		<i>Bu formdaki açıklamaların amacı sizi endişelendirmek için değil, uygulanacak işlemn öncesi-sırası-sonrası ve olası riskleri hakkında bilimsel çerçevede aydınlatmaktır. Lütfen dikkatlice okuyunuz. Soru veya anlamadığınız noktalar varsa, yardım isteyiniz.</i>
		
	</div>
	<div style="max-height: auto; margin-top: -15px;" >
			<h5 style="background-color: lightgrey; width:100%; " >MİKROPİGMENTASYON UYGULAMASI NEDİR VE NE AMAÇLA KULLANILIR?</h5>
			
		
	</div>
	<div style="margin-top: -25px; max-height:auto;" >
			<label style="font-size: 12px;">Ciltteki çeşitli sorunları veya istenmeyen durumları gidermek için lazer veya ışın tedavileri kullanılabilmektedir. Bu durumlar arasında en sık yapılan işlemlerden biri istenmeyen dövme veya kalıcı makyajın cilt üzerinden silinmesi veya renginin açılması vardır. Bu amaç için Q-anahtarlı Nd-Yag lazer cihazı kullanılmaktadır. Uygulama sırasında acı hissini azaltmak için işlem öncesi anestezik krem uygulanabilir ve işlem sırasında soğuk uygulaması yapılabilir. Genellikle iğne ile lokal anestezi gerektirmeyen bir işlemdir, ancak ihtiyaç halinde lokal anestezi uygulaması yapılabilir.</label>
		
	</div>
	<div  >
			<h5 style="background-color: lightgrey; width:100%;margin-top: 0px; max-height: auto " >DÖVME SİLME İŞLEMİNDE ÖNCESİNDE DİKKAT EDİLECEK HUSUSLAR NELERDİR?</h5>
		
	</div>
	<div style="text-align: center;margin-top: -18px; max-height: auto">
		<b style="font-size: 12px;border-bottom: 1px solid black; ">Lütfen aşağıdaki soruları eksiksiz olarak yanıtlayınız.</b>

		
	</div>
		<div style="margin-top: -15px; max-height: auto">
			<ul style="list-style-type: circle;">
<li style="border-bottom: 1px solid black; padding-bottom: 5px;">Uygulama alanında veya vücudunuzda enfeksiyonunuz var mı?<div style="float: right;"><input type="checkbox" style="width:13px;height: 5px;padding: 0;margin:0;vertical-align: center;position: relative;top: -1px;*overflow: hidden;" name="" {{($arsiv->enfeksiyon) ? 'checked' : ''}}> Evet <input type="checkbox" style=" width: 13px;height: 5px;padding: 0;margin:0;vertical-align: center;position: relative;top: -1px;*overflow: hidden;"  name="" {{(!$arsiv->enfeksiyon) ? 'checked' : ''}}> Hayır</div></li>
			<li style="border-bottom: 1px solid black; padding-bottom: 5px;">Şeker gibi kronik bir hastalığınız var mı?<div style="float: right;"><input type="checkbox" style="width:13px;height: 5px;padding: 0;margin:0;vertical-align: center;position: relative;top: -1px;*overflow: hidden;" name="" {{($arsiv->seker) ? 'checked' : ''}}> Evet <input type="checkbox" style=" width: 13px;height: 5px;padding: 0;margin:0;vertical-align: center;position: relative;top: -1px;*overflow: hidden;"  name="" {{(!$arsiv->seker) ? 'checked' : ''}}> Hayır
			</div></li>
			<li style="border-bottom: 1px solid black; padding-bottom: 5px;">Alerji, bağışıklık sistemi veya romatizmal bir hastalığınız var mı?<div style="float: right;"><input type="checkbox" style="width:13px;height: 5px;padding: 0;margin:0;vertical-align: center;position: relative;top: -1px;*overflow: hidden;" name="" {{($arsiv->alerji_bagisiklik_romatizma) ? 'checked' : ''}}> Evet <input type="checkbox" style=" width: 13px;height: 5px;padding: 0;margin:0;vertical-align: center;position: relative;top: -1px;*overflow: hidden;"  name="" {{(!$arsiv->alerji_bagisiklik_romatizma) ? 'checked' : ''}}> Hayır</div></li>
			<li style="border-bottom: 1px solid black; padding-bottom: 5px;">Bir operasyon geçirdiniz mi?<div style="float: right;"><input type="checkbox" style="width:13px;height: 5px;padding: 0;margin:0;vertical-align: center;position: relative;top: -1px;*overflow: hidden;" name="" {{($arsiv->operasyon) ? 'checked' : ''}}> Evet <input type="checkbox" style=" width: 13px;height: 5px;padding: 0;margin:0;vertical-align: center;position: relative;top: -1px;*overflow: hidden;"  name="" {{(!$arsiv->operasyon) ? 'checked' : ''}}> Hayır</div></li>
			<li style="border-bottom: 1px solid black; padding-bottom: 5px;">Aktif deri hastalığınız var mı veya uçuk ataklarınız olur mu?<div style="float: right;"><input type="checkbox" style="width:13px;height: 5px;padding: 0;margin:0;vertical-align: center;position: relative;top: -1px;*overflow: hidden;" name="" {{($arsiv->deri_hastaligi) ? 'checked' : ''}}> Evet <input type="checkbox" style=" width: 13px;height: 5px;padding: 0;margin:0;vertical-align: center;position: relative;top: -1px;*overflow: hidden;"  name="" {{(!$arsiv->deri_hastaligi) ? 'checked' : ''}}> Hayır</div></li>
			<li style="border-bottom: 1px solid black; padding-bottom: 5px;">Kanamaya yatkınlığınız var mı?<div style="float: right;">
				<input type="checkbox" style="width:13px;height: 5px;padding: 0;margin:0;vertical-align: center;position: relative;top: -1px;*overflow: hidden;" name="" {{($arsiv->kanama) ? 'checked' : ''}}> Evet <input type="checkbox" style=" width: 13px;height: 5px;padding: 0;margin:0;vertical-align: center;position: relative;top: -1px;*overflow: hidden;"  name="" {{(!$arsiv->kanama) ? 'checked' : ''}}> Hayır
			</div></li>
			<li style="border-bottom: 1px solid black; padding-bottom: 5px;">Hepatit(HBsAg, HCV) veya AIDS(HIV) pozitifliğiniz var mı?<div style="float: right;"><input type="checkbox" style="width:13px;height: 5px;padding: 0;margin:0;vertical-align: center;position: relative;top: -1px;*overflow: hidden;" name="" {{($arsiv->hepatit_aids) ? 'checked' : ''}}> Evet <input type="checkbox" style=" width: 13px;height: 5px;padding: 0;margin:0;vertical-align: center;position: relative;top: -1px;*overflow: hidden;"  name="" {{(!$arsiv->hepatit_aids) ? 'checked' : ''}}> Hayır</div></li>
			<li style="border-bottom: 1px solid black; padding-bottom: 5px;">Gebelik riski, gebelik ya da emzirme durumunuz  var mı?<div style="float: right;"><input type="checkbox" style="width:13px;height: 5px;padding: 0;margin:0;vertical-align: center;position: relative;top: -1px;*overflow: hidden;" name="" {{($arsiv->gebelik) ? 'checked' : ''}}> Evet <input type="checkbox" style=" width: 13px;height: 5px;padding: 0;margin:0;vertical-align: center;position: relative;top: -1px;*overflow: hidden;"  name="" {{(!$arsiv->gebelik) ? 'checked' : ''}}> Hayır</div></li>
			<li style="border-bottom: 1px solid black; padding-bottom: 5px;">Son 1 hafta içinde herhangi bir ilaç kullandınız mı?<div style="float: right;"><input type="checkbox" style="width:13px;height: 5px;padding: 0;margin:0;vertical-align: center;position: relative;top: -1px;*overflow: hidden;" name="" {{($arsiv->son_bir_hafta) ? 'checked' : ''}}> Evet <input type="checkbox" style=" width: 13px;height: 5px;padding: 0;margin:0;vertical-align: center;position: relative;top: -1px;*overflow: hidden;"  name="" {{(!$arsiv->son_bir_hafta) ? 'checked' : ''}}> Hayır</div></li>
			<li style="border-bottom: 1px solid black; padding-bottom: 5px;">Son 3 gün içinde kan sulandırıcı ilaç(aspirin vb.) kullandınız mı?<div style="float: right;"><input type="checkbox" style="width:13px;height: 5px;padding: 0;margin:0;vertical-align: center;position: relative;top: -1px;*overflow: hidden;" name="" {{($arsiv->son_uc_gun) ? 'checked' : ''}}> Evet <input type="checkbox" style=" width: 13px;height: 5px;padding: 0;margin:0;vertical-align: center;position: relative;top: -1px;*overflow: hidden;"  name="" {{(!$arsiv->son_uc_gun) ? 'checked' : ''}}> Hayır</div></li>
			<li style="border-bottom: 1px solid black; padding-bottom: 5px;">Son 1 ay içinde herhangi bir dermatolojik, estetik işlem yapıldı mı?<div style="float: right;"><input type="checkbox" style="width:13px;height: 5px;padding: 0;margin:0;vertical-align: center;position: relative;top: -1px;*overflow: hidden;" name="" {{($arsiv->son_bir_ay) ? 'checked' : ''}}> Evet <input type="checkbox" style=" width: 13px;height: 5px;padding: 0;margin:0;vertical-align: center;position: relative;top: -1px;*overflow: hidden;"  name="" {{(!$arsiv->son_bir_ay) ? 'checked' : ''}}> Hayır</div></li>
			<li style="border-bottom: 1px solid black; padding-bottom: 5px;">Son birkaç hafta içinde güneş veya solaryum ile bronzlaştınız mı?<div style="float: right;"><input type="checkbox" style="width:13px;height: 5px;padding: 0;margin:0;vertical-align: center;position: relative;top: -1px;*overflow: hidden;" name="" {{($arsiv->son_birkac_hafta) ? 'checked' : ''}}> Evet <input type="checkbox" style=" width: 13px;height: 5px;padding: 0;margin:0;vertical-align: center;position: relative;top: -1px;*overflow: hidden;"  name="" {{(!$arsiv->son_birkac_hafta) ? 'checked' : ''}}> Hayır</div></li>
			<li>Daha önce bu işlemden yaptırdı iseniz bir olumsuzluk oldu mu?<div style="float: right;"><input type="checkbox" style="width:13px;height: 5px;padding: 0;margin:0;vertical-align: center;position: relative;top: -1px;*overflow: hidden;" name="" {{($arsiv->daha_once_islem) ? 'checked' : ''}}> Evet <input type="checkbox" style=" width: 13px;height: 5px;padding: 0;margin:0;vertical-align: center;position: relative;top: -1px;*overflow: hidden;"  name="" {{(!$arsiv->daha_once_islem) ? 'checked' : ''}}> Hayır</div></li>
			
			
		</ul>
		</div>
	
	<div style=" margin-top: -15px; border:1px solid black;width:100%;font-size: 11px; max-height: auto; text-align: center; ">
				<p>SORULARA <b>EVET</b> YANITI VERDİĞİNİZDE VEYA SORULAR DIŞINDA AÇIKLAMAK İSTEDİĞİNİZ DURUMLARI AŞAĞIYA YAZINIZ.</p>
		
	</div>
		<div style=" ; margin-top: -20px; max-height: auto;" >
			<h5 style="background-color: lightgrey; width:100%; " >DÖVME SİLME İŞLEMİNDE NASIL YAPILIR VE ETKİSİNİN SEYRİ NASILDIR?</h5>

	</div>
		<div style=" height:25px;margin-top: -30px; height: auto; " >
			<ul  style="list-style-type: square;margin-top:5px;">
			<li>  Uygulama sırasında hem hastanın hem de uygulayıcının gözleri lazer ışınlarından korunmalıdır. </li>
			<li> Lazer epilasyon sırasında genellikle belli bir düzeyde acı hissi olabilir. Çok rahatsız edici olursa haber vermeniz gerekir. </li>
			<li>  Uygulamanız dövme veya kalıcı makyaj silme amaçlı yapılıyorsa, deriye ulaşan ışın ile boya parçacıkları parçalanarak ufalanmalarına sebep olur. Vücuttaki akyuvarlar bu boya kırıntılarını bölgeden uzaklaştırarak her seansta dövmenin renginin belirli oranlarda açılmasına sebep olurlar. Tedaviyle ilgili şu bilgiler önemlidir. Bir aydan daha yeni yaptırılmış dövmelere lazer ile tedavi önerilmez, tedavi halinde başarı oranı düşüktür. Dövme silme 3-10 seans gerektirebilir. Seans araları genellikle 6 ile 8 hafta olmalıdır. Siyah gibi koyu renkli dövmelerde başarı şansı renkli dövmelere göre (yeşil, kırmızı, mavi, sarı) daha yüksektir. Dövme silme vücutta merkeze yakın alanlarda daha başarılı iken el sırtı, ayak bileği gibi uç bölgelerde daha başarısız olabilir. </li>
			<li>  Dövme ve kalıcı makyaj silmede uygulanan doz ve uygulama seans sayıları kişiden kişiye farklılık göstermektedir. Ayrıca hastalıkların bazılarında belirgin iyileşme gözlenirken, bazılarında yanıt alınamamaktadır. Bu durum tedavi öncesinde her zaman öngörülememektedir. </li>
			<li>  Lazer ve ışın uygulamaları sonrasında 7 gün solaryum, güneş banyosu ve lazer uygulanacak bölgeyi zedeleyici işlemler (aşırı sıcak suyla banyo yapmak, bu bölgeyi ovalamak, keselemek, peeling gibi soyucu işlemler uygulamak, saç boyatmak, tüy-kıl almak, cilt bakımı yaptırmak ve aşırı güneşe maruz kalmak gibi) yan etkileri arttırabildiğinden önerilmemektedir. </li>
	
		</ul>
		
	</div>
		<div style=" float:right; height: 60px;margin-top:-20px; width: 180px; border:1px solid grey; border-style: dashed;" >
			<p style="font-size: 12px;color: grey;margin-bottom: 15px;margin-left: 5px;">İmza:</p>
			 <img src="{{$arsiv->musteri_imza}}" style="height: 60px;margin-top: -40px;margin-left: 40px;">
		
	</div>
	<div style=" height: auto;margin-top: -20px;  text-align: center">
		
	</div>
	<div style="  height: auto;margin-top: -50px" >
		<h5 style="text-align: center" >{{$isletme->salon_adi}}</h5>
			<h5 style="background-color: lightgrey; width:100%; margin-top: -15px;" >DÖVME SİLME İŞLEMİNDESONRASI DİKKAT EDİLMESİ GEREKEN HUSUSLAR NELERDİR?</h5>

	</div>
		<div style="height: auto;" >
		<ul  style="list-style-type: square;margin-top:-20px;">
			<li>Bu uygulamalar sonrası cilt güneşe karşı çok hassaslaşmaktadır.</li>
			<li> Mutlaka güneşten korunma kurallarına çok sıkı uyulmalı ve SPF 50+ düzeyinde güneş koruyucu uygulayınız. </li>
			<li> Uygulamanın ilk günü duş almayınız ve uygulama alanına elle temas etmeyin, özellikle ovuşturmayın. </li>
			<li>  İyileşme süresinde cildinizin nemlendirilmesine özen gösteriniz. </li>
			<li>  Denize girme, hamam, sauna, havuz, jakuzi gibi aktivitelerden 10 gün süreyle kaçınınız.  </li>
			
		</ul>
		
	</div>
	<div style="  height: auto;margin-top: -30px" >
			<h5 style="background-color: lightgrey; width:100%;" >DÖVME SİLME İŞLEMİNDE RİSKLERİ VE YAN ETKİLERİ NELERDİR?</h5>

	</div>
		<div style="height: auto;" >
			<p style="font-size: 12px; margin-top:-20px;">  Tüm tıbbi işlemlerde veya kozmetik uygulamalarda olduğu gibi bu işlemde de bazı riskler vardır. </p>
		<ul  style="list-style-type: square;margin-top:-15px;">
			<li>   Güneşten çok iyi korunma yanında, spot ışıklardan, bilgisayar ve televizyon ışığından korunmalıdır.  </li>
			<li>  Göze direkt atış yapılması sonucu körlüğe kadar gidebilen problemler oluşabilir. </li>
			<li>  Uygulama yapılan bölgede; kızarıklık (eritem), şişlik, kıl folliküllerinin kabarması (perifolliküler ödem), ağrı, kaşıntı, deride pullanma, kabarcık, yara, kabuklanma, kanama veya leke oluşumu ve nadiren iz kalması gibi yan etkiler. </li>
			<li>  Yanık ve enfeksiyon gibi daha ciddi yan etkiler oluşabilir.  </li>
			<li>  Uygulama bölgesinde cilt renginde koyulaşma veya açılma oluşabilir. Uçuk varsa var olan uçukta yayılma olabilir.   </li>
			<li>
				 Bireysel özelliklere bağlı olarak skar veya keloid dokusu şeklinde yara izi görünümü oluşabilir.  
			</li>
			<li>Beklenmeyen bir etki gelişirse lütfen kliniğimize başvurunuz. </li>
	
		</ul>
		<h5 style="text-align: center;margin-top: -20px;"><u>İŞLEM YAPILACAK KİŞİNİN ONAYI</u></h5>
		<div style=" border:1px solid black;margin-top: -20px;width:100%;font-size: 11px; max-height: auto; padding-left: 10px;  ">
				<i>Bu işlem diğer kozmetik uygulamalar gibi yaşamsal öneme sahip değildir. Kozmetik işlemler cildinizde yer alan kırışıklık, çizgilenmeler, lekeler, izler, dövme, kılcal damarlar, saç dökülmesi, sarkmalar, çatlaklar, istenmeyen kıllar, nemsizlik veya hoşa gitmeyen yüz ve vücut görünümleri gibi olumsuzlukları azaltmak yapılmaktadır. Tam olarak anlaşılamayan nedenlerden ötürü, işlemin başarısı ve kalıcılığı beklenen sürelerden daha kısa olabilir. Ayrıca uygulamanın sonuçlarıyla ilgili herhangi bir garanti verilemez. Oluşacak yan etkiler doktorumuz tarafından değerlendirilecek ve iyileştirme (reçete düzenleme, tıbbi müdahale, acil müdahale) işlemleri doktorumuz tarafından yapılacaktır. İstediğiniz zaman size verilmiş olan kurumumuza ait iletişim kanallarından bizeulaşabilirsiniz.</i>

		
	</div>
		<ul  style="list-style-type: square;margin-top:0px;">
			<li>   İznim olmaksızın tarafım üzerinde herhangi bir tıbbi müdahale, tedavi zorunlu olmadıkça uygulanamayacağı bana anlatıldı ve anladım </li>
			<li> Yukarıda  DÖVME SİLME  tedavisi yapılmadan önce verilmesi gereken bilgileri içeren metni okudum. Uygulanacak yöntemin beklenen etkisini ve risklerini anladım. </li>
			<li>   Ayrıca diğer tedavi seçenekleri, muhtemel sonuçları ve riskleri bana anlatıldı ve bu işlem hakkında bana yazılı ve sözlü açıklamalar yapıldı, gerekli uyarılarda bulunuldu ve anladım.  </li>
			<li>  Uygulanacak olan işlem seçenekleri ile ilgili ve bunların riskleriyle ilgili soru soracak durumda idim. Sorularım ve endişelerim beni tatmin edecek ölçüde tartışıldı ve cevaplandırıldı.  </li>
			
				<li>   Bana yapılacak işlemin etkinliğini değerlendirmek amacıyla, işlem öncesinde, sırasında ve sonrasında görsel materyal örnekleri (fotoğraf gibi) alınabileceği ifade edildi ve kabul ettim.   </li>
		<li>
				  Bana yapılacak işlem sonucunda hiçbir garanti verilmediğini anladım. 
			</li>
			<li>   Bu tedaviyi almam konusunda herhangi zorlayıcı bir davranışla karşılaşmadım </li>
			<li> Bu koşullarda  DÖVME SİLME  tedavisi olmayı ve bu tedavi için gerekli maliyeti ödemeyi kendi rızamla kabul ediyorum.  </li>
	
		</ul>
			
		
	</div>

		
	</div>
		<div style="width: 300px;float: left;height: 100px;margin-top: -40px;">
		<h5 style="text-align: center;"><u>İŞLEM YAPILAN KİŞİNİN</u></h5>
		<p style="font-size: 12px; margin-top: -20px;">Adı ve Soyadı : {{$arsiv->musteri->name}} </p>
		
		<p style="font-size: 12px; margin-top: -10px">İmzası : <img src="{{$arsiv->musteri_imza}}" style="height: 70px;"></p>

		<p style="font-size: 12px; margin-top: -20px">Tarih : {{date('d/m/Y',strtotime($arsiv->created_at))}} </p>

			<p style="font-size: 12px;margin-top: -10px">
			(*) Hastanın reşit olmaması durumunda yasal vasi tarafından imzalanır. 
		</p>
	
	</div>
	<div style="width: 300px;float: left;height: 100px;margin-top: 0px;">
		<h5 style="text-align: center;"><u>İŞLEMi YAPAN KİŞİNİN</u></h5>
		<p style="font-size: 12px; margin-top: -20px">Adı ve Soyadı : {{$arsiv->personel->personel_adi}}</p>
		<p style="font-size: 12px; margin-top: -10px">İmzası : <img src="{{$arsiv->personel_imza}}" style="height: 70px;"></p>
		<p style="font-size: 12px; margin-top: -20px">Tarih : {{date('d/m/Y',strtotime($arsiv->created_at))}}</p>
	</div>

</body>
</html>