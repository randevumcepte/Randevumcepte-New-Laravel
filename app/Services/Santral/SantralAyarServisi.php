<?php

namespace App\Services\Santral;

use App\Domain\Santral\OzelKarsilamaMenusu;
use App\Exceptions\GecersizIslemException;
use App\Support\AktiviteLog;
use Illuminate\Support\Facades\DB;

/**
 * Santral ayarlari — eski StoreAdminController@santral_ayar_kaydet /
 * santral_ozel_menu_kaydet ile ayni tablolar ve ayni aktivite log eylemleri.
 *
 *   Hatirlatma aramasi : salon_santral_ayarlari (ayar_id=1).musteri + salonlar.randevu_cagri_hatirlatma (saat)
 *   Ozel karsilama     : salonlar.santral_ozel_menu_aktif + salonlar.santral_ozel_menu (JSON)
 *
 * Form secenekleri (saatler, tuslar, tipler, hedef onerileri) de buradan verilir;
 * web ve Flutter sabit liste tutmaz.
 */
class SantralAyarServisi
{
    private const HATIRLATMA_AYAR_ID = 1;
    private const SAAT_MIN = 1;
    private const SAAT_MAKS = 23;
    private const SAAT_VARSAYILAN = 2;

    public function getir(int $salonId): array
    {
        $salon = DB::table('salonlar')->where('id', $salonId)
            ->first(['randevu_cagri_hatirlatma', 'santral_ozel_menu_aktif', 'santral_ozel_menu', 'operator_kanali']);

        return [
            'hatirlatma' => $this->hatirlatmaBlogu($salonId, $salon),
            'ozel_menu'  => $this->ozelMenuBlogu($salonId, $salon),
        ];
    }

    public function hatirlatmaKaydet(int $salonId, int $yapanId, bool $aktif, int $saatOnce): array
    {
        if ($saatOnce < self::SAAT_MIN || $saatOnce > self::SAAT_MAKS) {
            throw new GecersizIslemException('Hatırlatma saati '.self::SAAT_MIN.'-'.self::SAAT_MAKS.' arasında olmalı.');
        }

        DB::transaction(function () use ($salonId, $aktif, $saatOnce) {
            $kosul = ['salon_id' => $salonId, 'ayar_id' => self::HATIRLATMA_AYAR_ID];
            $simdi = now();
            if (DB::table('salon_santral_ayarlari')->where($kosul)->exists()) {
                DB::table('salon_santral_ayarlari')->where($kosul)->update(['musteri' => $aktif, 'updated_at' => $simdi]);
            } else {
                DB::table('salon_santral_ayarlari')->insert($kosul + ['musteri' => $aktif, 'personel' => false, 'created_at' => $simdi, 'updated_at' => $simdi]);
            }
            DB::table('salonlar')->where('id', $salonId)->update(['randevu_cagri_hatirlatma' => $saatOnce]);
        });

        AktiviteLog::yaz($salonId, $yapanId, 'santral_ayar_kaydet', 'santral_ayar', null,
            'Santral ayarları', 'Sesli arama / santral ayarları güncellendi',
            ['hatirlatma_saat' => $saatOnce, 'musteri' => $aktif ? 1 : 0]);

        return $this->getir($salonId)['hatirlatma'];
    }

    public function ozelMenuKaydet(int $salonId, int $yapanId, bool $aktif, array $hamSecenekler): array
    {
        $secenekler = OzelKarsilamaMenusu::normalize($hamSecenekler);
        if ($aktif && empty($secenekler)) {
            throw new GecersizIslemException('Özel menüyü açmak için en az bir geçerli seçenek (tuş, işlem adı, kuyruk veya dahili no) girin.');
        }

        DB::table('salonlar')->where('id', $salonId)->update([
            'santral_ozel_menu_aktif' => $aktif,
            'santral_ozel_menu'       => json_encode($secenekler, JSON_UNESCAPED_UNICODE),
        ]);

        AktiviteLog::yaz($salonId, $yapanId, 'santral_ozel_menu_kaydet', 'santral_ayar', null,
            'Santral özel karşılama menüsü',
            'Santral özel karşılama menüsü '.($aktif ? 'açıldı / güncellendi' : 'kapatıldı'),
            ['aktif' => $aktif ? 1 : 0, 'secenekler' => $secenekler]);

        return $this->getir($salonId)['ozel_menu'];
    }

    private function hatirlatmaBlogu(int $salonId, ?object $salon): array
    {
        $saat = (int) ($salon->randevu_cagri_hatirlatma ?? 0);

        return [
            'aktif'            => (bool) DB::table('salon_santral_ayarlari')
                ->where('salon_id', $salonId)->where('ayar_id', self::HATIRLATMA_AYAR_ID)->value('musteri'),
            'saat_once'        => $saat >= self::SAAT_MIN && $saat <= self::SAAT_MAKS ? $saat : self::SAAT_VARSAYILAN,
            'saat_secenekleri' => range(self::SAAT_MIN, self::SAAT_MAKS),
        ];
    }

    private function ozelMenuBlogu(int $salonId, ?object $salon): array
    {
        $secenekler = OzelKarsilamaMenusu::normalize(json_decode((string) ($salon->santral_ozel_menu ?? ''), true));

        return [
            'aktif'           => (bool) ($salon->santral_ozel_menu_aktif ?? false),
            'secenekler'      => $secenekler,
            'metin'           => OzelKarsilamaMenusu::metin($secenekler),
            'tuslar'          => array_map('strval', array_keys(OzelKarsilamaMenusu::TUS_OKUNUS)),
            'tipler'          => OzelKarsilamaMenusu::TIPLER,
            'etiket_maks'     => OzelKarsilamaMenusu::ETIKET_MAKS,
            'hedef_onerileri' => $this->hedefOnerileri($salonId, $salon),
        ];
    }

    /** Elle de girilebilir oneriler: kuyruk = operator kuyrugu, dahili = personel dahilileri. */
    private function hedefOnerileri(int $salonId, ?object $salon): array
    {
        $kuyruk = [];
        if (! empty($salon->operator_kanali)) {
            $kuyruk[] = ['no' => (string) $salon->operator_kanali, 'ad' => 'Operatör kuyruğu'];
        }
        $dahili = DB::table('salon_personelleri')->where('salon_id', $salonId)
            ->whereNotNull('dahili_no')->where('dahili_no', '!=', '')
            ->get(['personel_adi', 'dahili_no'])
            ->map(fn ($p) => ['no' => (string) $p->dahili_no, 'ad' => (string) $p->personel_adi])
            ->all();

        return ['kuyruk' => $kuyruk, 'dahili' => $dahili];
    }
}
