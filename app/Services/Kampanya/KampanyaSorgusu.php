<?php

namespace App\Services\Kampanya;

use App\Domain\Kampanya\Kanal;
use App\Exceptions\BulunamadiException;
use App\Support\ListeIstegi;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Kampanya OKUMA — liste (kanal sekme sayaçlarıyla) + tek kayıt (detay / düzenleme prefill'i).
 * Eski paket_kampanyalar (web DataTable) + v1 kampanyalar / kampanyaDuzenleGetir / kampanyaDetay
 * başlığının TEK karşılığı; biçim KampanyaSunumu'nda.
 *
 * Eskiden düzeltilenler: katılımcısız kampanya "1 katılımcı" görünüyordu (COUNT(k.id) → alt sorgu);
 * paket kampanyalarının Hizmet/Ürün kolonu boştu (paketler artık birleştiriliyor); detay başlığı
 * katılımcısız kampanyada null dönüyordu; kayıtlar salon kapsamı olmadan açılabiliyordu.
 */
class KampanyaSorgusu
{
    public const SIRALAMA = [
        'id' => 'k.id', 'paket_isim' => 'k.paket_isim', 'gorev_turu' => 'k.gorev_turu',
        'baslangic_tarihi' => 'k.baslangic_tarihi', 'bitis_tarihi' => 'k.bitis_tarihi', 'katilimci_sayisi' => 'katilimci_sayisi',
    ];

    private const SECIM = [
        'k.id', 'k.salon_id', 'k.gorev_turu', 'k.paket_isim', 'k.hizmet_id', 'k.urun_id', 'k.paket_id', 'k.mesaj',
        'k.musteri_turu', 'k.hedef_filtre', 'k.hedef_grup', 'k.hedef_cinsiyet', 'k.indirim_kodu', 'k.indirim_turu',
        'k.baslangic_tarihi', 'k.bitis_tarihi', 'k.asistan_tarih_saat', 'k.sms_tarih_saat', 'k.bildirim_tarih_saat',
        'k.arama_ile_gonderim', 'k.sms_ile_gonderim', 'k.bildirim_ile_gonderim', 'k.sms_gonderildi', 'k.randevu_olustur',
        'k.created_at', 'h.hizmet_adi', 'ur.urun_adi', 'p.paket_adi',
    ];

    /**
     * @param  array{page:int, per_page:int, ara:?string, sirala:?string, yon:string}  $liste
     * @return array{data: array, current_page:int, last_page:int, per_page:int, total:int, sayaclar: array<string,int>}
     */
    public function listele(int $salonId, array $liste, ?Kanal $kanal, array $ctx): array
    {
        $q = $this->temel($salonId);
        if ($like = ListeIstegi::like($liste['ara'] ?? null)) {
            $q->where(fn ($w) => $w->where('k.paket_isim', 'like', $like)->orWhere('h.hizmet_adi', 'like', $like)
                ->orWhere('ur.urun_adi', 'like', $like)->orWhere('p.paket_adi', 'like', $like)
                ->orWhere('k.musteri_turu', 'like', $like)->orWhere('k.indirim_kodu', 'like', $like));
        }

        // Sekme sayaçları: kanal filtresi HARİÇ aynı arama.
        $sayaclar = ['tumu' => 0];
        foreach (Kanal::cases() as $c) {
            $sayaclar[(string) $c->value] = 0;
        }
        foreach ((clone $q)->selectRaw('k.gorev_turu, COUNT(*) as adet')->groupBy('k.gorev_turu')->get() as $r) {
            $sayaclar[(string) (int) $r->gorev_turu] = (int) $r->adet;
            $sayaclar['tumu'] += (int) $r->adet;
        }

        if ($kanal) {
            $q->where('k.gorev_turu', $kanal->value);
        }
        $toplam = (clone $q)->count();
        $kolon = self::SIRALAMA[$liste['sirala'] ?? ''] ?? 'k.id';
        $satirlar = $this->katilimSayilari($q, $salonId)->select(self::SECIM)->selectRaw($this->sayacSecimi())
            ->orderBy($kolon, $liste['yon'] ?? 'desc')->orderByDesc('k.id')
            ->forPage($liste['page'], $liste['per_page'])->get();

        return ['data' => $satirlar->map(fn ($r) => KampanyaSunumu::satir($r, $ctx))->all()]
            + ListeIstegi::meta($liste['page'], $liste['per_page'], $toplam)
            + ['sayaclar' => $sayaclar];
    }

    /** Tek kampanya (detay + düzenleme alanları). Pasif / başka salon → 404. */
    public function getir(int $id, array $ctx): array
    {
        $salonId = $this->salonId($id) ?? 0;
        $r = $this->katilimSayilari($this->temel($salonId)->where('k.id', $id), $salonId)
            ->select(self::SECIM)->selectRaw($this->sayacSecimi())->first();

        return $r ? KampanyaSunumu::detay($r, $ctx, $this->aramaAyariAcik((int) $r->salon_id))
            : throw new BulunamadiException('Kampanya bulunamadı');
    }

    /** Ham kayıt (yazma servisi için; kilitli okuma isteğe bağlı). */
    public function kayit(int $id, int $salonId, bool $kilitle = false): object
    {
        $q = DB::table('kampanya_yonetimi')->where('id', $id)->where('salon_id', $salonId)->where('aktifmi', 1);

        return ($kilitle ? $q->lockForUpdate() : $q)->first() ?? throw new BulunamadiException('Kampanya bulunamadı');
    }

    /** Aktif kampanyanın salonu (kapsam denetimi); yoksa null. */
    public function salonId(int $id): ?int
    {
        $s = DB::table('kampanya_yonetimi')->where('id', $id)->where('aktifmi', 1)->value('salon_id');

        return $s ? (int) $s : null;
    }

    /** Kampanya araması cron kapısı (E-Asistan ayar_id=8) — arama kanalı teşhisi için. */
    public function aramaAyariAcik(int $salonId): bool
    {
        return (int) DB::table('salon_esistan_ayarlari')->where('salon_id', $salonId)->where('ayar_id', 8)->value('acik_kapali') === 1;
    }

    // ───────────────────────────── iç adımlar ─────────────────────────────

    private function temel(int $salonId): Builder
    {
        return DB::table('kampanya_yonetimi as k')
            ->leftJoin('hizmetler as h', 'h.id', '=', 'k.hizmet_id')
            ->leftJoin('urunler as ur', 'ur.id', '=', 'k.urun_id')
            ->leftJoin('paketler as p', 'p.id', '=', 'k.paket_id')
            ->where('k.salon_id', $salonId)
            ->where('k.aktifmi', 1);
    }

    /** Katılımcı özet sayıları — salonun kampanyalarıyla sınırlı tek gruplu alt sorgu (LEFT JOIN). */
    private function katilimSayilari(Builder $q, int $salonId): Builder
    {
        $alt = DB::table('kampanya_katilimcilari')
            ->whereIn('kampanya_id', fn ($s) => $s->select('id')->from('kampanya_yonetimi')->where('salon_id', $salonId)->where('aktifmi', 1))
            ->groupBy('kampanya_id')->selectRaw(
            'kampanya_id, COUNT(*) as katilimci_sayisi, SUM(indirim_kodu_kullanildi = 1) as kullanan, '
            .'SUM(durum_asistan = 1) as katilan, SUM(durum_asistan = 0) as katilmayan, '
            .'SUM(tekrar_arandi = 1 AND durum_asistan IS NULL) as ulasilamadi, SUM(durum_asistan IS NULL) as bekleyen'
        );

        return $q->leftJoinSub($alt, 'ks', 'ks.kampanya_id', '=', 'k.id');
    }

    private function sayacSecimi(): string
    {
        return implode(', ', array_map(fn ($a) => "COALESCE(ks.$a, 0) as $a",
            ['katilimci_sayisi', 'kullanan', 'katilan', 'katilmayan', 'ulasilamadi', 'bekleyen']));
    }
}
