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
		<h5  style="text-align: center;margin-top: -27px;">KİMYASAL PEELİNG UYGULAMASI HAKKINDA BİLGİLENDİRME VE ONAM FORMU</h5>
	</div>
	
	<div style=" border:1px solid black;width:100%;margin-top: -20px;  font-size: 11px">
		<i>Bu formdaki açıklamaların amacı sizi endişelendirmek için değil, uygulanacak işlemn öncesi-sırası-sonrası ve olası riskleri hakkında bilimsel çerçevede aydınlatmaktır. Lütfen dikkatlice okuyunuz. Soru veya anlamadığınız noktalar varsa, yardım isteyiniz.</i>
		
	</div>
	<div style="max-height: auto; margin-top: -15px;" >
			<h5 style="background-color: lightgrey; width:100%; " >KİMYASAL PEELİNG UYGULAMASI NEDİR VE NE AMAÇLA KULLANILIR? ?</h5>
			
		
	</div>
	<div style="margin-top: -25px; max-height:auto;" >
			<label style="font-size: 12px;">Kimyasal peeling uygulaması, cilt soyma tedavileri kapsamında; cilt yenileme, cilt lekelerinin tedavisi, kırışıklıkların tedavisi, sivilce izleri veya yara izleri tedavisi, skar veya keloid dokuları tedavisi, deri çatlaklarının tedavisi, deride bulunan bazı istenmeyen oluşumların çıkarılması, siğillerin tedavisi, gözaltı torbalarının azaltılması, deri yüzeyinde bulunan tümöral lezyonların çıkarılması, dermal nevusların alınması durumlarında kullanılmaktadır. Kimyasal peeling uygulamasında ise içeriği ve gücü değişen asidik ürünler ile cildin üst veya orta tabakası soyulmaktadır. Bu amaçla en sık meyve asitleri (glikolik asit),salisik asit, trikloroasetik asit, Jessner solüsyonu veya değişik yapıda asitler kullanılmaktadır. Asidin türüne göre nötralize edilmekte veya kimyasal reaksiyonun kendi durması beklenmektedir.  </label>
		
	</div>
	<div  >
			<h5 style="background-color: lightgrey; width:100%;margin-top: 0px; max-height: auto " >KİMYASAL PEELİNG UYGULAMASI ÖNCESİNDE DİKKAT EDİLECEK HUSUSLAR NELERDİR? </h5>
		
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
	
	<div style=" border:1px solid black;width:100%;font-size: 11px; max-height: auto; text-align: center; margin-top: -15px ">
				<p>SORULARA <b>EVET</b> YANITI VERDİĞİNİZDE VEYA SORULAR DIŞINDA AÇIKLAMAK İSTEDİĞİNİZ DURUMLARI AŞAĞIYA YAZINIZ.</p>
		
	</div>
		<div style=" ; margin-top: -15px; max-height: auto;" >
			<h5 style="background-color: lightgrey; width:100%; " >KİMYASAL PEELİNG UYGULAMASI NASIL YAPILIR VE ETKİSİNİN SEYRİ NASILDIR?</h5>

	</div>
		<div style=" height:25px;margin-top: -30px; height: auto; " >
			<p style="font-size: 12px;">Lazer uygulaması sırasında hem hastanın hem de uygulayıcının gözleri lazer ışınlarından korunmalıdır. Uygulama öncesinde gerekli olduğu takdirde lokal anestezi etkili kremler kullanılmaktadır. Bu işlemler genellikle belli aralar ile yapılan birden fazla seans gerektirmektedirler. İşlem süresi uygulanacak bölgenin genişliğine göre 10 dakika ile 60 dakika arasında olabilir. </p>
	</div>
		

	<div style="  height: auto;margin-top: 20px" >
		
			<h5 style="background-color: lightgrey; width:100%; margin-top: -15px;" >KİMYASAL PEELİNG UYGULAMASI SONRASI DİKKAT EDİLMESİ GEREKEN HUSUSLAR NELERDİR? </h5>

	</div>
		<div style="height: auto;" >
		<ul  style="list-style-type: square;margin-top:-20px;">
			<li>  Lokal anestezi kullanılmış ise 2-3 saat kadar ağrı hissetmeyebilirsiniz. Daha sonra oluşabilecek ağrı ve enfeksiyonu önlemek için, eğer önerilmiş ise size verilecek reçetedeki ilaçları kullanmanız gerekir </li>
			<li> Uygulamanın ilk günü duş almayınız ve uygulama alanına elle temas etmeyin, özellikle ovuşturmayın. </li>
			<li> İyileşme süresinde cildinizin nemlendirilmesine özen gösteriniz.</li>
			<li> 10 gün süresince yoğun spordan kaçınınız </li>
			<li> Deniz ve havuza girmek, sauna, havuz, jakuzi gibi aktivitelerden enfeksiyon riski yüzünden 10 gün süreyle kaçınınız.</li>
			
			
		</ul>
		<div style=" float:right; height: 60px;margin-top:10px; width: 180px; border:1px solid grey; border-style: dashed;" >
			<p style="font-size: 12px;color: grey;margin-bottom: 15px;margin-left: 5px;">İmza:</p>
			 <img src="{{$arsiv->musteri_imza}}" style="height: 60px;margin-top: -40px;margin-left: 40px;">
		
	</div>
		<ul style="list-style-type: square;margin-top:-10px;">
			<li>
				Bu uygulamalar sonrası 1 ay süresince cilt güneşe karşı çok hassaslaşmaktadır. Mutlaka güneşten korunma kurallarına çok sıkı uyulmalı ve SPF 50+ düzeyinde güneş koruyucu uygulayınız. Eğer bu tedavilere yeni seanslar eklenecek ise güneşten korunma 1 ayı geçse bile öteki seansa kadar olmalıdır. 
			</li>
				<li> 1 ay süresince spot ışıklardan, bilgisayar ve televizyon ışığından, karlı havalarda ve deniz kenarında güneşten çok iyi koruyunuz. 
 </li>
		</ul>
		<div style="  height: auto;margin-top: -50px" >
		<h5 style="text-align: center" >{{$isletme->salon_adi}}</h5>
		

	</div>
		<ul style="style=list-style-type: square;margin-top:-25px;">
		
			<li>1 hafta süresince cilt bakımı, kese, peeling, masaj, saç boyama, tüy alma işlemlerini yapmayınız. </li>
			<li>Soyulmaya başlayan deri kesinlikle elle koparılmamalı, ovalanmamalı ve keselenmemeli, kendiliğinden dökülmesi bekleyiniz. </li>
			<li>  İşlemden sonra doktorunuzun önerdiği ürünler dışında herhangi bir kozmetik krem/solüsyon veya işlem uygulamayınız. </li>
		</ul>
		
	</div>
	<div style="  margin-top: -35px" >
			<h5 style="background-color: lightgrey; width:100%;" >KİMYASAL PEELİNG UYGULAMASI RİSKLERİ VE YAN ETKİLERİ NELERDİR? </h5>

	</div>
		<div style="height: auto;" >
			<p style="font-size: 12px; margin-top:-20px;">  Tüm tıbbi işlemlerde olduğu gibi bu işlemde de bazı riskler vardır.  </p>
		<ul  style="list-style-type: square;margin-top:-15px;">
			<li>  Uygulama yerlerinde morarma, şişlik, kızarıklık, ağrı, hassasiyet, kıl folliküllerinin kabarması (perifolliküler ödem), kaşıntı, deride pullanma, soyulma, kabarcık, yara, kabuklanma, kanama olabilir. </li>
			<li> Tedavisi sonrası ciltte açık veya koyu cilt rengi değişiklikleri olabilir, bu yüzden güneşten korunma çok önemlidir.  </li>
			<li> Uygulama yapılan bölgede; tedavinin birkaç hafta sonra leke oluşumu ve nadiren iz kalması gibi yan etkiler görülebilir. </li>
			<li>  Yanık ve uygulama yerinde bölgesel enfeksiyon gibi daha ciddi yan etkiler veya bunun yayılması ile genel enfeksiyon hali olabilir.  </li>
			<li>  Göze direkt atış yapılması sonucu körlüğe kadar gidebilen problemler oluşabilir.   </li>
			<li>   Uçuk varsa var olan uçukta yayılma olabilir.   </li>
			<li>
				 Alerjik reaksiyonlar (anafilaksi, ürtiker, nefes darlığı ) ve enfeksiyon nadiren olabilir. 
			</li>
			<li> İnatçı eritem (kızarıklık) ve gecikmiş iyileşme gözlenebilir. Sivilce benzeri döküntüler görülebilir.  </li>
			<li> Bireysel özelliklere bağlı olarak skar veya keloid dokusu şeklinde yara izi görünümü oluşabilir. </li>
			<li>Beklenmeyen bir etki gelişirse lütfen kliniğimize başvurunuz. </li>
	
		</ul>
		<h5 style="text-align: center;margin-top: -20px;"><u>İŞLEM YAPILACAK KİŞİNİN ONAYI</u></h5>
		<div style=" border:1px solid black;margin-top: -20px;width:100%;font-size: 11px; max-height: auto; padding-left: 10px;  ">
				<i>Bu işlem diğer kozmetik uygulamalar gibi yaşamsal öneme sahip değildir. Kozmetik işlemler cildinizde yer alan kırışıklık, çizgilenmeler, lekeler, izler, dövme, kılcal damarlar, saç dökülmesi, sarkmalar, çatlaklar, istenmeyen kıllar, nemsizlik veya hoşa gitmeyen yüz ve vücut görünümleri gibi olumsuzlukları azaltmak yapılmaktadır. Tam olarak anlaşılamayan nedenlerden ötürü, işlemin başarısı ve kalıcılığı beklenen sürelerden daha kısa olabilir. Ayrıca uygulamanın sonuçlarıyla ilgili herhangi bir garanti verilemez. Oluşacak yan etkiler doktorumuz tarafından değerlendirilecek ve iyileştirme (reçete düzenleme, tıbbi müdahale, acil müdahale) işlemleri doktorumuz tarafından yapılacaktır. İstediğiniz zaman size verilmiş olan kurumumuza ait iletişim kanallarından bizeulaşabilirsiniz.</i>

		
	</div>
		<ul  style="list-style-type: square;margin-top:0px;">
			<li>   İznim olmaksızın tarafım üzerinde herhangi bir tıbbi müdahale, tedavi zorunlu olmadıkça uygulanamayacağı bana anlatıldı ve anladım </li>
			<li> Yukarıda KİMYASAL PEELİNG tedavisi yapılmadan önce verilmesi gereken bilgileri içeren metni okudum. Uygulanacak yöntemin beklenen etkisini ve risklerini anladım. </li>
			<li>   Ayrıca diğer tedavi seçenekleri, muhtemel sonuçları ve riskleri bana anlatıldı ve bu işlem hakkında bana yazılı ve sözlü açıklamalar yapıldı, gerekli uyarılarda bulunuldu ve anladım.  </li>
			<li>  Uygulanacak olan işlem seçenekleri ile ilgili ve bunların riskleriyle ilgili soru soracak durumda idim. Sorularım ve endişelerim beni tatmin edecek ölçüde tartışıldı ve cevaplandırıldı.  </li>
			

		<li>   Bana yapılacak işlemin etkinliğini değerlendirmek amacıyla, işlem öncesinde, sırasında ve sonrasında görsel materyal örnekleri (fotoğraf gibi) alınabileceği ifade edildi ve kabul ettim.   </li>
		<li>
				  Bana yapılacak işlem sonucunda hiçbir garanti verilmediğini anladım. 
			</li>
			<li>   Bu tedaviyi almam konusunda herhangi zorlayıcı bir davranışla karşılaşmadım </li>
			<li> Bu koşullarda KİMYASAL PEELİNG  ile tedavi olmayı ve bu tedavi için gerekli maliyeti ödemeyi kendi rızamla kabul ediyorum.  </li>
		
	
		</ul>
