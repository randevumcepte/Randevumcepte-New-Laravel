<?php

namespace App\Services\SistemYonetim\Salon;

use App\Domain\SistemYonetim\SistemRolu;
use App\Domain\SistemYonetim\UyelikKurallari;
use App\Models\SistemYoneticisi;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/** Salon detay ekraninin verisi. Kolonlar acik: sms/santral/firebase sirlari bu panele cikmaz. */
class SalonDetayServisi
{
    public const KOLONLAR = ['id', 'salon_adi', 'domain', 'il_id', 'ilce_id', 'salon_turu_id', 'adres', 'telefon_1', 'telefon_2', 'telefon_3',
        'yetkili_adi', 'yetkili_telefon', 'yetkili_mail', 'aciklama', 'android_uygulama', 'ios_uygulama', 'huawei_uygulama',
        'android_son_versiyon', 'ios_son_versiyon', 'huawei_son_versiyon', 'askiya_alindi', 'askiya_alma_sebebi', 'whatsapp_aktif',
        'demo_hesabi', 'uyelik_bitis_tarihi', 'created_at'];

    private const YETKILI_MODEL_TIPI = 'App\\IsletmeYetkilileri';

    private const SON_KAYIT = 20;

    public function __construct(private SalonIletisim $iletisim, private SalonSaglikServisi $saglik) {}

    public function detay(object $salon, SistemYoneticisi $yonetici): array
    {
        $id = (int) $salon->id;

        return [
            'salon'      => $salon,
            'il_adi'     => DB::table('il')->where('id', $salon->il_id)->value('il_adi'),
            'ilce_adi'   => DB::table('ilce')->where('id', $salon->ilce_id)->value('ilce_adi'),
            'iletisim'   => $this->iletisim->kisi($salon, $this->iletisim->hesapSahipleri([$id])[$id] ?? null),
            'demo'       => (int) $salon->demo_hesabi === 1,
            'uyelik'     => UyelikKurallari::ozet($salon->uyelik_bitis_tarihi, time()),
            'saglik'     => $this->saglik->salonIcin($salon),
            'istatistik' => $this->istatistik($salon),
            'yetkililer' => $this->yetkililer($id),
            'notlar'     => $this->notlar($id, $yonetici),
            'girisler'   => DB::table('sistemyonetim_impersonation_loglari')->where('salon_id', $id)->orderByDesc('id')->limit(self::SON_KAYIT)
                ->get(['id', 'user_name', 'baslangic_tarihi', 'sebep']),
            'talepler'   => DB::table('sistemyonetim_destek_talepleri')->where('salon_id', $id)->orderByDesc('id')->limit(self::SON_KAYIT)
                ->get(['id', 'numara', 'konu', 'durum', 'oncelik', 'created_at']),
        ];
    }

    private function istatistik(object $salon): array
    {
        $ayBasi = now()->startOfMonth()->toDateTimeString();
        $randevu = DB::table('randevular')->where('salon_id', $salon->id)
            ->selectRaw('COUNT(*) as toplam, SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) as bu_ay', [$ayBasi])->first();

        return [
            'toplam_randevu'     => (int) ($randevu->toplam ?? 0),
            'bu_ay_randevu'      => (int) ($randevu->bu_ay ?? 0),
            'bu_ay_yeni_musteri' => DB::table('musteri_portfoy')->where('salon_id', $salon->id)->where('created_at', '>=', $ayBasi)->count(),
            'whatsapp_aktif'     => (bool) $salon->whatsapp_aktif,
        ];
    }

    /** Personel kaydiyla bagli hesaplar + eski salon_id bagli hesaplar; rol adi salona ozel. */
    private function yetkililer(int $salonId): array
    {
        $personeller = DB::table('salon_personelleri')->where('salon_id', $salonId)->whereNotNull('yetkili_id')->get(['yetkili_id', 'aktif']);
        $aktifler = [];
        foreach ($personeller as $p) {
            $aktifler[$p->yetkili_id] = max($aktifler[$p->yetkili_id] ?? 0, (int) $p->aktif);
        }

        $idler = collect(array_keys($aktifler))->merge(DB::table('isletmeyetkilileri')->where('salon_id', $salonId)->pluck('id'))->unique()->values()->all();
        if (! $idler) {
            return [];
        }
        $roller = DB::table('model_has_roles')->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->whereIn('model_has_roles.model_id', $idler)->where('model_has_roles.model_type', self::YETKILI_MODEL_TIPI)->where('model_has_roles.salon_id', $salonId)
            ->pluck('roles.name', 'model_has_roles.model_id');

        return DB::table('isletmeyetkilileri')->whereIn('id', $idler)->get(['id', 'name', 'email', 'gsm1', 'gsm2', 'telefon', 'is_admin'])
            ->map(fn ($y) => [
                'id'      => (int) $y->id,
                'ad'      => $y->name,
                'email'   => $y->email,
                'telefon' => $y->gsm1 ?: ($y->gsm2 ?: $y->telefon),
                'sahip'   => (bool) $y->is_admin,
                'rol_adi' => $roller[$y->id] ?? ($y->is_admin ? 'Hesap Sahibi' : 'Personel'),
                'aktif'   => ($aktifler[$y->id] ?? 1) !== 0,
            ])->all();
    }

    private function notlar(int $salonId, SistemYoneticisi $yonetici): array
    {
        $superAdmin = $yonetici->rol() === SistemRolu::SuperAdmin;

        return DB::table('sistemyonetim_salon_notlari')->where('salon_id', $salonId)->orderByDesc('pinned')->orderByDesc('id')
            ->get(['id', 'user_id', 'user_name', 'baslik', 'icerik', 'tip', 'pinned', 'created_at'])
            ->map(fn ($n) => [
                'id'         => (int) $n->id,
                'yazan'      => $n->user_name,
                'baslik'     => $n->baslik,
                'icerik'     => $n->icerik,
                'tip'        => $n->tip,
                'sabit'      => (bool) $n->pinned,
                'ne_zaman'   => $n->created_at ? Carbon::parse($n->created_at)->locale('tr')->diffForHumans() : '',
                'silinebilir' => $superAdmin || (int) $n->user_id === (int) $yonetici->id,
            ])->all();
    }
}
