<?php

namespace App\Support;

/**
 * Türkçe ad-cinsiyet tahmin helper.
 *
 * Kullanım:
 *   CinsiyetTahmin::tahmin('Ahmet Yılmaz')   // 1 (erkek)
 *   CinsiyetTahmin::tahmin('Ayşe Kaya')      // 0 (kadın)
 *   CinsiyetTahmin::tahmin('Deniz Demir')    // null (uniseks/bilinmeyen)
 *
 * 0 = Kadın, 1 = Erkek, null = Belirlenemedi (mevcut sistemle uyumlu).
 */
class CinsiyetTahmin
{
    private static $cache = null;

    public static function tahmin($adSoyad)
    {
        if (empty($adSoyad)) {
            return null;
        }
        $temiz = self::normalize($adSoyad);
        if ($temiz === '') {
            return null;
        }
        $kelimeler = preg_split('/\s+/', $temiz, -1, PREG_SPLIT_NO_EMPTY);
        if (empty($kelimeler)) {
            return null;
        }
        $sozluk = self::sozluk();
        // Önce ilk iki kelimeye bak (ad veya bileşik adın bir parçası)
        $bakilacak = array_slice($kelimeler, 0, 2);
        foreach ($bakilacak as $kelime) {
            if (isset($sozluk[$kelime])) {
                return $sozluk[$kelime];
            }
        }
        return null;
    }

    private static function normalize($s)
    {
        $s = mb_strtolower((string) $s, 'UTF-8');
        $s = strtr($s, [
            'ş' => 's', 'Ş' => 's',
            'ı' => 'i', 'İ' => 'i',
            'ğ' => 'g', 'Ğ' => 'g',
            'ü' => 'u', 'Ü' => 'u',
            'ö' => 'o', 'Ö' => 'o',
            'ç' => 'c', 'Ç' => 'c',
            'â' => 'a', 'Â' => 'a',
            'î' => 'i', 'Î' => 'i',
            'û' => 'u', 'Û' => 'u',
        ]);
        // alfanumerik dışındakileri (noktalama, semboller) boşluğa çevir
        $s = preg_replace('/[^a-z0-9\s]/u', ' ', $s);
        return trim(preg_replace('/\s+/', ' ', $s));
    }

    /**
     * Lazy yüklenen ad → cinsiyet sözlüğü.
     * 0 = Kadın, 1 = Erkek. Uniseks/belirsiz adlar burada YOKTUR.
     */
    private static function sozluk()
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $erkek = self::erkekAdlari();
        $kadin = self::kadinAdlari();

