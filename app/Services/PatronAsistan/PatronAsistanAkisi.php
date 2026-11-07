<?php

namespace App\Services\PatronAsistan;

use App\Exceptions\GecersizIslemException;
use App\Models\Salonlar;

/**
 * Patron asistani akisi — eski ApiController::patronAsistanSorApi/UygulaApi'nin HTTP'den ayrilmis hali.
 * Niyet + metin PatronAsistanServisi'nde, rakam PatronAsistanRaporServisi'nde, kampanya
 * PatronAsistanKampanyaServisi'nde. Kimlik, kapsam ve yetki controller'da. Sira eskiyle aynidir, degistirmeyin:
 * kufur, kalip devami, oneri, kampanya, bilanco, karsilastirma, degerlendirme, niyet.
 */
class PatronAsistanAkisi
{
    public function __construct(
        private PatronAsistanServisi $asistan,
        private PatronAsistanRaporServisi $rapor,
        private PatronAsistanKampanyaServisi $kampanya,
    ) {}

    /** @return array<string,mixed> {basarili, cevap, seslendir, kart, intent, aksiyon?} */
    public function sor(int $salonId, int $uid, string $metin): array
    {
        $this->asistan->aktifSalonId = $salonId; // AI kullanim logu bu salona yazilsin

        // Terbiyesizlik/kufur -> niyet cozmeden ONCE nazik uyari (gecmise yazilmaz).
        if ($this->asistan->kufurMu($metin)) {
            return $this->asistan->kufurCevabi();
        }

        // KONUSMA BELLEGI: takip sorulari icin son turlar.
        $gecmis = $this->asistan->gecmisGetir($uid);
        $cevap = $this->kuralCevabi($metin, $uid, $salonId)
            ?? $this->niyetCevabi($metin, $uid, $salonId, $gecmis);

        return $this->sonlandir($cevap, $uid, $metin);
    }

    /** Onaylanmis kampanyayi uygular; cevap metinleri eskiyle ayni. */
    public function uygula(int $salonId, int $uid, string $tur, string $tip, int $oran): array
    {
        if ($tur !== 'kampanya') {
            throw new GecersizIslemException('Bilinmeyen işlem.');
        }
        $salonAdi = Salonlar::where('id', $salonId)->value('salon_adi');

        // GUVENLI TEST kodlamasi: tip = "test|<gercek_tur>|<user_id>" ise kampanya SADECE o tek kisiye gider.
        $testUserId = 0;
        if (str_starts_with($tip, 'test|')) {
            $parca = explode('|', $tip);
            $tip = $parca[1] ?? 'genel';
            $testUserId = (int) ($parca[2] ?? 0);
        }

        $sonuc = $this->kampanya->uygulaReklam($tip, $salonId, $salonAdi, $oran, $testUserId, $uid);
        if (! empty($sonuc['hata'])) {
            return ['basarili' => false, 'seslendir' => true, 'kart' => null, 'cevap' => 'Kampanya oluşturulurken bir sorun oluştu, gönderim yapılmadı.'];
        }

        return ['basarili' => true, 'intent' => 'kampanya_sonuc', 'seslendir' => true, 'kart' => null, 'cevap' => $this->uygulaMetni($sonuc, $testUserId)];
    }

    private function uygulaMetni(array $sonuc, int $testUserId): string
    {
        if ($testUserId > 0) {
            return ((int) $sonuc['sayi'] > 0)
                ? 'Test tamamlandı. Kampanya YALNIZCA seçtiğiniz müşteriye ücretsiz bildirim olarak gönderildi. Başka hiçbir müşteriye gitmedi.'
                : 'Test kampanyası oluşturuldu ve YALNIZCA seçtiğiniz müşteriye tanımlandı. O müşteri şu an anlık bildirim alamıyor olabilir ama uygulamayı açınca görecek. Başka hiçbir müşteriye gitmedi.';
        }
        if ((int) $sonuc['sayi'] === 0) {
            return $sonuc['baslik'].' oluşturuldu. Şu an anlık bildirim alabilecek (uygulamayı kullanan) müşteri bulunamadı ama kampanya uygulama içinde aktif; müşterileriniz uygulamayı açınca görecek.';
        }

        return $sonuc['baslik'].' tamamlandı. Uygulamayı kullanan '.$sonuc['sayi'].' müşterinize yüzde '.$sonuc['oran'].' indirim bildirimi ücretsiz gönderildi. Bildirime dokunan müşteriye kupon anında tanımlanacak.';
    }

