<?php

namespace App\Services\Yetki;

use App\Support\SalonIdListesi;
use Illuminate\Support\Facades\DB;

/**
 * ÇOKLU ŞUBE "hangi şubelere uygulansın?" — TEK KAYNAK (çark, puan ödülü; ileride bildirim
 * reklamı). Eski monolitte üç ayrı yerde (web yetkiliSubeIdler, mobil kardesSalonIdler,
 * partials/sube_secici) farklı kurallarla vardı.
 *
 *   secenekler() : seçicide gösterilecek şubeler (kullanıcının erişebildiği salonlar)
 *   hedefler()   : istekteki salon_ids ∩ kullanıcının o yetkiye sahip olduğu şubeler;
 *                  boşsa yalnız istekteki salon (güvenli varsayılan — eski davranış)
 *   kuponSubeleri(): bir kupon/ödül satırının geçerli olduğu şubeler — gecerli_salonlar
 *                  doluysa o grup, değilse kaydın salonunun sahip (kardeş şube) grubu
 *                  (eski CarkifelekOdulleri::gecerliSubeler ile birebir)
 */
class SubeGrubu
{
    /** Sistem/süper hesaplar şube grubu anahtarı sayılmaz (eski Personeller::SISTEM_YETKILI_IDLER). */
    private const SISTEM_YETKILI_IDLER = [3];

    public function __construct(
        private KapsamServisi $kapsam,
        private YetkiServisi $yetki,
    ) {}

    /** @return array<int, array{id:int, ad:string, secili:bool}> tek şubede [] (seçici çizilmez) */
    public function secenekler(int $yetkiliId, int $salonId): array
    {
        $idler = $this->kapsam->kullaniciSalonlari($yetkiliId);
        if (count($idler) < 2) {
            return [];
        }

        return DB::table('salonlar')->whereIn('id', $idler)->orderBy('salon_adi')->get(['id', 'salon_adi'])
            ->map(fn ($s) => ['id' => (int) $s->id, 'ad' => trim((string) $s->salon_adi), 'secili' => (int) $s->id === $salonId])
            ->all();
    }

    /**
     * @param  mixed  $secili  istekteki salon_ids (dizi | JSON | "1,2")
     * @return int[] boş olmaz; $salonId her zaman güvenli alt sınır
     */
    public function hedefler(int $yetkiliId, int $salonId, mixed $secili, string $yetkiAnahtari): array
    {
        $secili = SalonIdListesi::coz($secili);
        if (! $secili) {
            return [$salonId];
        }
        $izinli = array_filter(
            array_intersect($secili, $this->kapsam->kullaniciSalonlari($yetkiliId)),
            fn (int $id) => $id === $salonId || $this->yetki->yetkiliYetkiVar($yetkiliId, $id, $yetkiAnahtari),
        );

        return $izinli ? array_values(array_unique($izinli)) : [$salonId];
    }

    /** @return int[] */
    public function kuponSubeleri(?string $gecerliSalonlar, int $kayitSalonId): array
    {
        return SalonIdListesi::coz($gecerliSalonlar) ?: $this->kardesler($kayitSalonId);
    }

    /** Salonun gerçek sahibinin tüm şubeleri (sahip yoksa yalnız kendisi). */
    public function kardesler(int $salonId): array
    {
        $sahip = DB::table('salon_personelleri')->where('salon_id', $salonId)->whereNotNull('yetkili_id')
            ->whereNotIn('yetkili_id', self::SISTEM_YETKILI_IDLER)->value('yetkili_id');
        if (! $sahip) {
            return [$salonId];
        }
        $idler = DB::table('salon_personelleri')->where('yetkili_id', $sahip)->whereNotNull('salon_id')
            ->distinct()->pluck('salon_id')->map(fn ($v) => (int) $v)->filter()->all();

        return array_values(array_unique([...$idler, $salonId]));
    }
}