        $map = [];
        foreach ($erkek as $ad) {
            $map[$ad] = 1;
        }
        foreach ($kadin as $ad) {
            // Uniseks koruması: aynı ad iki listede varsa kaldır (null kalsın)
            if (isset($map[$ad])) {
                unset($map[$ad]);
                continue;
            }
            $map[$ad] = 0;
        }
        self::$cache = $map;
        return $map;
    }

    private static function erkekAdlari()
    {
        return [
            // A
            'abdulalim','abdulaziz','abdulbaki','abdulbasit','abdulcebbar','abdulcelil','abdulehat','abdulgaffar','abdulgafur','abdulgani',
            'abdulhadi','abdulhakim','abdulhakkim','abdulhalik','abdulhalim','abdulhamit','abdulhamid','abdulhanif','abdulhay','abdulkadim',
            'abdulkadir','abdulkahhar','abdulkerim','abdullah','abdullatif','abdulmecit','abdulmecid','abdulmelik','abdulmenan','abdulmuti',
            'abdulnasir','abdulrauf','abdulsamed','abdulvahap','abdulvahit','abdulvedud','abdurrahim','abdurrahman','abdusselam','adak',
            'adem','adeson','adil','adilcan','adler','adnan','adsiz','afnan','agah','agir','ahad','ahmet','akay','akbar','akbulut','akdemir',
            'akgun','akif','akim','akin','akkan','akman','aksu','aktan','aktar','aktug','alaaddin','alaattin','alemdar','alemsah','algan',
            'algun','ali','alican','alihan','alim','alkan','alkin','alpamis','alparslan','alpaslan','alpay','alper','alperen','alphan',
            'alpman','altan','altay','altug','altun','altunkaya','anar','anil','ardal','ardalan','arda','arif','arman','armagan','arsen',
            'arsin','arslan','asaf','asil','asim','askin','aslan','ata','atak','atakan','ataman','atalay','atan','atay','atif','atik',
            'atil','atilla','atinc','atlas','attila','avni','ayaz','aybars','ayberk','aydeniz','aydil','aydin','aydogan','aydogdu',
            'ayhan','aykan','aykut','aymaz','aytac','aytek','aytekin','ayverdi','ayvaz','ayyub','ayyup','azat','azer','aziz',
            // B
            'babacan','babur','baha','bahaddin','bahadir','bahattin','bahri','baki','balaban','balamir','balkan','baran','baris','barkin',
            'barlas','barlik','basar','basat','baskan','basri','batikan','batir','batu','batuhan','batur','baturhan','baykal','baykan',
            'bayar','bayazit','baybars','bayer','bayhan','baykara','baylan','bayram','bayrak','bayrakli','bedi','bedih','bedihi','bedirhan',
            'bedrettin','bedreddin','bedri','bektas','behcet','behlul','behram','behzad','behzat','bekir','bener','beni','benyamin','berat',
            'berca','berdan','berk','berkan','berkant','berkay','berke','berker','berkin','berkun','bertan','besir','beytullah','bilal',
            'bilen','bilge','bilgehan','bilgi','bilgin','binali','binyamin','birant','birdal','birgen','birhan','birkan','birol',
            'bogac','boran','bora','borer','bozkurt','bugra','bukran','bulent','burak','burhan','burhanettin','burhanedin',
            // C
            'cafer','caferi','cahit','can','canbey','canberk','candar','candas','caner','cangir','canip','capraz','caniel','casim','cebbar',
            'cebrail','cefer','celal','celalettin','celasin','celebi','celil','cem','cemal','cemalattin','cemaleddin','cemil','cemiloglu',
            'cemsit','cenab','cenan','cenger','cengiz','cenk','cenker','cetin','cevad','cevahir','cevat','cevdet','cevheri','cevri','cezayir',
            'cezmi','cihan','cihanbey','cihangir','cihat','comert','coskun','cumhur','cuneyit','cuneyt',
            // D
            'daghan','danyal','dara','daran','darga','davut','demir','demirhan','demirsoy','devlet','devran','devrim','dilaver','dincer',
            'dogan','dogu','dogukan','doruk','dorukhan','dost','durmus','durul',
            // E
            'ebubekir','edib','edip','ediz','efe','efecan','efkan','ekber','ekin','ekrem','eldar','elnur','elman','emin','emir','emirhan',
            'emrah','emre','emrullah','ender','enes','engin','enis','enver','eralp','eraslan','ercan','ercivan','ercument','erdal','erdem',
            'erden','erdi','erdinc','erdogan','eren','erenay','erender','erfan','ergin','ergun','erhan','erim','erinc','erkam','erkan',
            'erkin','erkut','erman','erol','ersan','ersin','ersoy','ertac','ertan','erten','ertekin','ertugrul','ertunc','erturk','esad',
            'esat','esref','evren','eyup','eyub',
            // F
            'fadil','fahretdin','fahrettin','fahri','faik','faruk','fatih','fatin','fazil','fazli','fehmi','ferdi','ferec','ferhad',
            'ferhan','ferhat','feridun','ferit','ferman','ferruh','fethi','fethullah','fevzi','feyzi','fikret','fikri','firat','firuz','fuat',
            'furkan',
            // G
            'gani','gener','goksen','gokay','gokberk','gokdemir','gokhan','gokmen','goktug','gokturk','goksel','gonensoy','gorkem','govan',
            'gultekin','guncel','gungor','gunkut','gurbuz','guven','guvenc',
            // H
            'habil','hacibey','hadi','hafiz','hakan','hakki','halid','halil','halilcan','halim','halis','halit','haluk','hami','hamdullah',
            'hamdi','hamit','hamza','hanefi','hanifi','harun','hasan','hasip','haskar','hatim','haydar','hayrettin','hayri','hayrullah',
            'hicabi','hidayet','hidir','hikmet','hilmi','hilmican','hizir','hudaverdi','hulki','husam','husamettin','husameddin','huseyin',
            'husnu','huzur',
            // I/İ
            'ibo','ibrahim','idris','ihsan','iken','ikram','ilbey','ilbeyi','ildem','ilhami','ilhan','ilkay','ilker','ilkim','ilkin','ilyas',
            'imdat','irfan','isa','isam','ishak','iskender','islam','ismail','ismet','izet','izzet','izzettin',
            // K
            'kaan','kadem','kadir','kahraman','kamer','kamil','kamilcan','kamuran','kanat','kara','karaca','kasim','kavur','kayan','kayhan',
            'kayhanlar','kazan','kazim','kelami','kemal','kemaleddin','kemalettin','kemali','kenan','kerami','kerem','kerimcan','kerim',
            'keskin','kivanc','kor','koray','korhan','korkmaz','kozan','kubilay','kudret','kudus','kursad','kursat','kutay','kuthan',
            'kutkan','kutlu','kutlukhan','kutsal','kutub','kuvvet',
            // L
            'laden','latif','leon','levent','lokman','lutfi','lutfu','lutfullah',
            // M
            'maaruf','macit','mahir','mahmut','mahsun','makbul','malik','mansur','masum','mazlum','meco','mecit','mecnun','medet','mehdi',
            'mehmed','mehmet','melih','melihcan','melik','melikhan','meliksah','memduh','memo','menderes','menes','mert','mertcan','merthan',
            'mesih','mesut','metehan','methi','metin','mevlut','mihrac','mirac','mirsat','muammer','muaz','mubarek','mubin','mucahit','mucip',
            'muhammed','muhammet','muharrem','muhip','muhittin','muhsin','muhtar','mukremin','munir','murat','murathan','musa','mustafa',
            'muvahhid','muzaffer','mufid','mujdat','muhtesem','mursel','muslum',
            // N
            'nabi','naci','nadi','nadir','nahit','nail','naim','naki','namik','nasi','nasir','nasrettin','nasrullah','nazil','nazim',
            'neamettin','necati','necdet','necip','necmettin','necmi','nedim','nejat','nejdet','nesim','nesir','neset','nesat','nevzat',
            'neyzen','nezih','nezir','nihat','niyazi','nizam','nizamettin','nizar','nuh','numan','nuretdin','nurettin','nuri','nurullah',
            'nuruddin','nusret',
            // O
            'oben','oder','oguz','oguzhan','okan','okay','oktay','olcay','olgun','omer','omur','onat','onder','ongun','onuralp','onur','oral',
            'orcan','orcun','orhan','orkun','orkut','oruc','osman','oytun','ozan','ozay','ozbay','ozdemir','ozeden','ozhan','ozcan','ozkan',
            // P
            'paklan','pala','partal','payidar','pehlivan','pekel','peker','peyami','polat','polater',
            // R
            'raci','raden','ragip','rahmi','rahim','ramazan','rami','ramiz','rasel','rasih','rasim','rauf','recai','recep','refet','refi',
            'refik','remzi','resit','resul','ridvan','rifat','riza','rusen','ruhi','rustem','ruzgar',
            // S
            'sabahattin','sabit','sabri','sacit','sadeddin','sadettin','sadi','sadik','sadrettin','saffet','sait','salahattin','salih',
            'salim','salman','sami','samil','samim','samir','samet','sancar','sani','sarp','sarphan','savas','sayit','sebahattin','sedat',
            'sefa','sefer','seferhan','sefik','sehmus','sekip','selami','selcuk','selim','selimcan','selman','semih','senol','serafettin',
            'serbay','sercan','serdar','serdal','serdivan','serhan','serhat','serkan','serkant','sermet','servet','sever','sevki','sevket',
            'seydi','seyfettin','seyfi','seyhmus','seyhun','seyit','seyitcan','sezai','sezgin','siddik','sinan','sinasi','sirac','sitki',
            'soner','suat','sukru','suleyman','sungur','suphi','surur',
            // S (s harfi farkli yazimlar)
            'sahin','sahindere','sener','seref','serif',
            // T
            'taci','tahir','tahsin','talas','talat','talha','taner','taneray','tanju','tanzer','tarcan','tarik','tarkan','tasin','tayfun',
            'taylan','tayyar','tayyip','tekin','temel','teoman','tevfik','tezcan','timur','timucin','tolga','tolgahan','toygar','tufan',
            'tugay','tugcan','tugkan','tugrul','tuna','tuncay','tunc','tuncel','tuncer','turab','turan','turgay','turgut','turhan','turker',
            'turkmen',
            // U/Ü
            'ubeydullah','ufuk','ugur','ugurcan','ulas','ulasalp','ulvi','umit','unal','unsal','ural','uras','urcun','usame','ustun','utku',
            'uygar','uygun','uzay','uzeyir',
            // V
            'vahap','vahit','vakkas','varol','vasif','vatan','vecdi','vecihi','vedat','veli','vehbi','veysel','veysi','vural',
            // Y
            'yagiz','yahya','yakup','yaman','yamac','yasar','yasin','yasir','yavuz','yener','yetkin','yilmaz','yildirim','yigit','yigithan',
            'yunus','yusuf','yuksel','yusufcan',
            // Z
            'zafer','zafercan','zahir','zahit','zekai','zekeriya','zeki','zerdest','zihni','zikri','zikrullah','ziya','ziyaettin','zubeyir',
        ];
    }

    private static function kadinAdlari()
    {
        return [
            // A
            'abide','acelya','adalet','adel','adile','adviye','afet','afife','afitap','afra','ahsen','ahter','ahu','akgul','alanur','aleyna',
            'aliye','almila','almira','alya','ananur','andac','arzu','asena','asife','asiye','asli','asuman','asya','atike','atiye',
            'aybike','ayca','aycan','ayda','aydanur','aydan','ayfer','aygul','aygun','aylin','aynur','aysel','aysenur','aysu','aysun','ayse',
            'aysecan','aysegul','aysen','ayten','aytug','ayyildiz','azime','aziye',
            // B
            'bade','bahar','baharnur','bahriye','balnur','banuse','banucicek','banu','basak','bedia','beden','behice','behiye','behnaz',
            'bekriye','belen','belgin','belgizar','beliz','belkis','belkize','belkiz','belma','belris','benay','bendegul','benginur',
            'bengisu','bengul','bengu','benu','beren','berfin','berfu','berican','berice','berin','berivan','berksu','berna','bernanur',
            'berra','berrak','berrin','betinur','betul','betulnur','beyhan','beyza','beyzanur','bezar','biken','biket','billur','binaz',
            'binnaz','binnur','birgul','birsen','birsel','buket','bukre','burcin','burcu','burcunur','busra',
            // C
            'cana','canan','candan','canset','ceci','cefagul','cemnur','cemile','ceyda','cevriye','ceylan','cicek','cigdem','cilvenaz',
            // D
            'defne','demet','derya','didem','dilan','dilara','dilay','dildar','dilek','dilfeza','dilrize','dilsah','dudu','duru','durusu',
            'duygu','duygun',
            // E
            'ebrar','ebru','ece','eda','edibe','ela','elcin','elif','elifnur','elifsu','eliz','elma','elmas','elsa','elvan','emel','emine',
            'emire','emirenur','emrenur','enise','erhanim','erva','ervanur','eser','esma','esmer','esra','esmeray','esrasu',
            'eylul','ezgi',
            // F
            'fadime','fahriye','fatima','fatma','fatmagul','fatmanur','fazilet','fazile','feray','ferda','ferdane','feride','feriha',
            'feryal','fevziye','feyha','feyzanur','feyza','fidan','figen','filiz','firdes','firdevs','fitnat','fulden','fulin','fulya',
            'funda','fusun',
            // G
            'gamze','gaye','gayenur','gisem','gizem','gokce','gokcen','gokcenur','gonca','gonul','gorsen','gozde','gulay','gul','gulafer',
            'gulanur','gulbahar','gulben','gulberk','gulcan','gulcin','guldane','guler','gulferiye','gulgun','gulhan','gulizar','gulnaz',
            'gulnar','gulnihal','gulnur','gulpembe','gulrana','gulsah','gulsen','gulseren','gulsum','gulten','gulyaz','gunay','guzide','guzin',
            // H
            'hacer','hafize','halime','hamide','hamiyet','hamiyye','hanife','hanim','hara','hasibe','hatice','hayal','havva','hayriye','hilal',
            'hilmiye','hira','huda','hulya','humeyra','hurkus','hurriye','husniye',
            // I/İ
            'ibtihal','icim','ifakat','ilknur','inci','ipek','ipeknur','irem','isil','ismihan','isra','izlem',
            // J
            'jale','jalenur','julide',
            // K
            'kader','kamile','kerime','kevser','kismet','kiymet','kiymetli','koral','kubra','kumru',
            // L
            'lale','latife','lebibe','leman','lerzan','leyla','lutfiye',
            // M
            'mahbube','makbule','masal','mehnaz','mehribahar','mehriban','mehtap','mehlika','mehlikanur','melahat','melda','melek','melike',
            'melisa','meltem','meneksenur','menekse','meral','merve','meryem','mesude','mihribahar','mihriban','mihrinaz','mine','miray',
            'mubeccel','mubera','mucahide','mukerrem','muge','mujde','mujgan','munevver','munire','muyesser','muzeyyen',
            // N
            'nadide','nafia','naile','naime','naciye','nazan','nazenin','nazli','nazlican','nazmiye','nebahat','nebile','necla','nehrim',
            'nejla','nejlanur','neriman','nese','nesibe','neslihan','nesrin','nesteren','neva','nevin','nevra','nezahat','nezehat','nezihe',
            'nigar','nihal','nihan','nilay','nilgun','nilufer','nimet','nisa','nisanur','nizaket','nuran','nuray','nurbahar','nurban','nurben',
            'nurbike','nurcan','nurcihan','nurdan','nurefsa','nurgun','nurhan','nurhayat','nuriye','nurseli','nursen','nursima','nurtan','nurten',
            // O
            'ofelia','ozge','ozgun','ozlem','oya','oyku',
            // P
            'pakize','parla','peri','pelin','pelinsu','perican','perihan','perim','pinar','piruze',
            // R
            'rabia','ramize','ravza','rayhan','raziye','refia','refika','remziye','resmiye','reyhan','rojda','rojin','rumeysa','rumeysanur',
            'ruya','ruveyda',
            // S
            'sabahat','sabire','sabriye','sadika','sadiye','safak','safiye','sakine','salime','samiye','sanem','sare','saryanur','sarya',
            'sati','satiye','saziye','seba','sebahat','sebnem','secil','secilcan','seda','sedef','sedefcan','seher','selcen','selcennur',
            'selda','selen','selin','selma','selvi','selvinaz','sema','semin','semiha','semra','sena','senanur','senay','sengul','sennur',
            'serap','serfiraz','serihan','serma','sermin','serna','serpil','servinaz','sevcan','sevda','sevde','sevdican','sevgi','sevigul',
            'sevil','sevilay','sevim','sevime','sevinc','sevincan','seyhan','sezen','sidika','sila','sibel','simay','simge','simten','sinem',
            'sirin','sirma','sumeyye','sumeyra','songul','sukran','sultan','suna','suzan','suheyla','suheyda',
            // T
            'taban','tahire','talia','tanay','tane','tansel','tansu','telli','tomris','tuanur','tuana','tuba','tubanur','tugba','tugce',
            'tulay','tulin','turkan','tutku',
            // U/Ü
            'ulker','ulgun','umran','ummuhan','urunur','ummu',
            // V
            'vahide','vasfiye','vesile','vildan','vuslat',
            // Y
            'yasemin','yeliz','yeter','yildiz','yusra',
            // Z
            'zahide','zarife','zehra','zekiye','zeliha','zerrin','zeynep','zubeyde','zuhre','ziynet','zumra',
        ];
    }
}
