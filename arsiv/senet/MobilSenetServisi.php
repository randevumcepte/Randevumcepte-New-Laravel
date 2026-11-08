<?php

// ARSIV — calismaz, autoload disinda (arsiv/senet/README.md). Geri acmak icin app/Services/Tahsilat/Mobil/ altina tasinir.

namespace App\Services\Tahsilat\Mobil;

use App\Domain\Tahsilat\MobilTahsilatHesabi as Hesap;
use App\Exceptions\BulunamadiException;
use App\Exceptions\CakismaException;
use App\Exceptions\GecersizIslemException;
use App\Models\Adisyonlar;
use App\Models\AdisyonPaketSeanslar;
use App\Models\AdisyonUrunler;
use App\Models\Alacaklar;
use App\Models\RandevuHizmetler;
use App\Models\Randevular;
use App\Models\SenetVadeleri;
use App\Models\Tahsilat;
use App\Support\AktiviteLog;
use App\Support\Idempotency;
use Illuminate\Support\Facades\DB;

/**
 * Eski mobil senetekleguncelle, senetode, senetvadeguncelle. Mobil senedi yalniz yeni acar (adisyon_id ve senet_id
 * gondermez), bu yol tasinmistir. Tek transaction ve idempotency.
 *
 * Eski kodla bilinen farklar (GECIS listesinde):
 *   - On odemeli senet eskide hep 500'dur ($$request->olusturan): burada satir yazilmadan reddedilir.
 *   - Eski kod musteri SMS'inden sonra yetkili_telefonlari'nda (ApiController'da yok) 500 verir, rollback yoktur:
 *     satirlar yazilmis, ekran hata gostermistir. Burada ayni satirlar yazilir, cevap basarilidir, yetkili SMS'i yoktur.
 */
class MobilSenetServisi
{
    /** Eski pakettenrandevuveseansolustur seans ve randevu hizmetine sabit bu personeli yazar; korunur, karar GECIS listesinde. */
    private const ESKI_SEANS_PERSONELI = 183;

    /** Senet dogrulama kodu ayari (salon_sms_ayarlari.ayar_id). */
    private const DOGRULAMA_AYARI = 16;

    public function __construct(private MobilKapsam $kapsam, private MobilTahsilatYazici $yazici, private MobilSms $sms) {}

    public function ekle(int $salonId, int $yetkiliId, array $g): array
    {
        if (Hesap::senetOnOdemesiVarMi($g['on_odeme_tutari'] ?? '')) {
            throw new GecersizIslemException('Ön ödemeli senet kaydedilemedi.');
        }
        $this->kapsam->musteriOlmali($salonId, $g['musteri_id'] ?? null);
        $this->kalemKapsami($salonId, $g);

        $sonuc = Idempotency::calistir('mobil_senet', (string) ($g['idempotency_key'] ?? ''), $yetkiliId, $salonId,
            fn () => $this->yaz($salonId, $yetkiliId, $g));

        if (! $sonuc['replay']) {
            $this->senetSmsi($salonId, $g);
            AktiviteLog::yaz($salonId, $yetkiliId, 'senet_ekle_guncelle', 'senet', $sonuc['senet_id'], 'Vade: '.($g['vade'] ?? '').' - Tutar: '.($g['senet_tutar'] ?? ''), 'Senet oluşturuldu/güncellendi');
        }

        return $sonuc;
    }

    private function kalemKapsami(int $salonId, array $g): void
    {
        $this->kapsam->katalogOlmali($salonId, [
            ...array_map(fn ($id) => ['tip' => 'urun', 'id' => $id], (array) ($g['senet_urun_id'] ?? [])),
            ...array_map(fn ($id) => ['tip' => 'paket', 'id' => $id], (array) ($g['senet_paket_id'] ?? [])),
        ]);
        foreach (['senet_hizmetleri', 'senet_urunleri', 'senet_paketleri'] as $alan) {
            foreach ((array) ($g[$alan] ?? []) as $k) {
                $this->kapsam->personelOlmali($salonId, is_array($k) ? ($k['personel_id'] ?? null) : null);
            }
        }
    }

