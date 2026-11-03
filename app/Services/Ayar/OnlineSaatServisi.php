<?php

namespace App\Services\Ayar;

use App\Domain\Ayar\AyarSozluk;
use App\Domain\Ayar\HaftalikSaat;
use App\Exceptions\BulunamadiException;
use App\Support\AktiviteLog;
use App\Support\HaftalikSaatDeposu;
use Illuminate\Support\Facades\DB;

/**
 * Online randevu AÇIK saatleri — eski StoreAdminController@onlineRandevuSaatGuncelle ve
 * v1 onlineRandevuSaatConfig / onlineRandevuSaatKaydet'in TEK karsiligi. Cevap bicimi v1 ile
 * ayni (Flutter online_randevu_saatleri.dart anahtarlari): kisitlama_aktif, gunluk_limit,
 * aralik_dk, gunler[{gun, gun_adi, calisiyor, slots[], yapilandirildi, acik[]}], istisnalar[].
 *
 * Kayit girdisi slot tabanli (web izgarasi ve Flutter ayni): gunler = {"1": ["10:00", ...]};
 * sunucu bitisik slotlari pencerelere birlestirir. Pencere/istisna tablolari sil-ve-yaz.
 * Mini sitenin kanonik okuyucusu (SalonOnlineRandevuSaatleri) eski monolitte; tablo semasi ayni.
 */
class OnlineSaatServisi
{
    private const PENCERE_TABLO = 'salon_online_randevu_saatleri';
    private const ISTISNA_TABLO = 'salon_online_randevu_istisnalari';

    public function getir(int $salonId): array
    {
        $salon = $this->salon($salonId);
        $aralik = HaftalikSaat::aralik($salon->randevu_saat_araligi);
        $calisma = collect(HaftalikSaatDeposu::oku('salon', $salonId, HaftalikSaatDeposu::CALISMA))->keyBy('haftanin_gunu');
        $pencereler = DB::table(self::PENCERE_TABLO)->where('salon_id', $salonId)->get()->groupBy('haftanin_gunu');

        $gunler = [];
        foreach (HaftalikSaat::GUNLER as $gun => $ad) {
            $c = $calisma->get($gun);
            $bas = HaftalikSaat::saat($c['baslangic_saati'] ?? null);
            $bit = HaftalikSaat::saat($c['bitis_saati'] ?? null);
            $calisiyor = (int) ($c['calisiyor'] ?? 0) === 1 && HaftalikSaat::sirali($bas, $bit);

            $acik = [];
            foreach ($pencereler->get($gun, collect()) as $p) {
                $pb = HaftalikSaat::saat($p->baslangic_saati);
                $pe = HaftalikSaat::saat($p->bitis_saati);
                if (HaftalikSaat::sirali($pb, $pe)) {
                    array_push($acik, ...HaftalikSaat::slotlar($pb, $pe, $aralik));
                }
            }

            $gunler[] = [
                'gun'            => $gun,
                'gun_adi'        => $ad,
                'calisiyor'      => $calisiyor,
                'slots'          => $calisiyor ? HaftalikSaat::slotlar($bas, $bit, $aralik) : [],
                'yapilandirildi' => $pencereler->has($gun), // false → bu gun hic kaydedilmemis, varsayilan hepsi acik
                'acik'           => array_values(array_unique($acik)),
            ];
        }

        return [
            'kisitlama_aktif' => (int) ($salon->online_saat_kisitlama_aktif ?? 0) === 1,
            'gunluk_limit'    => $salon->online_gunluk_slot_limiti !== null ? (int) $salon->online_gunluk_slot_limiti : null,
            'aralik_dk'       => $aralik,
            'gunler'          => $gunler,
            'istisnalar'      => $this->istisnalar($salonId),
            'secenekler'      => ['istisna_tipleri' => AyarSozluk::ISTISNA_TIPLERI],
        ];
    }

