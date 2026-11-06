<?php

namespace App\Services\SistemYonetim\Izleme;

use App\Support\SorguSayfasi;
use App\Services\SistemYonetim\Denetim;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Aktivite logu: tum ekibin denetim kayitlari, filtreleri ve CSV aktarimi. */
class AktiviteServisi
{
    public const SIRALAMA = ['zaman' => 'id', 'kullanici' => 'user_name', 'islem' => 'action', 'hedef' => 'target_label', 'ip' => 'ip'];

    public const CSV_BASLIKLARI = ['Zaman', 'Kullanıcı', 'Rol', 'İşlem', 'Hedef Tipi', 'Hedef', 'Açıklama', 'IP'];

    private const CSV_SINIRI = 5000;

    private const SECENEK_SN = 300;

    /** @param array{action?:?string,user_id?:?int,tarih?:?string} $filtre */
    public function listele(array $liste, array $filtre): array
    {
        $sorgu = DB::table('sistemyonetim_audit_log')->select(IzlemeSunumu::AKTIVITE_KOLONLARI)
            ->when($filtre['action'] ?? null, fn ($q, $v) => $q->where('action', $v))
            ->when($filtre['user_id'] ?? null, fn ($q, $v) => $q->where('user_id', $v))
            ->when($filtre['tarih'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', $v.' 00:00:00')
                ->where('created_at', '<', Carbon::parse($v)->addDay()->toDateString().' 00:00:00'));

        return SorguSayfasi::uret($sorgu, $liste, ['target_label', 'aciklama', 'user_name'], self::SIRALAMA, fn ($k) => IzlemeSunumu::aktivite($k));
    }

    public function secenekler(): array
    {
        return [
            'aksiyonlar'   => Cache::remember('sy.aktivite.actions', self::SECENEK_SN, fn () => DB::table('sistemyonetim_audit_log')
                ->whereNotNull('action')->where('action', '<>', '')->distinct()->orderBy('action')->pluck('action')->all()),
            'kullanicilar' => DB::table('sistemyoneticileri')->orderBy('name')->get(['id', 'name'])
                ->map(fn ($k) => ['id' => (int) $k->id, 'ad' => $k->name])->all(),
        ];
    }

    /** @return list<list<?string>> */
    public function csvSatirlari(): array
    {
        $satirlar = DB::table('sistemyonetim_audit_log')->orderByDesc('id')->limit(self::CSV_SINIRI)
            ->get(['created_at', 'user_name', 'user_rol', 'action', 'target_type', 'target_label', 'aciklama', 'ip'])
            ->map(fn ($l) => [IzlemeZamani::bicim($l->created_at), $l->user_name, $l->user_rol, $l->action, $l->target_type, $l->target_label, $l->aciklama, $l->ip])
            ->all();

        Denetim::yaz('csv_export', 'aktivite', null, 'Aktivite log ('.count($satirlar).')');

        return $satirlar;
    }
}
