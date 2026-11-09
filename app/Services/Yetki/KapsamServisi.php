<?php

namespace App\Services\Yetki;

use Illuminate\Support\Facades\DB;

/**
 * Tenant (isletme/sube) kapsam servisi.
 *
 * Paylasilan DB'de bir isletme yetkilisinin SADECE kendi bagli oldugu
 * salonlarin kaydina erismesini saglar. Eski sistemde bu kontrol zayifti
 * (cogu uc sadece query'de salon_id filtresi kullaniyordu); burada acik
 * bir "kullanici bu salona bagli mi?" denetimi yapariz.
 *
 * Baglanti kaynaklari:
 *   - salon_personelleri.yetkili_id = kullanici, YALNIZ aktif=1 satir (pasif personel kapsam disi)
 *   - model_has_roles.model_id = kullanici (rol atamasi olan salonlar). Bu salonda personel satiri
 *     olup hepsi pasifse rol satiri kapsam VERMEZ (pasife alinan personelin rol satiri kalabiliyor);
 *     hesap sahibi (rol 1) ve personel satiri hic olmayan yetkili (coklu sube yoneticisi) korunur.
 *   - Hesap sahibi iki kaynaktan taninir: model_has_roles.role_id=1 ya da salon_personelleri.role_id=1. Hesap
 *     sahibinin personel satiri pasif olsa da salon kapsamda kalir (sahip kendi isletmesinden dusurulemez).
 */
class KapsamServisi
{
    private const ROL_HESAP_SAHIBI = 1;

    /** @return int[] Kullanicinin erisebildigi salon_id listesi */
    public function kullaniciSalonlari($yetkiliId): array
    {
        if (! $yetkiliId) {
            return [];
        }

        $personel = DB::table('salon_personelleri')->where('yetkili_id', $yetkiliId)->get(['salon_id', 'aktif', 'role_id']);
        $roller = DB::table('model_has_roles')->where('model_id', $yetkiliId)->get(['salon_id', 'role_id']);

        return self::salonlariBirlestir(
            $personel->map(fn ($p) => ['salon_id' => (int) $p->salon_id, 'aktif' => (int) $p->aktif === 1, 'role_id' => (int) $p->role_id])->all(),
            $roller->map(fn ($r) => ['salon_id' => (int) $r->salon_id, 'role_id' => (int) $r->role_id])->all(),
        );
    }

    /**
     * Saf kural (test edilebilir): aktif personel salonlari + hesap sahibi personel satirlari (pasif olsa da) + rol
     * salonlari; tamamen pasif personel salonundaki rol satiri yalniz hesap sahibiyse sayilir.
     *
     * @param  array<int, array{salon_id:int, aktif:bool, role_id?:int}>  $personel
     * @param  array<int, array{salon_id:int, role_id:int}>  $roller
     * @return int[]
     */
    public static function salonlariBirlestir(array $personel, array $roller): array
    {
        $aktif = [];
        $pasif = [];
        foreach ($personel as $p) {
            $p['aktif'] || ($p['role_id'] ?? null) === self::ROL_HESAP_SAHIBI
                ? $aktif[$p['salon_id']] = true
                : $pasif[$p['salon_id']] = true;
        }
        $salonlar = array_keys($aktif);
        foreach ($roller as $r) {
            $tamamenPasif = isset($pasif[$r['salon_id']]) && ! isset($aktif[$r['salon_id']]);
            if (! $tamamenPasif || $r['role_id'] === self::ROL_HESAP_SAHIBI) {
                $salonlar[] = $r['salon_id'];
            }
        }

        return array_values(array_unique(array_map('intval', $salonlar)));
    }

    /**
     * Personel kaydi olan ama hicbir salona erisemeyen (tum personel satirlari pasif) hesap. Guard bu hesabin
     * token'ini her istekte reddeder: pasife alma bildirimi (kuyruk) islenmeden de oturum duser.
     */
    public function tamamenPasifMi($yetkiliId): bool
    {
        return $yetkiliId
            && DB::table('salon_personelleri')->where('yetkili_id', $yetkiliId)->exists()
            && $this->kullaniciSalonlari($yetkiliId) === [];
    }

    public function salonaBagliMi($yetkiliId, $salonId): bool
    {
        if (! $yetkiliId || ! $salonId) {
            return false;
        }

        return in_array((int) $salonId, $this->kullaniciSalonlari($yetkiliId), true);
    }

    /** @return int[] Kullanicinin bu salondaki rolleri (model_has_roles; 1=Hesap Sahibi … 5=Personel) */
    public function salonRolleri($yetkiliId, $salonId): array
    {
        if (! $yetkiliId || ! $salonId) {
            return [];
        }

        return DB::table('model_has_roles')
            ->where('model_id', $yetkiliId)
            ->where('salon_id', $salonId)
            ->pluck('role_id')
            ->map(fn ($r) => (int) $r)
            ->all();
    }
}
