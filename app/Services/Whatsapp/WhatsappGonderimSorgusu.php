<?php

namespace App\Services\Whatsapp;

use App\Domain\Whatsapp\GonderimDurumu;
use App\Domain\Whatsapp\GonderimOzeti;
use App\Exceptions\BulunamadiException;
use App\Support\KvkkMaske;
use App\Support\ListeIstegi;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * whatsapp_gonderim_loglari okuma — istatistik, mesaj listesi, alıcı listesi, alıcı geçmişi.
 * Eski web + mobil uçlarının tek karşılığı. Telefon SUNUCUDA maskelenir ve musteri.telefon_gor
 * yoksa telefonda aranmaz (rakam rakam deneyip numarayı bulma kapalı). Bu yüzden alıcı geçmişi
 * telefonla değil, alıcının son log id'siyle istenir: maskeli numara istemciye yeter.
 *
 * Paylaşılan oturumda da log, mesajı gönderen salonun id'siyle yazılır (eski WhatsAppService):
 * her işletme yalnız kendi salon_id'sini görür; üst ve alt salon mesajları birbirine karışmaz.
 */
class WhatsappGonderimSorgusu
{
    private const TABLO = 'whatsapp_gonderim_loglari';

    public const LOG_SIRALAMA = ['tarih' => 'wl.id', 'durum' => 'wl.durum', 'musteri' => 'musteri_adi', 'telefon' => 'wl.telefon'];

    public const ALICI_SIRALAMA = ['son' => 'son_mesaj', 'ilk' => 'ilk_mesaj', 'toplam' => 'toplam', 'musteri' => 'musteri_adi', 'telefon' => 'wl.telefon'];

    private const GECMIS_LIMIT = 100;

    private const VARSAYILAN_GUNLUK_LIMIT = 150;

    public function __construct(
        private AliciAdCozucu $adlar,
        private WhatsappOturumServisi $oturum,
    ) {}

    public function ozet(int $salonId): array
    {
        $bugun = date('Y-m-d');
        $satirlar = DB::table(self::TABLO)
            ->selectRaw('DATE(created_at) as tarih, durum, COUNT(*) as adet')
            ->where('salon_id', $salonId)
            ->where('created_at', '>=', GonderimOzeti::ilkGun($bugun).' 00:00:00')
            ->groupBy('tarih', 'durum')
            ->get();
        $oturum = $this->oturum->coz($salonId)['oturum'] ?? null;

        return GonderimOzeti::hesapla($satirlar, $bugun) + [
            'durum'        => $oturum->whatsapp_durum ?? null,
            'numara'       => $oturum->whatsapp_numara ?? null,
            'gunluk_limit' => (int) (($oturum->whatsapp_gunluk_limit ?? 0) ?: self::VARSAYILAN_GUNLUK_LIMIT),
        ];
    }

    /** @param array{durum?:?int, baslangic?:?string, bitis?:?string} $filtre */
    public function loglar(int $salonId, array $filtre, array $l, bool $telefonGor): array
    {
        $q = $this->logSorgusu($salonId);
        if (isset($filtre['durum'])) {
            $q->where('wl.durum', (int) $filtre['durum']);
        }
        if (! empty($filtre['baslangic'])) {
            $q->where('wl.created_at', '>=', $filtre['baslangic'].' 00:00:00');
        }
        if (! empty($filtre['bitis'])) {
            $q->where('wl.created_at', '<=', $filtre['bitis'].' 23:59:59');
        }
        if ($desen = ListeIstegi::like($l['ara'])) {
            $q->where(function (Builder $w) use ($desen, $telefonGor) {
                $w->where('wl.mesaj', 'like', $desen)->orWhere('wl.hata', 'like', $desen)
                    ->orWhere('u.name', 'like', $desen)->orWhere('ru.name', 'like', $desen);
                if ($telefonGor) {
                    $w->orWhere('wl.telefon', 'like', $desen);
                }
            });
        }

        $toplam = (clone $q)->count();
        $satirlar = $q->orderBy(self::LOG_SIRALAMA[$l['sirala']] ?? 'wl.id', $this->yon($l))->orderByDesc('wl.id')
            ->forPage($l['page'], $l['per_page'])->get();
        $this->adlar->doldur($satirlar);

        return ['data' => $satirlar->map(fn ($s) => $this->logSun($s, $telefonGor))->all()]
            + ListeIstegi::meta($l['page'], $l['per_page'], $toplam);
    }

