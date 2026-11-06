<?php

namespace App\Services\SistemYonetim\Entegrasyon;

use App\Domain\Whatsapp\GonderimDurumu;
use App\Exceptions\BulunamadiException;
use App\Models\SistemYoneticisi;
use App\Services\SistemYonetim\SalonKapsami;
use App\Services\Whatsapp\AliciAdCozucu;
use App\Support\ListeIstegi;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Tum salonlar mesaj logu: filtre + sayfa + tek mesaj + CSV. KVKK verisi: destek rolu yalniz kendi salonlari.
 * Tek salonun alici listesi isletme paneliyle ortak: WhatsappGonderimSorgusu. Tarih kosulu aralik; toplam sayimi
 * join'siz (salon/kullanici join'i satir sayisini degistirmez, tablo buyuk).
 */
class WhatsappLogSorgusu
{
    public const SIRALAMA = ['id' => 'wl.id', 'durum' => 'wl.durum'];

    public const CSV_SATIR_SINIRI = 50000;

    public const CSV_BASLIKLAR = ['ID', 'Salon ID', 'Salon', 'Müşteri', 'Telefon', 'Durum', 'Hata', 'Mesaj ID', 'Gönderim Tarihi', 'Oluşturulma', 'Mesaj'];

    public function __construct(private SalonKapsami $kapsam, private AliciAdCozucu $adlar) {}

    /** @param array{salon_id?:?int,durum?:?int,telefon?:?string,baslangic?:?string,bitis?:?string} $f */
    public function liste(array $f, array $l, SistemYoneticisi $yonetici): array
    {
        $q = $this->filtreli($f, $l['ara'] ?? null, $yonetici);
        $toplam = (clone $q)->count();
        $satirlar = $this->zengin($q)
            ->orderBy(self::SIRALAMA[$l['sirala'] ?? ''] ?? 'wl.id', $l['yon'] === 'asc' ? 'asc' : 'desc')->orderByDesc('wl.id')
            ->forPage($l['page'], $l['per_page'])->get();
        $this->adlar->doldur($satirlar);

        return ['data' => $satirlar->map(fn ($s) => $this->sun($s))->all()] + ListeIstegi::meta($l['page'], $l['per_page'], $toplam);
    }

    public function mesaj(int $id, SistemYoneticisi $yonetici): array
    {
        $s = $this->zengin($this->filtreli([], null, $yonetici))->where('wl.id', $id)->first();
        if (! $s) {
            throw new BulunamadiException('Mesaj bulunamadı');
        }
        $this->adlar->doldur(collect([$s]));

        return $this->sun($s);
    }

    /** @return \Generator<int,array> CSV satirlari; sinir ayni filtrelerle ilk CSV_SATIR_SINIRI kayit. */
    public function csvSatirlari(array $f, SistemYoneticisi $yonetici): \Generator
    {
        $imlec = $this->zengin($this->filtreli($f, $f['ara'] ?? null, $yonetici))->orderByDesc('wl.id')->limit(self::CSV_SATIR_SINIRI)->cursor();
        foreach ($imlec as $s) {
            yield [
                $s->id, $s->salon_id, $s->salon_adi, $s->musteri_adi, $s->telefon,
                GonderimDurumu::tryFrom((int) $s->durum)?->ad() ?? $s->durum, $s->hata,
                $s->mesaj_id, $s->gonderim_tarihi, $s->created_at,
                str_replace(["\n", "\r"], ' ', (string) $s->mesaj),
            ];
        }
    }

    private function filtreli(array $f, ?string $ara, SistemYoneticisi $yonetici): Builder
    {
        $q = DB::table('whatsapp_gonderim_loglari as wl');
        if ($yonetici->rol()->salonKapsamliMi()) {
            $q->whereIn('wl.salon_id', $this->kapsam->sorguyaUygula(DB::table('salonlar')->select('id'), $yonetici));
        }
        if (! empty($f['salon_id'])) {
            $q->where('wl.salon_id', (int) $f['salon_id']);
        }
        if (($f['durum'] ?? null) !== null) {
            $q->where('wl.durum', (int) $f['durum']);
        }
        if ($desen = ListeIstegi::like($f['telefon'] ?? null)) {
            $q->where('wl.telefon', 'like', $desen);
        }
        if (! empty($f['baslangic'])) {
            $q->where('wl.created_at', '>=', $f['baslangic'].' 00:00:00');
        }
        if (! empty($f['bitis'])) {
            $q->where('wl.created_at', '<', Carbon::parse($f['bitis'])->addDay()->toDateString().' 00:00:00');
        }
        if ($desen = ListeIstegi::like($ara)) {
            $q->where(fn ($w) => $w->where('wl.mesaj', 'like', $desen)->orWhere('wl.telefon', 'like', $desen));
        }

        return $q;
    }

    private function zengin(Builder $q): Builder
    {
        return $q->leftJoin('salonlar as s', 's.id', '=', 'wl.salon_id')
            ->leftJoin('users as u', 'u.id', '=', 'wl.user_id')
            ->select('wl.id', 'wl.salon_id', 'wl.randevu_id', 'wl.telefon', 'wl.mesaj', 'wl.durum', 'wl.hata', 'wl.mesaj_id', 'wl.gonderim_tarihi', 'wl.created_at', 's.salon_adi', 'u.name as musteri_adi');
    }

    private function sun(object $s): array
    {
        $durum = GonderimDurumu::tryFrom((int) $s->durum);

        return [
            'id'              => (int) $s->id,
            'salon_id'        => (int) $s->salon_id,
            'salon_adi'       => $s->salon_adi ?: '#'.$s->salon_id,
            'musteri_adi'     => $s->musteri_adi,
            'randevu_id'      => $s->randevu_id ? (int) $s->randevu_id : null,
            'telefon'         => $s->telefon,
            'durum'           => (int) $s->durum,
            'durum_ad'        => $durum?->ad() ?? (string) $s->durum,
            'durum_renk'      => $durum?->renk() ?? 'gri',
            'mesaj'           => $s->mesaj,
            'hata'            => $s->hata,
            'mesaj_id'        => $s->mesaj_id,
            'gonderim_tarihi' => $s->gonderim_tarihi,
            'created_at'      => $s->created_at,
        ];
    }
}
