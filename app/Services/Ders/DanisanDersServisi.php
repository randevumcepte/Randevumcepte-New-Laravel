<?php

namespace App\Services\Ders;

use App\Domain\Ders\KatilimciDurumu;
use App\Exceptions\GecersizIslemException;
use App\Models\DersOturumu;
use App\Services\Studyo\StudyoPolitikaServisi;
use Illuminate\Support\Facades\DB;

/**
 * Danisanin dersleri: online rezervasyon listesi/islemi, kendi derslerim, katilim bildirimi.
 * Musteri uygulamasi (kimlik token'dan) ve panel musteri detayi (salon kapsamli) ORTAK.
 */
class DanisanDersServisi
{
    private const REZERVASYON_GUN = 30;

    /** Gecmiste kac gunluk ders gosterilir (katildim/katilmadim bildirimi icin). */
    private const GECMIS_GUN = 3;

    public function __construct(
        private DersKatilimServisi $katilim,
        private StudyoPolitikaServisi $politika,
    ) {}

    /** Online rezervasyona acik, baslamamis dersler (modul/stüdyo/online kapaliysa bos). */
    public function uygunDersler(int $salonId): array
    {
        if (! $this->politika->getir($salonId)->onlineRezervasyon()) {
            return [];
        }
        $oturumlar = DersOturumu::yayinda()->where('salon_id', $salonId)
            ->whereBetween('tarih', [date('Y-m-d'), date('Y-m-d', strtotime('+'.self::REZERVASYON_GUN.' day'))])
            ->with('personel:id,personel_adi')->withCount('kontenjandakiler as doluluk')
            ->orderBy('tarih')->orderBy('saat')->get();

        return $oturumlar->reject(fn (DersOturumu $o) => $o->basladiMi())->values()->map(fn (DersOturumu $o) => [
            'id' => (int) $o->id, 'ders_tipi' => $o->ad(), 'hizmet_id' => $o->hizmet_id, 'tarih' => $o->tarih,
            'saat' => substr((string) $o->saat, 0, 5), 'saat_bitis' => substr((string) $o->saat_bitis, 0, 5),
            'egitmen' => $o->personel?->personel_adi ?? '', 'kapasite' => (int) $o->kapasite,
            'doluluk' => (int) $o->doluluk, 'bos' => max(0, (int) $o->kapasite - (int) $o->doluluk), 'gecmis' => false,
        ])->all();
    }

    /** Paket hakki ZORUNLU degil (eski kararla ayni): kontenjan varsa herkes rezervasyon yapar. */
    public function rezervasyon(int $salonId, int $oturumId, int $userId, bool $mobilMetni = false): array
    {
        if (! $this->politika->getir($salonId)->onlineRezervasyon()) {
            throw new GecersizIslemException('Online rezervasyon bu işletmede kapalı.');
        }
        $sonuc = $this->katilim->rezerveEt($oturumId, $salonId, $userId, $mobilMetni);

        return $sonuc + ['mesaj' => $sonuc['katilimci_durum'] === KatilimciDurumu::BEKLEME->value
            ? 'Kapasite dolu olduğu için BEKLEME listesine eklendiniz. Yer açılırsa bilgilendirileceksiniz.'
            : 'Rezervasyonunuz alındı. Görüşmek üzere ✨'];
    }

    /** Son 3 gun + ileri tarihli dersler (iptal edilmemis). */
    public function dersler(int $userId, ?int $salonId): array
    {
        $bugun = date('Y-m-d');

        return DB::table('ders_katilimcilar as k')->join('ders_oturumlari as o', 'o.id', '=', 'k.oturum_id')
            ->leftJoin('salon_personelleri as p', 'p.id', '=', 'o.personel_id')
            ->leftJoin('salonlar as s', 's.id', '=', 'o.salon_id')
            ->where('k.user_id', $userId)->where('k.durum', '!=', KatilimciDurumu::IPTAL->value)
            ->where('o.aktif', 1)->where(fn ($w) => $w->whereNull('o.iptal')->orWhere('o.iptal', 0))
            ->where('o.tarih', '>=', date('Y-m-d', strtotime('-'.self::GECMIS_GUN.' day')))
            ->when($salonId, fn ($q) => $q->where('o.salon_id', $salonId))
            ->orderBy('o.tarih')->orderBy('o.saat')
            ->get(['k.id as katilimci_id', 'k.durum', 'o.id as oturum_id', 'o.ders_tipi', 'o.tarih', 'o.saat', 'o.saat_bitis', 'o.salon_id', 's.salon_adi', 'p.personel_adi as personel'])
            ->map(fn ($r) => [
                'katilimci_id' => (int) $r->katilimci_id, 'durum' => $r->durum, 'oturum_id' => (int) $r->oturum_id,
                'ders_tipi' => $r->ders_tipi ?: DersOturumu::VARSAYILAN_AD, 'tarih' => $r->tarih,
                'saat' => substr((string) $r->saat, 0, 5), 'saat_bitis' => substr((string) $r->saat_bitis, 0, 5),
                'salon_id' => (int) $r->salon_id, 'salon_adi' => $r->salon_adi, 'personel' => $r->personel ?? '',
                'gecmis' => strtotime($r->tarih.' '.$r->saat_bitis) < time(),
                'geldim_isaretleyebilir' => $r->durum === KatilimciDurumu::REZERVE->value && $r->tarih <= $bugun,
            ])->all();
    }

    public function katilimBildir(int $katilimciId, int $userId, KatilimciDurumu $durum): array
    {
        $sonuc = $this->katilim->danisanIsaretle($katilimciId, $userId, $durum);

        return $sonuc + ['mesaj' => $durum === KatilimciDurumu::GELDI ? 'Katılımınız kaydedildi ✨' : 'Teşekkürler, katılmadınız olarak işaretlendi.'];
    }
}