<div style="width: 300px;float: left;height: 100px;margin-top: -10px;">
		<h5 style="text-align: center;margin-top: -10px"><u>İŞLEM YAPILAN KİŞİNİN</u></h5>
		<p style="font-size: 12px;margin-top: -22px">Adı ve Soyadı : {{$arsiv->musteri->name}} </p>
		
		<p style="font-size: 12px;margin-top: -22px">İmzası :   <img src="{{$arsiv->musteri_imza}}" style="height: 70px;margin-top: 8px;"> </p>

		<p style="font-size: 12px;margin-top: -12px">Tarih : {{date('d/m/Y',strtotime($arsiv->created_at))}} </p>

		
	<p style="font-size: 12px;margin-top: -11px">
			(*) Hastanın reşit olmaması durumunda yasal vasi tarafından imzalanır. 
		</p>
	</div>
	<div style="width: 300px;float: left;height: 100px;margin-top: 0px;">
		<h5 style="text-align: center;margin-top: -10px"><u>İŞLEMi YAPAN KİŞİNİN</u></h5>
		<p style="font-size: 12px;margin-top: -22px">Adı ve Soyadı : {{$arsiv->personel->personel_adi}}</p>
		<p style="font-size: 12px;margin-top: -22px">İmzası :   <img src="{{$arsiv->personel_imza}}" style="height: 70px;margin-top: 8px;">  </p>
		<p style="font-size: 12px;margin-top: -12px">Tarih : {{date('d/m/Y',strtotime($arsiv->created_at))}}</p>
	</div>


		
	</div>

	



		
		
</body>
</html>