    public function aliciler(int $salonId, array $l, bool $telefonGor): array
    {
        $q = DB::table(self::TABLO.' as wl')
            ->leftJoin('users as u', 'u.id', '=', 'wl.user_id')
            ->where('wl.salon_id', $salonId)
            ->groupBy('wl.telefon')
            ->selectRaw("wl.telefon, MAX(wl.id) as id, NULLIF(MAX(u.name), '') as musteri_adi, COUNT(*) as toplam,
                SUM(wl.durum = ?) as basari, SUM(wl.durum = ?) as fail, SUM(wl.durum = ?) as fallback,
                MAX(wl.created_at) as son_mesaj, MIN(wl.created_at) as ilk_mesaj", [
                GonderimDurumu::Gonderildi->value, GonderimDurumu::Basarisiz->value, GonderimDurumu::SmsDustu->value,
            ]);
        if ($desen = ListeIstegi::like($l['ara'])) {
            // WHERE değil HAVING: adı eşleşen alıcının kullanıcısız log satırları da toplama girsin.
            $telefonGor
                ? $q->havingRaw('musteri_adi LIKE ? OR wl.telefon LIKE ?', [$desen, $desen])
                : $q->havingRaw('musteri_adi LIKE ?', [$desen]);
        }

        $toplam = DB::query()->fromSub(clone $q, 'a')->count();
        $satirlar = $q->orderBy(self::ALICI_SIRALAMA[$l['sirala']] ?? 'son_mesaj', $this->yon($l))
            ->forPage($l['page'], $l['per_page'])->get();
        $this->adlar->doldur($satirlar);

        return ['data' => $satirlar->map(fn ($s) => [
            'id'          => (int) $s->id,
            'telefon'     => $this->telefon($s->telefon, $telefonGor),
            'musteri_adi' => $s->musteri_adi,
            'toplam'      => (int) $s->toplam,
            'basari'      => (int) $s->basari,
            'fail'        => (int) $s->fail,
            'fallback'    => (int) $s->fallback,
            'son_mesaj'   => $s->son_mesaj,
            'ilk_mesaj'   => $s->ilk_mesaj,
        ])->all()] + ListeIstegi::meta($l['page'], $l['per_page'], $toplam);
    }

    /** @param int $logId alıcı listesindeki id (alıcının herhangi bir log satırı) */
    public function aliciGecmisi(int $salonId, int $logId, bool $telefonGor): array
    {
        $telefon = DB::table(self::TABLO)->where('id', $logId)->where('salon_id', $salonId)->value('telefon');
        if ($telefon === null) {
            throw new BulunamadiException('Alıcı bulunamadı');
        }
        $satirlar = $this->logSorgusu($salonId)->where('wl.telefon', $telefon)
            ->orderByDesc('wl.id')->limit(self::GECMIS_LIMIT)->get();
        $this->adlar->doldur($satirlar);

        return [
            'telefon'     => $this->telefon($telefon, $telefonGor),
            'musteri_adi' => $satirlar->first()?->musteri_adi,
            'data'        => $satirlar->map(fn ($s) => $this->logSun($s, $telefonGor))->all(),
        ];
    }

    /** Müşteri adı önce log'un user_id'sinden, yoksa randevunun müşterisinden (eskiyle aynı öncelik). */
    private function logSorgusu(int $salonId): Builder
    {
        return DB::table(self::TABLO.' as wl')
            ->leftJoin('users as u', 'u.id', '=', 'wl.user_id')
            ->leftJoin('randevular as r', 'r.id', '=', 'wl.randevu_id')
            ->leftJoin('users as ru', 'ru.id', '=', 'r.user_id')
            ->where('wl.salon_id', $salonId)
            ->select('wl.id', 'wl.randevu_id', 'wl.telefon', 'wl.mesaj', 'wl.durum', 'wl.hata', 'wl.gonderim_tarihi', 'wl.created_at')
            ->selectRaw("COALESCE(NULLIF(u.name, ''), NULLIF(ru.name, '')) as musteri_adi");
    }

    private function logSun(object $s, bool $telefonGor): array
    {
        return [
            'id'              => (int) $s->id,
            'randevu_id'      => $s->randevu_id ? (int) $s->randevu_id : null,
            'telefon'         => $this->telefon($s->telefon, $telefonGor),
            'musteri_adi'     => $s->musteri_adi,
            'mesaj'           => $s->mesaj,
            'durum'           => (int) $s->durum,
            'hata'            => $s->hata,
            'gonderim_tarihi' => $s->gonderim_tarihi,
            'created_at'      => $s->created_at,
        ];
    }

    private function telefon(?string $telefon, bool $telefonGor): string
    {
        return $telefonGor ? (string) $telefon : KvkkMaske::telefon($telefon);
    }

    private function yon(array $l): string
    {
        return $l['yon'] === 'asc' ? 'asc' : 'desc';
    }
}
