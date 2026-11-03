<?php

namespace App\Domain\Form;

/**
 * Hizmet sözleşmesi FABRİKA (varsayılan) şartlar metni — TEK KAYNAK.
 * Eskide iki kopyası vardı (web sozlesme-olustur-modal + ApiController@_sozlesmeFabrikaMetni);
 * metin birebir aynıdır. Salon kendi metnini kaydettiyse (salonlar.sozlesme_varsayilan_metin)
 * o kullanılır.
 */
final class SozlesmeMetni
{
    public static function sec(?string $salonMetni): string
    {
        $salonMetni = trim((string) $salonMetni);

        return $salonMetni !== '' ? $salonMetni : self::fabrika();
    }

    public static function fabrika(): string
    {
        return <<<'SOZLESME_TXT'
1- SÖZLEŞMENİN KONUSU VE KAPSAMI
İşbu sözleşmenin konusu, MÜŞTERİ tarafından MERKEZ'den satın aldığı aşağıda detayları belirtilen lazer, bakım ve güzellik hizmetlerinin (bundan böyle "HİZMET" olarak anılacaktır) şartlarının, hizmetlerin sunulmasının, ödeme koşullarının ve tarafların hak ve yükümlülüklerinin belirlenmesidir.
2- ÖDEME ŞEKLİ VE KOŞULLARI
2.1- ÖDEME YÖNTEMİ
Nakit, Kredi Kart (tek çekim, taksit), elden taksit (vade tarihleri ekli ödeme planında belirtilir)
2.2- Müşteri taksitli işlemlerde ödemeleri belirtilen vadelerde yapmakla yükümlüdür. Ödemelerin gecikmesi durumunda MERKEZ, yasal faiz talep etme ve kalan borcunun tamamını muaccel kılma hakkını saklı tutar.
2.3- Hizmet bedeli ödenmeden veya ödeme planına uygulamadan hizmetin ifasına devam edilip edilmeyeceği MERKEZ'in inisiyatifindedir.
3- TARAFLARIN HAK VE YÜKÜMLÜLÜKLERİ
3.1- MERKEZ'İN YÜKÜMLÜLÜKLERİ
Merkez, hizmeti mesleki standartlara uygun, hijyen kurallarına bağlı, konusunda uzman personel tarafından ve taahhüt edilen standartlarda sunmak, kullanılan cihaz ve ürünlerin standartlara uygunluğunu sağlamak ve müşteriye sözleşme şartlarına uygun olarak hizmet vermekle yükümlüdür.
3.2- MÜŞTERİ'NİN YÜKÜMLÜLÜKLERİ VE SAĞLIK BEYANI
Sağlık Beyanı: Müşteri, hamilelik, epilepsi, kalp pili, açık yara, cilt hastalıkları, kanser tedavisi, hormon bozuklukları veya düzenli kullandığı ilaçlar gibi hizmetin uygulanmasında engel olabilecek veya risk oluşturabilecek tüm sağlık durumlarını MERKEZ'e yazılı olarak bildirmek zorundadır.
Müşteri, yanlış veya eksik sağlık beyanından kaynaklanabilecek komplikasyonlardan, yan etkilerden veya hizmetin sonuç vermemesinden MERKEZ'in sorumlu tutulmayacağını kabul ve beyan eder.
İşlem Sonrası Bakım: Müşteri, işlem sonrasında kendisine iletilen (güneşten korunma, su teması vb.) bakım talimatlarına uymak zorundadır. Talimatlara uyulmaması sonucu oluşacak leke, tahriş veya sonuç almama durumlarında MERKEZ sorumlu değildir. Müşteri, işbu sorumluluğun kendisinde olduğunu kabul ve beyan edip, tüm talimatlara eksiksiz uyacağını taahhüt eder.
3.3- TIBBİ İŞLEM UYARISI
Müşteri, MERKEZ'de uygulanan işlemlerin birer "tıbbi tedavi" veya "hastalık teşhis, tedavi yöntemi" olmadığını ve bakım amaçlı uygulamalar olduğunu, %100 sonuç garantisi verilmeyeceğini (kıl yapısı, hormon dengesi, cilt tipi gibi biyolojik faktörlere bağlı olarak) bildiğini kabul eder. Ve hizmetin etkilerinin kişisel özelliklere göre değişebileceğini, garanti sonuç talep etmeyeceğini, kendisinden kaynaklı bir durum ortaya çıktığında bunun MERKEZ'den kaynaklı olmadığını kabul ve beyan eder.
4- RANDEVU, İPTAL VE ERTELEME POLİTİKASI
4.1- MERKEZ, planlanmış randevulara ilişkin hatırlatma mesajını müşterinin bildirdiği telefon numarasına SMS, WhatsApp yolu ile bilgilendirme yapmakla yükümlüdür.
4.2- Müşteri de randevu saatine tam zamanında gelmekle yükümlüdür. 15 dakikayı aşan gecikmelerde MERKEZ, seansı iptal etme ve süreyi kısaltma hakkına sahiptir.
4.3- Randevu iptali veya erteleme talepleri, randevu saatinden en az 24 saat önce MERKEZ'e bildirilmelidir.
4.4- Mazeretsiz Gelmeme (No-Show): 24 saat önceden haber verilmeksizin randevuya gelinmemesi durumunda, ilgili seans "kullanılmış, yapılmış" sayılır ve paket hakkından düşülür. Müşteri bu durumda herhangi bir hak iddia edemez. Müşteri bu durumu eksiksiz kabul ve beyan eder.
4.5- Alınan hizmet paketleri, sözleşmede belirtilen süre içerisinde kullanılmalıdır. MÜŞTERİ'nin kendi kusurlarından kaynaklanan gecikmelerde süre uzatımı talep edemez. Ancak MERKEZ mücbir bir sebep varlığında ya da işletmeden kaynaklı zorunluluklar halinde süre uzatımı yapabilir.
5- CAYMA HAKKI, FESİH VE İADE KOŞULLARI
5.1- Cayma Hakkı: Müşteri sözleşmenin imzalandığı tarihten itibaren 14 (on dört) gün içinde, hizmet alımına başlanmamış olması kaydıyla, herhangi bir gerekçe göstermeksizin ve cezai şart ödemeksizin sözleşmeden cayma hakkına sahiptir.
5.2- Hizmet Başladıktan Sonra Fesih: Hizmetin ifasına başlandıktan (ilk seans yapıldıktan) sonra mücbir nedenlerle sözleşmenin feshedilmesi durumunda; kullanılan seanslar liste fiyatı (indirimli paket fiyatı değil, tek seans birim fiyatı) üzerinden hesaplanır. Toplam ödenen tutardan, kullanılan seansların liste fiyatı bedeli düşülerek kalan tutar iade edilir. Ancak MÜŞTERİ tarafından keyfi nedenlerle sözleşmenin feshedilmesi durumunda işbu sözleşme muaccel hale gelir ve ödenen bedeller geri iade edilmez. Müşteri bunu kabul ettiğini beyan eder.
5.3- MERKEZ'den kaynaklanan kusurlu hizmet (ayıplı hizmet) durumunda, MÜŞTERİ'nin 6502 sayılı kanundan doğan bedel iadesi veya hizmetin yeniden görülmesi hakları saklıdır.
6- HİZMETİN DEVİR VE İADESİ
6.1- İşbu yapılan hizmet sözleşmesi sadece sözleşmeyi imzalayan MÜŞTERİ'yi bağlar. Alınan hizmet herhangi başka birine devredilemez.
6.2- MÜŞTERİ tarafından alınan hizmet bir başkasına satılamaz ve ücret yerine kullanılamaz.
6.3- Müşteri getireceği Resmi Sağlık Kurumu Raporu ile hizmetin kesin olarak alınamayacağını belgelemesi halinde kullanılmayan seansların bedeli iade edilir.
6.4- Peşin ödemelerde yasal zorunluluklar dışında iade yapılmaz.
6.5- MÜŞTERİ, kendisi adına uygulanmış olan kampanya, indirim veya özel fiyatla alınan hizmetleri farklı biri üzerinde kullanamaz.
7- KİŞİSEL VERİLERİN KORUNMASI (KVKK)
7.1- MÜŞTERİ, bu sözleşme kapsamında verdiği kişisel verilerin (kimlik, iletişim, sağlık bilgileri, işlem öncesi ve sonrası fotoğraflar, rıza dahilinde çekilen videolar ve fotoğraflar vb.) 6698 sayılı KVKK kapsamında hizmetin ifası, randevu takibi ve yasal yükümlülükler nedeniyle MERKEZ tarafından işlenmesine, saklanmasına ve mevzuatın izin verdiği kurumlarla, MERKEZ'in yönettiği sosyal medya hesaplarında (Instagram, Facebook, TikTok vb.) paylaşılmasına açık rıza gösterdiğini beyan eder.
7.2- Müşteri yukarıda belirtilen ve MERKEZ'in sosyal medya hesaplarında paylaşılması için video, fotoğraf, görüntü vb. gibi alınan içeriklerin paylaşılmasına açık rıza göstermiyorsa işbu sözleşme ile birlikte imzalanan KVKK aydınlatma metni ve açık rıza formu imzalatılmıştır.
8- YETKİLİ MAHKEMELER VE YÜRÜRLÜK
İşbu sözleşmeden doğacak uyuşmazlıklarda, Tüketici Hakem Heyetleri ve ......................................... Mahkemeleri ve İcra daireleri yetkilidir. İşbu sözleşme 8 (sekiz) maddeden ibaret olup taraflarca iki nüsha olarak tanzim ve imza edilmiştir.
SOZLESME_TXT;
    }
}