    /** Cevap metnini suzer ve konusma gecmisine yazar. */
    private function sonlandir(array $veri, int $uid, string $metin): array
    {
        if (! empty($veri['cevap'])) {
            $veri['cevap'] = $this->asistan->emojiTemizle($this->asistan->buyurunTemizle($veri['cevap']));
            $this->asistan->gecmisEkle($uid, $metin, (string) $veri['cevap']);
        }

        return $veri;
    }

    /** Niyetten once calisan kurallar; hicbiri tutmazsa null. */
    private function kuralCevabi(string $metin, int $uid, int $salonId): ?array
    {
        $asistan = $this->asistan;

        // "biraz daha bilgi ver" -> son kalibin havuzundan yeni cevap (oneriyle cakismasin diye once).
        if ($kalipDevam = $asistan->kalipDevam($metin, $uid)) {
            return $kalipDevam;
        }
        // Salon buyutme danismanligi -> rapor niyetinden ONCE, son ay verisine dayali.
        if ($asistan->salonOnerisiTetikMi($metin)) {
            return $asistan->veriliOneri($this->oneriVerisi($salonId), $uid);
        }
        // Kampanya: burada yalniz TEKLIF (salt okunur); gonderim onay sonrasi uygula ucunda.
        if ($kampanya = $this->kampanya->coz($metin, $salonId)) {
            return $kampanya;
        }
        // Bilanco: ay ay gelir/gider/net (AI yok).
        if ($asistan->bilancoTetik($metin)) {
            return $asistan->cevapBilanco($this->rapor->bilanco($salonId, $asistan->bilancoAySayisi($metin)), Salonlar::where('id', $salonId)->value('salon_adi'));
        }
        if ($asistan->karsilastirmaTetik($metin)) {
            return $this->karsilastirma($metin, $salonId);
        }

        return $this->degerlendirme($metin, $salonId);
    }

    private function karsilastirma(string $metin, int $salonId): array
    {
        $asistan = $this->asistan;
        $veri = $this->rapor;
        $d = $asistan->karsilastirmaDonem($metin);
        if ($asistan->personelSozuVarMi($metin)) {
            return $asistan->cevapPersonelKarsilastirma($veri->personelKarsilastir($salonId, $d['buT1'], $d['buT2'], $d['onT1'], $d['onT2']), $d['buAd'], $d['onAd']);
        }
        $odak = $asistan->karsilastirmaOdak($metin);

        return match ($odak) {
            'urun' => $asistan->cevapKalemKarsilastirma($veri->urunKarsilastir($salonId, $d['buT1'], $d['buT2'], $d['onT1'], $d['onT2']), 'Ürün', $d['buAd'], $d['onAd'], 'Bu iki dönemde karşılaştırılacak ürün satışı bulunamadı.'),
            'hizmet' => $asistan->cevapKalemKarsilastirma($veri->hizmetKarsilastir($salonId, $d['buT1'], $d['buT2'], $d['onT1'], $d['onT2']), 'Hizmet', $d['buAd'], $d['onAd'], 'Bu iki dönemde karşılaştırılacak hizmet bulunamadı.'),
            'randevu' => $asistan->cevapRandevuKarsilastirma($veri->karsilastirmaVeri($salonId, $d['buT1'], $d['buT2']), $veri->karsilastirmaVeri($salonId, $d['onT1'], $d['onT2']), $d['buAd'], $d['onAd']),
            default => $asistan->cevapKarsilastirma($veri->karsilastirmaVeri($salonId, $d['buT1'], $d['buT2']), $veri->karsilastirmaVeri($salonId, $d['onT1'], $d['onT2']), $d['buAd'], $d['onAd'], $odak),
        };
    }

