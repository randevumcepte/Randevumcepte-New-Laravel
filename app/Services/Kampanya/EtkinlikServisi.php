<?php

namespace App\Services\Kampanya;

use App\Exceptions\BulunamadiException;
use App\Support\AktiviteLog;
use App\Support\KvkkMaske;
use App\Support\ListeIstegi;
use Illuminate\Support\Facades\DB;

/**
 * Etkinlik: liste (son 30 günde açılan), kayıt, pasif yapma, bekleyenlere SMS. Eski etkinlikyukle,
 * etkinlikekleduzenle, etkinlikpasifet ve etkinliktekrarsmsgonder karşılığı. Eskiden farklı olarak
 * kayıt salona bağlıdır, katılımcı salonun portföyünden seçilir, müşteri satırı beyaz listeyle döner.
 */
class EtkinlikServisi
{
    private const LISTE_GUN = 30;

    public function __construct(private KatilimSmsServisi $sms) {}

    /** @param array{page:int, per_page:int, ara:?string} $liste */
    public function listele(int $salonId, array $liste, bool $telefonGor): array
    {
        $q = DB::table('etkinlikler')->where('salon_id', $salonId)->where('aktifmi', 1)
            ->whereDate('created_at', '>=', now()->subDays(self::LISTE_GUN)->toDateString());
        if ($like = ListeIstegi::like($liste['ara'] ?? null)) {
            $q->where('etkinlik_adi', 'like', $like);
        }
        $toplam = (clone $q)->count();
        $satirlar = $q->orderBy('id')->forPage($liste['page'], $liste['per_page'])
            ->get(['id', 'etkinlik_adi', 'tarih_saat', 'fiyat', 'aktifmi', 'mesaj']);

        $katilimcilar = DB::table('etkinlik_katilimcilari as ek')->leftJoin('users as u', 'u.id', '=', 'ek.user_id')
            ->whereIn('ek.etkinlik_id', $satirlar->pluck('id'))
            ->get(['ek.id', 'ek.etkinlik_id', 'ek.user_id', 'ek.durum', 'u.name', 'u.cep_telefon'])
            ->groupBy('etkinlik_id');

        return ['data' => $satirlar->map(fn ($e) => [
            'id'           => (int) $e->id,
            'etkinlik_adi' => $e->etkinlik_adi,
            'tarih_saat'   => $e->tarih_saat,
            'fiyat'        => $e->fiyat,
            'aktifmi'      => (int) $e->aktifmi,
            'mesaj'        => $e->mesaj,
            'katilimcilar' => ($katilimcilar[$e->id] ?? collect())->map(fn ($k) => [
                'id'      => (int) $k->id,
                'user_id' => (int) $k->user_id,
                'durum'   => $k->durum,
                'musteri' => ['name' => $k->name, 'cep_telefon' => $telefonGor ? $k->cep_telefon : KvkkMaske::telefon($k->cep_telefon)],
            ])->values()->all(),
        ])->all()] + ListeIstegi::meta($liste['page'], $liste['per_page'], $toplam);
    }

    /**
     * @param  array{etkinlik_adi:?string, etkinlik_tarih:?string, etkinlik_saat:?string, etkinlik_mesaj:?string, etkinlik_fiyat:mixed, user_idler?:int[]}  $v
     * @return int etkinlik id
     */
    public function kaydet(int $salonId, ?int $id, array $v, int $yetkiliId): int
    {
        $metin = (string) ($v['etkinlik_mesaj'] ?? '');
        $kolonlar = [
            'etkinlik_adi' => $v['etkinlik_adi'] ?? null,
            'tarih_saat'   => trim(($v['etkinlik_tarih'] ?? '').' '.($v['etkinlik_saat'] ?? '')),
            'fiyat'        => $v['etkinlik_fiyat'] ?? null,
            'salon_id'     => $salonId,
            'aktifmi'      => 1,
            'mesaj'        => $metin,
            'updated_at'   => now(),
        ];

        $sonuc = DB::transaction(function () use ($salonId, $id, $v, $kolonlar, $metin, $yetkiliId) {
            if ($id) {
                $this->kayit($id, $salonId);
                DB::table('etkinlikler')->where('id', $id)->update($kolonlar);
            } else {
                $id = (int) DB::table('etkinlikler')->insertGetId($kolonlar + ['created_at' => now()]);
            }
            DB::table('etkinlik_katilimcilari')->where('etkinlik_id', $id)->delete();

            // Başka salonun müşterisi eklenemez.
            $idler = DB::table('musteri_portfoy')->where('salon_id', $salonId)->whereIn('user_id', $v['user_idler'] ?? [])
                ->distinct()->pluck('user_id')->all();
            $simdi = now();
            DB::table('etkinlik_katilimcilari')->insert(array_map(fn ($u) => [
                'etkinlik_id' => $id, 'user_id' => $u, 'created_at' => $simdi, 'updated_at' => $simdi,
            ], $idler));

            $this->sms->gonder($salonId, $yetkiliId, $this->telefonlar($idler), $metin, 'etkinlikkatilim/'.$id);

            return $id;
        });
        AktiviteLog::yaz($salonId, $yetkiliId, $id ? 'etkinlik_guncelle' : 'etkinlik_ekle', 'etkinlik', $sonuc, (string) ($v['etkinlik_adi'] ?? ''), 'Etkinlik kaydedildi.');

        return $sonuc;
    }

    public function pasifYap(int $salonId, int $id, int $yetkiliId): void
    {
        $this->kayit($id, $salonId);
        DB::table('etkinlikler')->where('id', $id)->update(['aktifmi' => 0, 'updated_at' => now()]);
        AktiviteLog::yaz($salonId, $yetkiliId, 'etkinlik_pasif', 'etkinlik', $id, null, 'Etkinlik pasife alindi');
    }

    /** Henüz cevap vermeyenlere (durum boş) aynı metni yeniden gönderir. */
    public function tekrarSms(int $salonId, int $id, int $yetkiliId): int
    {
        $e = $this->kayit($id, $salonId);
        $bekleyenler = DB::table('etkinlik_katilimcilari')->where('etkinlik_id', $id)->whereNull('durum')->pluck('user_id')->all();

        return $this->sms->gonder($salonId, $yetkiliId, $this->telefonlar($bekleyenler), (string) $e->mesaj, 'etkinlikkatilim/'.$id);
    }

    /** Kayıt + salon kapsamı; başka salonun etkinliği 404. */
    public function salonu(int $id): ?int
    {
        $s = DB::table('etkinlikler')->where('id', $id)->value('salon_id');

        return $s ? (int) $s : null;
    }

    private function kayit(int $id, int $salonId): object
    {
        return DB::table('etkinlikler')->where('id', $id)->where('salon_id', $salonId)->first(['id', 'mesaj'])
            ?? throw new BulunamadiException('Etkinlik bulunamadı');
    }

    /** @param int[] $idler */
    private function telefonlar(array $idler): \Illuminate\Support\Collection
    {
        return DB::table('users')->whereIn('id', $idler)->get(['id as user_id', 'cep_telefon']);
    }
}