    /**
     * @param  array{kisitlama_aktif:bool, gunluk_limit:?int, gunler:array<int|string,list<string>>, istisnalar:list<array>}  $g
     */
    public function kaydet(int $salonId, int $yapanId, array $g): array
    {
        $salon = $this->salon($salonId);
        $aralik = HaftalikSaat::aralik($salon->randevu_saat_araligi);
        $limit = (int) ($g['gunluk_limit'] ?? 0);
        $simdi = now();

        $pencereler = [];
        foreach ((array) ($g['gunler'] ?? []) as $gun => $slotlar) {
            if (! HaftalikSaat::gecerliGun($gun)) {
                continue;
            }
            foreach (HaftalikSaat::pencereler((array) $slotlar, $aralik) as $p) {
                $pencereler[] = ['salon_id' => $salonId, 'haftanin_gunu' => (int) $gun, 'baslangic_saati' => $p['bas'], 'bitis_saati' => $p['bit'], 'created_at' => $simdi, 'updated_at' => $simdi];
            }
        }

        $istisnalar = [];
        foreach ((array) ($g['istisnalar'] ?? []) as $i) {
            $ozel = ($i['tip'] ?? 'kapali') === 'ozel';
            $bas = $ozel ? HaftalikSaat::saat($i['bas'] ?? null) : null;
            $bit = $ozel ? HaftalikSaat::saat($i['bit'] ?? null) : null;
            if ($ozel && ! HaftalikSaat::sirali($bas, $bit)) {
                continue; // gecersiz ozel aralik atlanir (eskiyle ayni)
            }
            $istisnalar[] = ['salon_id' => $salonId, 'tarih' => $i['tarih'], 'tip' => $ozel ? 'ozel' : 'kapali', 'baslangic_saati' => $bas, 'bitis_saati' => $bit, 'created_at' => $simdi, 'updated_at' => $simdi];
        }

        DB::transaction(function () use ($salonId, $g, $limit, $pencereler, $istisnalar) {
            DB::table('salonlar')->where('id', $salonId)->update([
                'online_saat_kisitlama_aktif' => ! empty($g['kisitlama_aktif']) ? 1 : 0,
                'online_gunluk_slot_limiti'   => $limit > 0 ? $limit : null,
            ]);
            DB::table(self::PENCERE_TABLO)->where('salon_id', $salonId)->delete();
            DB::table(self::ISTISNA_TABLO)->where('salon_id', $salonId)->delete();
            if ($pencereler) {
                DB::table(self::PENCERE_TABLO)->insert($pencereler);
            }
            if ($istisnalar) {
                DB::table(self::ISTISNA_TABLO)->insert($istisnalar);
            }
        });

        AktiviteLog::yaz($salonId, $yapanId, 'online_saat_guncelle', 'salon', $salonId,
            'Online randevu saatleri güncellendi', ! empty($g['kisitlama_aktif']) ? 'Kısıtlama aktif' : 'Kısıtlama kapalı',
            ['limit' => $limit > 0 ? $limit : null]);

        return $this->getir($salonId);
    }

    /** Bugun ve sonrasi istisnalar (gecmis gosterilmez — eskiyle ayni). */
    private function istisnalar(int $salonId): array
    {
        return DB::table(self::ISTISNA_TABLO)->where('salon_id', $salonId)
            ->where('tarih', '>=', date('Y-m-d'))->orderBy('tarih')->get()
            ->map(fn ($i) => [
                'tarih' => substr((string) $i->tarih, 0, 10),
                'tip'   => (string) $i->tip,
                'bas'   => HaftalikSaat::saat($i->baslangic_saati),
                'bit'   => HaftalikSaat::saat($i->bitis_saati),
            ])->all();
    }

    private function salon(int $salonId): object
    {
        $s = DB::table('salonlar')->where('id', $salonId)
            ->first(['randevu_saat_araligi', 'online_saat_kisitlama_aktif', 'online_gunluk_slot_limiti']);
        if (! $s) {
            throw new BulunamadiException('İşletme bulunamadı');
        }

        return $s;
    }
}