    /** Personel karnesi: 'guclu' kelime isim olmasa da sorar, 'zayif' kelime yalniz personel adi varsa degerlendirir. */
    private function degerlendirme(string $metin, int $salonId): ?array
    {
        $asistan = $this->asistan;
        $tur = $asistan->degerlendirmeTur($metin);
        if ($tur === null) {
            return null;
        }
        $kisi = $asistan->personelMesajdanBul($this->rapor->personelListesi($salonId), $metin);
        if (is_object($kisi)) {
            $gun = $asistan->degerlendirmeGun($metin);
            $detay = $this->rapor->personelDetay($salonId, $kisi->id, date('Y-m-d', strtotime('-'.($gun - 1).' days')), date('Y-m-d'));

            return $asistan->cevapPersonelDegerlendirme($detay, $kisi->personel_adi, $gun);
        }
        if (is_array($kisi) && isset($kisi['coklu'])) {
            return $this->personelCevabi('Birden fazla personel eşleşti: '.implode(', ', array_slice($kisi['adlar'], 0, 5)).'. Hangisini değerlendireyim?');
        }
        if ($tur === 'guclu') {
            return $this->personelCevabi('Hangi personeli değerlendireyim? Adını söyleyip süreyi ekleyebilirsiniz, örneğin Ahmet son yedi gün.');
        }

        return null; // 'zayif' + isim yok -> normal akis
    }

    private function personelCevabi(string $cevap): array
    {
        return ['basarili' => true, 'intent' => 'personel', 'seslendir' => true, 'kart' => null, 'cevap' => $cevap];
    }

    /** Kural motoru, sonra kalip/ogrenilen niyet, sonra Haiku; rapor niyeti yoksa sohbet. */
    private function niyetCevabi(string $metin, int $uid, int $salonId, array $gecmis): array
    {
        $asistan = $this->asistan;
        $niyet = $asistan->niyetCoz($metin);

        if (($niyet['intent'] ?? 'bilinmiyor') === 'bilinmiyor') {
            // Kapanis/tesekkur AI'ya gitmez: yoksa konusma bellegi onceki konuyu surdurur.
            if ($asistan->kapanisMi($metin)) {
                return $asistan->sohbetCevabi($metin) ?: ['basarili' => true, 'intent' => 'sohbet', 'seslendir' => true, 'kart' => null, 'cevap' => 'Rica ederim, ne zaman isterseniz buradayım.'];
            }
            if ($kalip = $asistan->kalipCevabi($metin, $uid)) {
                return $kalip;
            }
            // Ogrenilen niyet yalniz baglamsiz turda (takip sorusu baglama baglidir).
            $ogrenilen = empty($gecmis) ? $asistan->ogrenilenNiyet($metin) : null;
            if ($ogrenilen) {
                $niyet = $ogrenilen;
            } elseif ($aiNiyet = $asistan->niyetCozAI($metin, $gecmis)) {
                $niyet = $aiNiyet;
                if (empty($gecmis)) {
                    $asistan->ogren($metin, $aiNiyet);
                }
            }
        }

        if (($niyet['intent'] ?? 'bilinmiyor') === 'bilinmiyor') {
            if ($sohbet = $this->sohbet($metin, $gecmis)) {
                return $sohbet;
            }
            $this->anlasilmayanKaydet($metin, $salonId);
        }

        return $this->raporCevabi($niyet, $salonId, $uid);
    }

    private function sohbet(string $metin, array $gecmis): ?array
    {
        $asistan = $this->asistan;
        if ($sohbet = $asistan->sohbetCevabi($metin)) {
            return $sohbet;
        }
        // Ogrenilen cevap yalniz baglamsiz turda bedava; takip sorusu AI'ya baglamla gider.
        $ogrenilen = $asistan->ogrenilenSohbet($metin);
        if ($ogrenilen !== null && empty($gecmis)) {
            return ['basarili' => true, 'intent' => 'sohbet', 'seslendir' => true, 'cevap' => $ogrenilen, 'kart' => null];
        }
        // AI'ya giden soru "cevaplanamayan"a yazilir: panelden kalip eklenince bedavaya doner.
        $asistan->cozulemeyenKaydet($metin);
        $ai = $asistan->sohbetAI($metin, $gecmis);
        if ($ai === null || $ai === '') {
            return null;
        }
        if (empty($gecmis)) {
            $asistan->ogrenSohbet($metin, $ai);
        }

        return ['basarili' => true, 'intent' => 'sohbet_ai', 'seslendir' => true, 'cevap' => $ai, 'kart' => null];
    }

