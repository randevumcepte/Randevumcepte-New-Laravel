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
		<h5  style="text-align: center;margin-top: -27px;">DERMAROLLER (MİKROİĞNELEME) UYGULAMASI HAKKINDA BİLGİLENDİRME VE ONAM FORMU</h5>
	</div>
	
	<div style=" border:1px solid black;width:100%;margin-top: -10px;  font-size: 11px">
		<i>Bu formdaki açıklamaların amacı sizi endişelendirmek için değil, uygulanacak işlemn öncesi-sırası-sonrası ve olası riskleri hakkında bilimsel çerçevede aydınlatmaktır. Lütfen dikkatlice okuyunuz. Soru veya anlamadığınız noktalar varsa, yardım isteyiniz.</i>
		
	</div>
	<div style="max-height: auto; margin-top: -15px;" >
			<h5 style="background-color: lightgrey; width:100%; " >LAZER EPİLASYON NEDİR VE NE AMAÇLA KULLANILIR?</h5>
			
		
	</div>
	<div style="margin-top: -25px; max-height:auto;" >
			<label style="font-size: 12px;">Bu form, dermaroller uygulaması ve bunun olası risk ve komplikasyonları (istenmeyen sonuçları) hakkında bilgilendirmeye yöneliktir. Lütfen formu dikkatlice okuyunuz. Sorularınız ya da anlamadığınız noktalar varsa lütfen doktorunuzdan yardım isteyiniz.Dermaroller elle tutulan bir kabzası ve bunun ucunda içinde çok sayıda, son derece ince paslanmaz çelikten olan iğnelerle çevrelenmiş silindir şeklinde bir alettir. Dermaroller tedavisi, “mesoroller, mikroigne tedavisi veya kollojen indüksiyon tedavisi” gibi çeşitli isimlerle de bilinmektedir. Dermaroller derinin ikinci alt tabakasında yer alan dermise mikro kanallar (mikro yaralar) açar ve iğneleme sayesinde deri üzerinde ufacık iğne ucu büyüklüğünde kanamalar olur. İşlem iz bırakmadan 1-3 günde iyileşir. Dermaroller ile tedavide arzu edilir etki oluşması için 4-6 hafta aralıklarla ve çok sayıda iğnelemeye ihtiyaç vardır. Herhangi epidermal (derinin en üst tabakası) hasara neden olmaz ve zaman kaybı minimaldir. İşlem ofis ortamında yapılır. Dermaroller tedavisiyle deride mikro kanallar açmak (iğne ucu ile oluşmuş yara) ve ardından yara iyileşmesi ile ince deri çizgilerini sıkılaştırmak veya deri üzerindeki skarları (akne izi, strialar, yanık izi vb.) azaltmak amaçlanır. Ayrıca topikal kozmetiklerin ve/veya büyüme faktörleri, peptidler ve kök hücrelerin emilimini sağlanır. Dermoroller tedavisi sonrası derimize hacim ve dolgunluk veren elastin, kollogen ve hyalüronik asit üretimi artar bunun sonucu deri yüzeyinde, dokusunda ve renginde düzelme olması beklenir. Dermoroller tedavisi ağrılıdır. Öncesinde tedavi edilecek alana topikal anestezik krem (EMLA %5 krem) ile kapatarak 1 saat anestezi yapılır, uyuşturulması sağlanırDermoroller tedavisinin etkinliği için genellikle birden çok sayıda seansa ihtiyaç olur. Bazı durumlar da ise istenen kozmetik düzelme için başka ek tedavilerle (PRP, lazer, dolgu, botoks vb.) birlikte kullanılması daha iyi sonuç verebilir.</label>
		
	</div>
	<div  >
			<h5 style="background-color: lightgrey; width:100%;margin-top: 0px; max-height: auto " >DERMAROLLER (MİKROİĞNELEME) UYGULAMASI ÖNCESİNDE DİKKAT EDİLECEK HUSUSLAR NELERDİR?</h5>
		
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
	
	<div style="margin-top: -10px; border:1px solid black;width:100%;font-size: 11px; max-height: auto; text-align: center; ">
				<p>SORULARA <b>EVET</b> YANITI VERDİĞİNİZDE VEYA SORULAR DIŞINDA AÇIKLAMAK İSTEDİĞİNİZ DURUMLARI AŞAĞIYA YAZINIZ.</p>
		
	</div>
		
	
		
	<div style="  height: auto;margin-top: 20px" >
			<h5 style="background-color: lightgrey; width:100%; margin-top: -15px;" >DERMAROLLER (MİKROİĞNELEME) UYGULAMA SONRASINDA DİKKAT EDİLMESİ GEREKEN HUSUSLAR NELERDİR?</h5>

	</div>
		<div style="height: auto;" >
		<ul  style="list-style-type: square;margin-top:-20px;">
			<li> Uygulamanın ilk günü duş alınmamalıdır.</li>
			<li>İşlemden sonra doktorunuzun önerisi dışında herhangi bir kozmetik veya işlem uygulanmamalı.</li>
			<li> Beklenmeyen bir etki gelişirse lütfen doktorunuza başvurunuz.</li>
				
			

		
		</ul>
		<div style=" float:right; height: 60px;margin-top:-30px; width: 180px; border:1px solid grey; border-style: dashed;" >
			<p style="font-size: 12px;color: grey;margin-bottom: 15px;margin-left: 5px;">İmza:</p>
			 <img src="{{$arsiv->musteri_imza}}" style="height: 60px;margin-top: -40px;margin-left: 40px;">
		
	</div>

	<ul style="list-style-type: square;margin-top:10px;">
		<li>Dermaroller güneş hassasiyetine neden olmaz, bununla birlikte deri üzerinde yapılan her işlem gibi uygulama 
			sonrası güneşten korunma ve en az SPF 30+ düzeyinde güneş koruyucu uygulanmasında fayda vardır.</li>
		
			
		
	</ul>
		<div style=" max-height: auto; margin-top: -40px;">
		<h5 style="text-align: center" >CC ESTETİK</h5>
	
	</div>
		
	</div>

	<div style="  height: auto;margin-top: -40px" >
			<h5 style="background-color: lightgrey; width:100%;" >UYGULAMA SONRASINDA OLUŞABİLECEK YAN ETKİLER NELERDİR?</h5>

	</div>
		<div style="height: auto;" >
			<p style="font-size: 12px; margin-top:-20px;">  Tüm tıbbi işlemlerde veya kozmetik uygulamalarda olduğu gibi bu işlemde de bazı riskler vardır. </p>
		<ul  style="list-style-type: square;margin-top:-15px;">
			<li> Kısa süre hafif düzeyde deride ağrı, yanma ve hassasiyet olabilir.</li>
			<li>İğne uzunluğuna göre 3-4 saat ile 1-4 güne kadar devam edebilen eritem (kızarıklık) beklenir</li>
			<li>Göz çevresi uygulamalarında hafiften şiddetliye göre değişen (iğne uzunluğuna göre) ödem olabilir</li>
			<li>Nadiren kemik üzeri veya çok ince deri alanlarında hafif-orta düzeyde deri altında kanama olabilir.</li>
			<li>Her ne kadar pigmentasyon (leke) tedavisinde kullanılsa da nadiren pigmentasyon riski olabilir. </li>
			<li>Bu güne kadar hiç bildirilmemiş olsa da mikrokanallar açılan deriye bakteri girişi olabilir, yüzeyel bir deri enfeksiyonu olabilir.</li>
			<li>Bu güne kadar hiç bildirilmemiş olsa da mikrokanallar açılarak uyarılan deride daha önce hikayesi olan veya olmayan hastalarda labilal herpes (uçuk mikrobu) ortaya çıkabilir.</li>
			<li>Dermaroller sonrası uygulanan bazı topikal solüsyonlar, büyüme faktörü, peptidlere karşı deri alerjik reaksiyon gösterebilir</li>

	
		</ul>
		

		<ul style="list-style-type: square;margin-top:-15px;">
						<li>  Yüz bölgesinde nadirende olsa ince tüylerin yayılması. </li>
			<li>Beklenmeyen bir etki gelişirse lütfen kliniğimize başvurunuz. </li>
		</ul>
		<h5 style="text-align: center;margin-top: -20px;"><u>İŞLEM YAPILACAK KİŞİNİN ONAYI</u></h5>
		<div style=" border:1px solid black;margin-top: -20px;width:100%;font-size: 11px; max-height: auto; padding-left: 10px;  ">
				<i>Bu işlem diğer kozmetik uygulamalar gibi yaşamsal öneme sahip değildir. Kozmetik işlemler cildinizde yer alan kırışıklık, çizgilenmeler, lekeler, izler, dövme, kılcal damarlar, saç dökülmesi, sarkmalar, çatlaklar, istenmeyen kıllar, nemsizlik veya hoşa gitmeyen yüz ve vücut görünümleri gibi olumsuzlukları azaltmak yapılmaktadır. Tam olarak anlaşılamayan nedenlerden ötürü, işlemin başarısı ve kalıcılığı beklenen sürelerden daha kısa olabilir. Ayrıca uygulamanın sonuçlarıyla ilgili herhangi bir garanti verilemez. Oluşacak yan etkiler doktorumuz tarafından değerlendirilecek ve iyileştirme (reçete düzenleme, tıbbi müdahale, acil müdahale) işlemleri doktorumuz tarafından yapılacaktır. İstediğiniz zaman size verilmiş olan kurumumuza ait iletişim kanallarından bizeulaşabilirsiniz.</i>

		
	</div>
		<ul  style="list-style-type: square;margin-top:0px;">
			<li>   İznim olmaksızın tarafım üzerinde herhangi bir tıbbi müdahale, tedavi zorunlu olmadıkça uygulanamayacağı bana anlatıldı ve anladım </li>
			<li> Yukarıda  DERMAROLLER (MİKROİĞNELEME) tedavisi yapılmadan önce verilmesi gereken bilgileri içeren metni okudum. Uygulanacak yöntemin beklenen etkisini ve risklerini anladım. </li>
			<li>   Ayrıca diğer tedavi seçenekleri, muhtemel sonuçları ve riskleri bana anlatıldı ve bu işlem hakkında bana yazılı ve sözlü açıklamalar yapıldı, gerekli uyarılarda bulunuldu ve anladım.  </li>
			<li>  Uygulanacak olan işlem seçenekleri ile ilgili ve bunların riskleriyle ilgili soru soracak durumda idim. Sorularım ve endişelerim beni tatmin edecek ölçüde tartışıldı ve cevaplandırıldı.  </li>
					<li>   Bana yapılacak işlemin etkinliğini değerlendirmek amacıyla, işlem öncesinde, sırasında ve sonrasında görsel materyal örnekleri (fotoğraf gibi) alınabileceği ifade edildi ve kabul ettim.   </li>
		<li>
				  Bana yapılacak işlem sonucunda hiçbir garanti verilmediğini anladım. 
			</li>
			<li>   Bu tedaviyi almam konusunda herhangi zorlayıcı bir davranışla karşılaşmadım </li>
			<li> Bu koşullarda  DERMAROLLER (MİKROİĞNELEME) ile tedavi olmayı ve bu tedavi için gerekli maliyeti ödemeyi kendi rızamla kabul ediyorum.  </li>
			
		
	
		</ul>


		
	</div>
			<div style="width: 300px;float: left;height: 100px;margin-top: 10px;">
		<h5 style="text-align: center;margin-top: -10px"><u>İŞLEM YAPILAN KİŞİNİN</u></h5>
		<p style="font-size: 12px;margin-top: -20px">Adı ve Soyadı : {{$arsiv->musteri->name}} </p>
		
		<p style="font-size: 12px;margin-top: 0px">İmzası : <img src="{{$arsiv->musteri_imza}}" style="height: 70px;"></p>

		<p style="font-size: 12px;margin-top: -10px">Tarih : {{date('d/m/Y',strtotime($arsiv->created_at))}} </p>

			<p style="font-size: 12px;margin-top: 0px">
			(*) Hastanın reşit olmaması durumunda yasal vasi tarafından imzalanır. 
		</p>

		
	
	</div>
	<div style="width: 300px;float: left;height: 100px;margin-top: 0px;">
		<h5 style="text-align: center;margin-top: -10px"><u>İŞLEMi YAPAN KİŞİNİN</u></h5>
		<p style="font-size: 12px;margin-top: -20px">Adı ve Soyadı : {{$arsiv->personel->personel_adi}}</p>
		<p style="font-size: 12px;margin-top: 0px">İmzası : <img src="{{$arsiv->personel_imza}}" style="height: 70px;"></p>
		<p style="font-size: 12px;margin-top: -10px">Tarih : {{date('d/m/Y',strtotime($arsiv->created_at))}}</p>
	</div>

		
		
</body>
</html>