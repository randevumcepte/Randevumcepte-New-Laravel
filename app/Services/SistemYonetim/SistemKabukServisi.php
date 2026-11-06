<?php

namespace App\Services\SistemYonetim;

use App\Models\SistemYoneticisi;
use App\Support\ListeIstegi;
use App\Support\SistemMenu;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Kabuk verisi: menu + rozetler, bildirim feed'i, genel arama. */
class SistemKabukServisi
{
    private const ACIK_DURUMLAR = ['acik', 'islemde', 'bekliyor'];

    public function acilis(SistemYoneticisi $yonetici): array
    {
        return [
            'kullanici' => SistemOturumServisi::kullaniciOzeti($yonetici),
            'menu'      => SistemMenu::rolIcin($yonetici->rol()),
            'rozetler'  => ['bekleyen_ticket' => (int) Cache::remember('sy.kabuk.bekleyen_ticket', 15, fn () => DB::table('sistemyonetim_destek_talepleri')->whereIn('durum', self::ACIK_DURUMLAR)->count())],
        ];
    }

    /** Bana atanmis + atanmamis + acil talepler (son 14 gun) ve son 3 gunun demo kayitlari. */
    public function bildirimler(SistemYoneticisi $yonetici): array
    {
        $talepler = DB::table('sistemyonetim_destek_talepleri')
            ->whereIn('durum', self::ACIK_DURUMLAR)
            ->where(fn ($w) => $w->where('atanan_user_id', $yonetici->id)->orWhereNull('atanan_user_id')->orWhere('oncelik', 'acil'))
            ->where('created_at', '>=', now()->subDays(14)->toDateString())
            ->orderByDesc('id')->limit(15)
            ->get(['id', 'numara', 'konu', 'salon_adi', 'oncelik', 'created_at']);

        $liste = $talepler->map(fn ($t) => [
            'tip'      => 'ticket',
            'ikon'     => $t->oncelik === 'acil' ? 'mdi-alert' : 'mdi-lifebuoy',
            'renk'     => $t->oncelik === 'acil' ? 'danger' : 'info',
            'baslik'   => $t->numara.' — '.mb_substr((string) $t->konu, 0, 50),
            'aciklama' => ($t->salon_adi ?: 'Genel').' · '.$t->oncelik,
            'link'     => '/sistemyonetim/ticket/'.$t->id,
            'zaman'    => Carbon::parse($t->created_at)->locale('tr')->diffForHumans(),
        ])->all();

        $demolar = DB::table('salonlar')->where('demo_hesabi', 1)->where('created_at', '>=', now()->subDays(3))
            ->orderByDesc('id')->limit(10)->get(['id', 'salon_adi', 'yetkili_telefon', 'created_at']);
        foreach ($demolar as $d) {
            $liste[] = [
                'tip'      => 'demo',
                'ikon'     => 'mdi-store-plus',
                'renk'     => 'warning',
                'baslik'   => 'Yeni Demo: '.mb_substr((string) $d->salon_adi, 0, 40),
                'aciklama' => 'Demo hesap açıldı'.($d->yetkili_telefon ? ' · '.$d->yetkili_telefon : ''),
                'link'     => '/sistemyonetim/salon/'.$d->id,
                'zaman'    => Carbon::parse($d->created_at)->locale('tr')->diffForHumans(),
            ];
        }

        return ['sayi' => count($liste), 'liste' => $liste];
    }

    /** Destek rolunde salon sonuclari kendi salonlariyla sinirli; ekip sonuclari yalniz yonetime. */
    public function ara(string $metin, SistemYoneticisi $yonetici, SalonKapsami $kapsam): array
    {
        $like = ListeIstegi::like($metin);
        if ($like === null || mb_strlen(trim($metin)) < 2) {
            return ['salon' => [], 'ticket' => [], 'ekip' => []];
        }

        $salonlar = $kapsam->sorguyaUygula(DB::table('salonlar'), $yonetici)
            ->where(fn ($w) => $w->where('salon_adi', 'like', $like)->orWhere('telefon_1', 'like', $like)->orWhere('yetkili_telefon', 'like', $like)->orWhere('yetkili_adi', 'like', $like))
            ->limit(8)->get(['id', 'salon_adi', 'yetkili_adi', 'telefon_1', 'askiya_alindi']);

        $talepler = DB::table('sistemyonetim_destek_talepleri')
            ->where(fn ($w) => $w->where('numara', 'like', $like)->orWhere('konu', 'like', $like)->orWhere('salon_adi', 'like', $like))
            ->limit(8)->get(['id', 'numara', 'konu', 'durum', 'oncelik', 'salon_adi']);

        $ekip = DB::table('sistemyoneticileri')
            ->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('email', 'like', $like))
            ->limit(8)->get(['id', 'name', 'email', 'rol']);

        return ['salon' => $salonlar, 'ticket' => $talepler, 'ekip' => $ekip];
    }

    /** Secici kutular icin hafif salon aramasi. */
    public function salonAra(string $metin, SistemYoneticisi $yonetici, SalonKapsami $kapsam): array
    {
        $like = ListeIstegi::like($metin);
        if ($like === null || mb_strlen(trim($metin)) < 2) {
            return [];
        }

        return $kapsam->sorguyaUygula(DB::table('salonlar'), $yonetici)->where('salon_adi', 'like', $like)
            ->orderBy('salon_adi')->limit(20)->get(['id', 'salon_adi'])->all();
    }
}