    /** Hala anlasilmayan soru dosyaya yazilir, periyodik gozden gecirilip kalip eklenir. */
    private function anlasilmayanKaydet(string $metin, int $salonId): void
    {
        @file_put_contents(
            storage_path('logs/patron-asistan-anlasilmayan.log'),
            date('Y-m-d H:i:s').' | salon='.$salonId.' | '.str_replace(["\n", "\r"], ' ', $metin)."\n",
            FILE_APPEND
        );
    }

    private function raporCevabi(array $niyet, int $salonId, int $uid): array
    {
        $asistan = $this->asistan;
        $veri = $this->rapor;
        [$t1, $t2] = $veri->donemTarih($niyet['donem']);

        return match ($niyet['intent']) {
            'kasa' => $asistan->cevapKasa($veri->kasa($salonId, $t1, $t2), $niyet),
            'personel' => $asistan->cevapPersonel($veri->personel($salonId, $t1, $t2), $niyet),
            'hizmet' => $asistan->cevapHizmet($veri->hizmet($salonId, $t1, $t2), $niyet),
            'urun' => $asistan->cevapUrun($veri->urun($salonId, $t1, $t2), $niyet),
            'musteri' => $asistan->cevapMusteri($veri->musteri($salonId, $t1, $t2), $niyet),
            'ozet' => $asistan->cevapGunOzeti($this->gunOzeti($salonId, $t1, $t2), $niyet),
            // "bugun" -> bugunku liste; "bu hafta"/"bu ay" -> o donemin randevu SAYISI.
            'bugun' => $niyet['donem'] === 'gun'
                ? $asistan->cevapBugun($veri->bugun($salonId), $niyet)
                : $asistan->cevapRandevuDonem($veri->ozet($salonId, $t1, $t2), $niyet),
            'iptal' => $asistan->cevapRandevuDurum($veri->randevuDurum($salonId, $t1, $t2), $niyet),
            'saat' => $asistan->cevapCalismaSaati($veri->calismaSaatleri($salonId), $niyet),
            'oneri' => $asistan->veriliOneri($this->oneriVerisi($salonId), $uid),
            default => $asistan->yardimCevabi($niyet),
        };
    }

    private function oneriVerisi(int $salonId): array
    {
        $veri = $this->rapor;
        [$t1, $t2] = $veri->donemTarih('ay');

        return [
            'kasa' => $veri->kasa($salonId, $t1, $t2),
            'hizmet' => $veri->hizmet($salonId, $t1, $t2),
            'urun' => $veri->urun($salonId, $t1, $t2),
            'personel' => $veri->personel($salonId, $t1, $t2),
            'musteri' => $veri->musteri($salonId, $t1, $t2),
        ];
    }

    /** Zengin gun ozeti: kasa + en cok satilan hizmet + (varsa) urun + en cok islem yapan personel. */
    private function gunOzeti(int $salonId, string $t1, string $t2): array
    {
        $veri = $this->rapor;
        $enHizmet = null;
        $mxH = 0;
        foreach ($veri->hizmet($salonId, $t1, $t2)['hizmetler'] ?? [] as $h) {
            $a = (int) ($h['adet'] ?? 0);
            if ($a > $mxH) {
                $mxH = $a;
                $enHizmet = ['ad' => $h['hizmet_adi'] ?? '', 'adet' => $a, 'ciro' => (float) ($h['ciro'] ?? 0)];
            }
        }
        $enUrun = null;
        $mxU = 0;
        foreach ($veri->urun($salonId, $t1, $t2)['urunler'] ?? [] as $u) {
            $c = (float) ($u['ciro'] ?? 0);
            if ($c > $mxU) {
                $mxU = $c;
                $enUrun = ['ad' => $u['urun_adi'] ?? '', 'ciro' => $c];
            }
        }
        $enPersonel = null;
        $mxP = 0;
        foreach ($veri->personel($salonId, $t1, $t2)['personeller'] ?? [] as $p) {
            $i = (int) ($p['hizmet_say'] ?? 0);
            if ($i > $mxP) {
                $mxP = $i;
                $enPersonel = ['ad' => $p['personel_adi'] ?? '', 'islem' => $i];
            }
        }

        return ['kasa' => $veri->kasa($salonId, $t1, $t2), 'enHizmet' => $enHizmet, 'enUrun' => $enUrun, 'enPersonel' => $enPersonel];
    }
}