    private function yaz(int $salonId, int $yetkiliId, array $g): array
    {
        $musteriId = (int) $g['musteri_id'];
        $adisyonId = (int) Adisyonlar::ac($salonId, $musteriId, $yetkiliId, date('Y-m-d'))->id;
        Alacaklar::where('adisyon_id', $adisyonId)->delete();

        DB::table('users')->where('id', $musteriId)->update(['tc_kimlik_no' => $g['tc_kimlik_no'] ?? null, 'adres' => $g['adres'] ?? null]);

        $senetId = (int) DB::table('senetler')->insertGetId([
            'kefil_adi' => $g['kefil_adi'] ?? null, 'kefil_adres' => $g['kefil_adres'] ?? null, 'kefil_tc_vergi_no' => $g['kefil_tc_vergi_no'] ?? null,
            'user_id' => $musteriId, 'adisyon_id' => $adisyonId, 'vade_sayisi' => $g['vade'] ?? null, 'salon_id' => $salonId,
            'olusturan_id' => $yetkiliId, 'senet_turu' => $g['senet_turu'] ?? null, 'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach ((array) ($g['senet_hizmet_id'] ?? []) as $i => $hizmetId) {
            $k = $g['senet_hizmetleri'][$i];
            $this->yazici->hizmetEkle($salonId, $adisyonId, [
                'hizmet_id' => $hizmetId, 'islem_tarihi' => $k['islem_tarihi'], 'islem_saati' => $k['islem_saati'], 'sure' => $k['sure'],
                'fiyat' => $k['fiyat'], 'geldi' => false, 'personel_id' => $k['personel_id'], 'cihaz_id' => $k['cihaz_id'], 'senet_id' => $senetId,
            ]);
        }

        foreach ((array) ($g['senet_urun_id'] ?? []) as $i => $urunId) {
            $k = $g['senet_urunleri'][$i];
            $u = new AdisyonUrunler();
            $u->islem_tarihi = date('Y-m-d');
            $u->adisyon_id = $adisyonId;
            $u->urun_id = $urunId;
            $u->adet = $k['adet'];
            $u->fiyat = $k['fiyat'];
            $u->personel_id = $k['personel_id'];
            $u->senet_id = $senetId;
            $u->save();
        }

        foreach ((array) ($g['senet_paket_id'] ?? []) as $i => $paketId) {
            $k = $g['senet_paketleri'][$i];
            $adisyonPaketId = $this->yazici->paketEkle($adisyonId, [
                'paket_id' => $paketId, 'fiyat' => $k['fiyat'], 'baslangic_tarihi' => $k['baslangic_tarihi'],
                'seans_araligi' => $k['seans_araligi'], 'personel_id' => $k['personel_id'], 'senet_id' => $senetId,
            ]);
            $this->seansVeRandevuUret($salonId, $yetkiliId, $musteriId, $adisyonPaketId, (int) $paketId, $k);
        }

        foreach (Hesap::vadeTarihleri((string) ($g['vade_baslangic_tarihi'] ?? ''), $g['vade'] ?? 0) as $tarih) {
            $v = new SenetVadeleri();
            $v->senet_id = $senetId;
            $v->vade_tarih = $tarih;
            $v->odendi = false;
            $v->tutar = Hesap::vadeTutari($g['senet_tutar'] ?? '0', $g['vade'] ?? 0);
            $v->save();

            $a = new Alacaklar();
            $a->adisyon_id = $adisyonId;
            $a->salon_id = $salonId;
            $a->tutar = $v->tutar;
            $a->planlanan_odeme_tarihi = $v->vade_tarih;
            $a->olusturan_id = $yetkiliId;
            $a->user_id = $musteriId;
            $a->senet_id = $senetId;
            $a->save();
        }

        return ['sonuc' => 'Başarılı', 'senet_id' => $senetId, 'adisyon_id' => $adisyonId];
    }

    /** Eski pakettenrandevuveseansolustur: her hizmetin her seansina seans satiri, seans sirasina gore ortak randevu. */
    private function seansVeRandevuUret(int $salonId, int $yetkiliId, int $musteriId, int $adisyonPaketId, int $paketId, array $k): void
    {
        $randevular = [];
        $saatKilit = $k['seans_baslangic_saati'] ?? null;
        $yeniSaat = $saatKilit;
        $hizmetler = DB::table('paket_hizmetler')->where('paket_id', $paketId)->get(['hizmet_id', 'seans'])->values();

        foreach ($hizmetler as $sira => $h) {
            $sure = (int) DB::table('salon_sunulan_hizmetler')->where('salon_id', $salonId)->where('hizmet_id', $h->hizmet_id)->value('sure_dk');
            $sure = $sure > 0 ? $sure : 60;
            foreach (Hesap::seansTarihleri($k['baslangic_tarihi'] ?? null, (int) $h->seans, $k['seans_araligi'] ?? 0) as $no => $tarih) {
                $seansNo = $no + 1;
                $yeniRandevu = $sira === 0 || count($randevular) < $seansNo;
                if ($yeniRandevu) {
                    $yeniSaat = $saatKilit;
                    $r = new Randevular();
                    $r->user_id = $musteriId;
                    $r->tarih = $tarih;
                    $r->salon_id = $salonId;
                    $r->durum = 1;
                    $r->saat = $saatKilit;
                    $r->olusturan_personel_id = $yetkiliId;
                    $r->salon = 1;
                    $r->save();
                    $randevuId = (int) $r->id;
                    $randevular[] = $randevuId;
                    if ($seansNo === (int) $h->seans) {
                        $yeniSaat = date('H:i', strtotime('+'.$sure.' minutes', strtotime((string) $saatKilit)));
                    }
                } else {
                    $randevuId = $randevular[$seansNo - 1];
                }

                $seans = new AdisyonPaketSeanslar();
                $seans->adisyon_paket_id = $adisyonPaketId;
                $seans->seans_tarih = $tarih;
                $seans->hizmet_id = $h->hizmet_id;
                $seans->seans_no = $seansNo;
                $seans->seans_saat = $yeniSaat;
                $seans->personel_id = self::ESKI_SEANS_PERSONELI;
                $seans->randevu_id = $randevuId;
                $seans->save();

                $rh = new RandevuHizmetler();
                $rh->randevu_id = $randevuId;
                $rh->hizmet_id = $h->hizmet_id;
                $rh->personel_id = self::ESKI_SEANS_PERSONELI;
                $rh->sure_dk = $sure;
                $rh->saat = $yeniRandevu ? $saatKilit : $yeniSaat;
                $rh->saat_bitis = date('H:i', strtotime('+'.$sure.' minutes', strtotime((string) $yeniSaat)));
                $rh->save();
            }
        }
    }

    private function senetSmsi(int $salonId, array $g): void
    {
        $salonTel = DB::table('salonlar')->where('id', $salonId)->value('telefon_1');
        $tel = DB::table('users')->where('id', (int) $g['musteri_id'])->value('cep_telefon');
        $this->sms->gonder($salonId, $tel, date('d.m.Y', strtotime((string) ($g['vade_baslangic_tarihi'] ?? '')))
            .' vade başlangıç tarihli ve tutarı '.number_format(Hesap::tlSayi($g['senet_tutar'] ?? '0'), 2, ',', '.')
            .' TL olan '.($g['vade'] ?? '').' adet vadeden oluşan senediniz oluşturulmuştur. Detaylı bilgi için bize ulaşın. 0'.$salonTel);
    }

    /**
     * Vade odemesi. Kod bos + ayar acik: kod uretilir, SMS gider, `dogrulamavar` doner. Tahsilata kalem payi yazilmaz
     * ve alacak satiri silinmez, eski kod da yazmaz. Eskiden farkli: odenmis vade ikinci kez odenmez.
     *
     * @return array{dogrulamavar:bool, dogrulamayanlis?:bool, odemebasarili?:bool, replay?:bool}
     */
    public function ode(int $vadeId, int $yetkiliId, array $g): array
    {
        [$vade, $senet] = $this->vadeVeSenet($vadeId);
        $salonId = (int) $senet->salon_id;
        $kod = (string) ($g['dogrulama_kodu'] ?? '');

        if ($kod === '' && $this->kodIsteniyorMu($salonId)) {
            $this->kodGonder($vade, $senet);

            return ['dogrulamavar' => true];
        }

        return Idempotency::calistir('mobil_senet_ode', (string) ($g['idempotency_key'] ?? ''), $yetkiliId, $salonId, function () use ($vadeId, $senet, $salonId, $yetkiliId, $g, $kod) {
            $v = SenetVadeleri::where('id', $vadeId)->lockForUpdate()->first();
            // Eski kod: gevsek karsilastirma, kod hic uretilmemisse null == '' dogrudur.
            if ($v->dogrulama_kodu != $kod) {
                return ['dogrulamayanlis' => true, 'dogrulamavar' => false, 'odemebasarili' => false];
            }
            if ((int) $v->odendi === 1) {
                throw new CakismaException('Bu vade zaten ödenmiş.');
            }
            $v->odendi = true;
            $v->odeme_yontemi_id = $g['odeme_yontemi'] ?? null;
            $v->save();

            $t = new Tahsilat();
            $t->user_id = $senet->user_id;
            $t->adisyon_id = $senet->adisyon_id;
            $t->tutar = $v->tutar;
            $t->odeme_tarihi = $g['tarih'] ?? null;
            $t->olusturan_id = $this->yazici->personelId($salonId, $yetkiliId);
            $t->salon_id = $salonId;
            $t->yapilan_odeme = $v->tutar;
            $t->odeme_yontemi_id = $g['odeme_yontemi'] ?? null;
            $t->notlar = $v->senet_id.' nolu senedin '.date('d.m.Y', strtotime((string) $v->vade_tarih)).' tarihli vadesinin ödemesi';
            $t->save();

            AktiviteLog::yaz($salonId, $yetkiliId, 'senet_ode', 'senet_vade', (int) $v->id, (string) $v->senet_id, 'Senet vadesi ödendi');

            return ['dogrulamayanlis' => false, 'dogrulamavar' => false, 'odemebasarili' => true];
        });
    }

    public function vadeGuncelle(int $vadeId, int $yetkiliId, array $g): void
    {
        [$vade, $senet] = $this->vadeVeSenet($vadeId);
        $vade->vade_tarih = $g['tarih'] ?? null;
        $vade->notlar = $g['not'] ?? null;
        $vade->save();
        AktiviteLog::yaz((int) $senet->salon_id, $yetkiliId, 'senet_vade_guncelle', 'senet_vade', $vadeId, null, 'Senet vade tarihi/notu guncellendi');
    }

    /** Vade salonu controller'in kapsam denetimi icin. */
    public function vadeSalonu(int $vadeId): int
    {
        return (int) $this->vadeVeSenet($vadeId)[1]->salon_id;
    }

    /** @return array{0:SenetVadeleri,1:object} */
    private function vadeVeSenet(int $vadeId): array
    {
        $vade = SenetVadeleri::where('id', $vadeId)->first() ?? throw new BulunamadiException('Vade bulunamadı');
        $senet = DB::table('senetler')->where('id', $vade->senet_id)->first() ?? throw new BulunamadiException('Senet bulunamadı');

        return [$vade, $senet];
    }

    private function kodIsteniyorMu(int $salonId): bool
    {
        return (bool) DB::table('salon_sms_ayarlari')->where('salon_id', $salonId)->where('ayar_id', self::DOGRULAMA_AYARI)->value('musteri');
    }

    private function kodGonder(SenetVadeleri $vade, object $senet): void
    {
        $kod = substr(str_shuffle('1234567890'), 0, 4);
        $vade->dogrulama_kodu = $kod;
        $vade->save();
        $tel = DB::table('users')->where('id', $senet->user_id)->value('cep_telefon');
        $this->sms->gonder((int) $senet->salon_id, $tel, $senet->id.' nolu senedinizin '.date('d.m.Y', strtotime((string) $vade->vade_tarih)).' tarihli vadesinin ödemesi için doğrulama kodunuz : '.$kod);
    }
}
