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
		<h5  style="text-align: center;margin-top: -27px;"><u>MİKROPİGMENTASYON</u> UYGULAMASI HAKKINDA BİLGİLENDİRME VE ONAM FORMU</h5>
	</div>
	
	<div style=" border:1px solid black;width:100%;margin-top: -10px;  font-size: 11px">
		<i>Bu formdaki açıklamaların amacı sizi endişelendirmek için değil, uygulanacak işlemn öncesi-sırası-sonrası ve olası riskleri hakkında bilimsel çerçevede aydınlatmaktır. Lütfen dikkatlice okuyunuz. Soru veya anlamadığınız noktalar varsa, yardım isteyiniz.</i>
		
	</div>
	<div style="max-height: auto; margin-top: -15px;" >
			<h5 style="background-color: lightgrey; width:100%; " >MİKROPİGMENTASYON UYGULAMASI NEDİR VE NE AMAÇLA KULLANILIR?</h5>
			
		
	</div>
	<div style="margin-top: -25px; max-height:auto;" >
			<label style="font-size: 12px;">&quot;Kalıcı makyaj&quot;, &quot;saç simülasyonu&quot;, &quot;dermapigmentasyon&quot; veya &quot;kozmetik dövme işlemi&quot; olarak ta isimlendirilen mikropigmentasyon dövme işlemine benzer bir işlemdir. Steril ve tek kullanımlık bir iğne ile renk verici özelliği bulunan çok küçük boya tanecikleri cildin üst tabakasına zerk edilmesi işlemidir. Şu amaçlar ile uygulanmaktadır; seyrelmiş, bozuk, kısa kaşlatın şekillendirilmesinde, kaşlara yeniden şekil verilmesinde, tıbbi bir nedenle kaşı dökülenlerde, leke ver yara izlerinin kamuflajında, göz etrafında sürmeli görüntü vermek amacıyla, makyaj ihtiyacını azaltmak için, dudakları belirgin, dolgun göstermek, düzensiz dudak çevresini, asimetrik dudakları, eksik çatlak ve belirsiz dudak çevresini düzeltmek amacıyla, kaza, ameliyat sonras şeklini kaybetmiş dudaklara şekil ve doğallık vermek amacıyla, dudakların renklendirilmesinde, yüzdeki asimetrilerin düzeltilmesinde, saç dökülmesine bağlı kelliklerde saçların kazıtılmış gibi görünmesini sağlamak amacıyla, vitiligo(ala) hastalığında deriye normal rengi görüntüsüne benzetmek için, meme başı bölgesi(areola) alınanlarda bu görüntüyü taklit etmek amacıyla. </label>
		
	</div>
	<div  >
			<h5 style="background-color: lightgrey; width:100%;margin-top: 0px; max-height: auto " >MİKROPİGMENTASYON UYGULAMASI ÖNCESİNDE DİKKAT EDİLECEK HUSUSLAR NELERDİR?</h5>
		
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
	
	<div style=" border:1px solid black;width:100%;font-size: 11px; max-height: auto; text-align: center; ">
				<p>SORULARA <b>EVET</b> YANITI VERDİĞİNİZDE VEYA SORULAR DIŞINDA AÇIKLAMAK İSTEDİĞİNİZ DURUMLARI AŞAĞIYA YAZINIZ.</p>
		
	</div>
		<div style=" ; margin-top: -15px; max-height: auto;" >
			<h5 style="background-color: lightgrey; width:100%; " >MİKROPİGMENTASYON UYGULAMASI NASIL YAPILIR VE ETKİSİNİN SEYRİ NASILDIR?</h5>

	</div>
		<div style=" height:25px;margin-top: -30px; height: 210px; " >
			<p style="font-size: 12px;">Mikro streil iğneler ile özel cihazı yardımıyla bu amaç için üretilmiş boyalar cildin üst tabakasına uygulanır. Bu bir dövme uygulamasına göre daha yüzeyel tabakalara uygulanır. Uygulama bölgesinde oluşacak rahatsızlığı en hafife indirmek amacıyla yüzeysel anestezi uygulaması veya bir doktor tarafından lokal anestezi yapılabilir. Uygulama süresi cildin nemi ve elastikiyeti, kişinin hassasiyeti gibi faktörlere göre değişiklik gösterse de ortalama 2-3 saat sürer. Uygulamadan 3 veya 4 hafta sonra tekrar kontrol edilerek rötuş yapılabilir Kalıcı makyajdaki boyalar kişiye göre ve dış etkenlere maruziyete göre değişmekle beraber yüz bölgesine yapılan kalıcı makyaj sonrası 8 ay ile 2 yıl, saç bölgesine yapılan mikropigmentasyon uygulamasında ise 2 ile 6 yıl içinde solmaktadır. Boyaların kaybolması birden değil, yavaş yavaş solarak gerçekleşmektedir. Nadiren bazı ciltlerde boyalar hiç tutmayabilir.</p>
	</div>
		<div style=" float:right; height: 60px;margin-top:-40px; width: 180px; border:1px solid grey; border-style: dashed;" >
			<p style="font-size: 12px;color: grey;margin-bottom: 15px;margin-left: 5px;">İmza:</p>
			 <img src="{{$arsiv->musteri_imza}}" style="height: 60px;margin-top: -40px;margin-left: 40px;">
		
	</div>
	<div style=" height: auto;margin-top: -20px;  text-align: center">
		
	</div>
	<div style="  height: auto;margin-top: -50px" >
		<h5 style="text-align: center" >{{$isletme->salon_adi}}</h5>
			<h5 style="background-color: lightgrey; width:100%; margin-top: -15px;" >MİKROENHEKSİYON VE MİKROİĞNE UYGULAMASI SONRASI DİKKAT EDİLMESİ GEREKEN HUSUSLAR NELERDİR?</h5>

	</div>
		<div style="height: auto;" >
		<ul  style="list-style-type: square;margin-top:-20px;">
			<li> Kalıcı makyajın iyileşme süreci ve deri değiştirme bitene kadar 7-10 gün boyunca bakım yapılmalıdır. </li>
			<li> Pigmentler yüzeyi korumak amaçlı ince bir tabaka kremle kapatılmalıdır. Eğer kişi bunu tüm iyileşme süresi boyunca düzenli olarak yapmazsa pigment kaybından dolayı kalın bir yara izi görülebilir. </li>
			<li> 3 gün uygulama bölgesine su değmemelidir. 2 hafta boyunca Güneşlenmemeli, solaryuma girmemelidir.</li>
			<li> Makyaj uygulaması üzerinde oluşan kabuklar asla koparılmamalıdır. </li>
			<li> Uygulama bölgesine günde 3–4 kez bir hafta boyunca size tavsiye edilen krem sürülmelidir. </li>
			<li>
				21 gün bölgeye peeling uygulaması yapılmamalıdır. 
			</li>
			<li> Ayrıca işlemin kalıcılığını arttırmak için aşağıdaki maddelere dikkat edilmelidir: Güneşe veya solaryum seansları UV ışınlarına fazlaca maruz kalmamak, sigara kullanmamak,uygulanan alanları kimyasal peeling veya mikrodermabrazyon gibi cilt soyucu uygulamalarından korumak. </li>
			<li> Uygulama sonrası 10 gün boyunca sauna, buhar odası ve yüzme aktiviteleri yapılmamalıdır. </li>
			<li>Uygulama yapılan alanın korunması gerekir (iyileşme sürecinde bölge kaşınmamalı, havlu veya benzeri bir madde ile ovuşturulmamalıdır.)</li>
			<li> Bölgeye kozmetik ürün kullanılmamalıdır. </li>
			<li> Mikrop alma tehlikesine karşı uygulama yapılan bölge dezenfekte edilerek korunmalıdır. Uygulama yapılan alan, önerilen krem ile düzenli olarak nemlendirilmelidir</li>
			<li> Lokal anestezi kullanılmış ise 2-3 saat kadar ağrı hissetmeyebilirsiniz. Daha sonra oluşabilecek ağrı ve enfeksiyonu önlemek için, eğer önerilmiş ise size verilecek reçetedeki ilaçları kullanmanız gerekir. </li>
		</ul>
		
	</div>
	<div style="  height: auto;margin-top: -30px" >
			<h5 style="background-color: lightgrey; width:100%;" >MİKROPİGMENTASYON UYGULAMASININ RİSKLERİ VE YAN ETKİLERİ NELERDİR?</h5>

	</div>
		<div style="height: auto;" >
			<p style="font-size: 12px; margin-top:-20px;">  Tüm tıbbi işlemlerde veya kozmetik uygulamalarda olduğu gibi bu işlemde de bazı riskler vardır. </p>
		<ul  style="list-style-type: square;margin-top:-15px;">
			<li>  Uygulama yerlerinde morarma, şişlik, kızarıklık, ağrı, hassasiyet, kıl folliküllerinin kabarması (perifolliküler ödem), kaşıntı, deride pullanma, soyulma, kabarcık, yara, kabuklanma, kanama olabilir. </li>
			<li> İğne izleri çizgi tarzında 2-3 gün deride görülebilir.  </li>
			<li>  İğne yapılan bölgelerde veya genel enfeksiyon oluşabilir. </li>
			<li>  İğne yerlerinde morarma, kızarma, küçük kanamalar görülebilir.  </li>
			<li>  Tedavi edilen alanda işlem sırasında ve sonrasında 1 haftaya kadar süren ağrı, hassasiyet olabilir.  </li>
			<li>
				 Alerjik reaksiyonlar (anafilaksi, ürtiker, nefes darlığı ) ve enfeksiyon nadiren olabilir. 
			</li>
			<li>  Tedavisi sonrası ciltte açık veya koyu cilt rengi değişiklikler oluşabilir. </li>
			<li> Bireysel özelliklere bağlı olarak skar veya keloid dokusu şeklinde yara izi görünümü oluşabilir.  </li>
			<li>Beklenmeyen bir etki gelişirse lütfen kliniğimize başvurunuz. </li>
	
		</ul>
		<h5 style="text-align: center;margin-top: -20px;"><u>İŞLEM YAPILACAK KİŞİNİN ONAYI</u></h5>
		<div style=" border:1px solid black;margin-top: -20px;width:100%;font-size: 11px; max-height: auto; padding-left: 10px;  ">
				<i>Bu işlem diğer kozmetik uygulamalar gibi yaşamsal öneme sahip değildir. Kozmetik işlemler cildinizde yer alan kırışıklık, çizgilenmeler, lekeler, izler, dövme, kılcal damarlar, saç dökülmesi, sarkmalar, çatlaklar, istenmeyen kıllar, nemsizlik veya hoşa gitmeyen yüz ve vücut görünümleri gibi olumsuzlukları azaltmak yapılmaktadır. Tam olarak anlaşılamayan nedenlerden ötürü, işlemin başarısı ve kalıcılığı beklenen sürelerden daha kısa olabilir. Ayrıca uygulamanın sonuçlarıyla ilgili herhangi bir garanti verilemez. Oluşacak yan etkiler doktorumuz tarafından değerlendirilecek ve iyileştirme (reçete düzenleme, tıbbi müdahale, acil müdahale) işlemleri doktorumuz tarafından yapılacaktır. İstediğiniz zaman size verilmiş olan kurumumuza ait iletişim kanallarından bizeulaşabilirsiniz.</i>

		
	</div>
		<ul  style="list-style-type: square;margin-top:0px;">
			<li>   İznim olmaksızın tarafım üzerinde herhangi bir tıbbi müdahale, tedavi zorunlu olmadıkça uygulanamayacağı bana anlatıldı ve anladım </li>
			<li> Yukarıda MİKROPİGMENTASYON UYGULAMASI tedavisi yapılmadan önce verilmesi gereken bilgileri içeren metni okudum. Uygulanacak yöntemin beklenen etkisini ve risklerini anladım. </li>
			<li>   Ayrıca diğer tedavi seçenekleri, muhtemel sonuçları ve riskleri bana anlatıldı ve bu işlem hakkında bana yazılı ve sözlü açıklamalar yapıldı, gerekli uyarılarda bulunuldu ve anladım.  </li>
			<li>  Uygulanacak olan işlem seçenekleri ile ilgili ve bunların riskleriyle ilgili soru soracak durumda idim. Sorularım ve endişelerim beni tatmin edecek ölçüde tartışıldı ve cevaplandırıldı.  </li>
			
		
	
		</ul>
			<div style=" float:right; height: 60px;margin-top:-35px; width: 180px; border:1px solid grey; border-style: dashed;" >
			<p style="font-size: 12px;color: grey;margin-bottom: 15px;margin-left: 5px;">İmza:</p>
			 <img src="{{$arsiv->musteri_imza}}" style="height: 60px;margin-top: -40px;margin-left: 40px;">
		
	</div>
	<ul  style="list-style-type: square;margin-top:60px;">
		<li>   Bana yapılacak işlemin etkinliğini değerlendirmek amacıyla, işlem öncesinde, sırasında ve sonrasında görsel materyal örnekleri (fotoğraf gibi) alınabileceği ifade edildi ve kabul ettim.   </li>
		<li>
				  Bana yapılacak işlem sonucunda hiçbir garanti verilmediğini anladım. 
			</li>
			<li>   Bu tedaviyi almam konusunda herhangi zorlayıcı bir davranışla karşılaşmadım </li>
			<li> Bu koşullarda MİKROPİGMENTASYON UYGULAMASI ile tedavi olmayı ve bu tedavi için gerekli maliyeti ödemeyi kendi rızamla kabul ediyorum.  </li>
		
	
		</ul>

		
	</div>
			<div style="width: 300px;float: left;height: 100px;margin-top: 20px;">
		<h5 style="text-align: center;margin-top: -10px"><u>İŞLEM YAPILAN KİŞİNİN</u></h5>
		<p style="font-size: 12px;">Adı ve Soyadı : {{$arsiv->musteri->name}} </p>
		
		<p style="font-size: 12px;">İmzası : <img src="{{$arsiv->musteri_imza}}" style="height: 70px;margin-top: 12px;"> </p>

		<p style="font-size: 12px;">Tarih : {{date('d/m/Y',strtotime($arsiv->created_at))}} </p>
	
		<p style="font-size: 12px;margin-top: 0px">
			(*) Hastanın reşit olmaması durumunda yasal vasi tarafından imzalanır. 
		</p>

	</div>
	<div style="width: 300px;float: left;height: 100px;margin-top: 0px;">
		<h5 style="text-align: center;margin-top: -10px"><u>İŞLEMi YAPAN KİŞİNİN</u></h5>
		<p style="font-size: 12px">Adı ve Soyadı : {{$arsiv->personel->personel_adi}}</p>
		<p style="font-size: 12px;">İmzası : <img src="{{$arsiv->personel_imza}}" style="height: 70px;margin-top: 12px;"> </p>
		<p style="font-size: 12px;">Tarih : {{date('d/m/Y',strtotime($arsiv->created_at))}}</p>
	</div>

		

		
		
</body>
</html>