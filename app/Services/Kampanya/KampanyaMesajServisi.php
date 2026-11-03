<?php

namespace App\Services\Kampanya;

use App\Domain\Kampanya\Indirim;
use App\Domain\Kampanya\KalemSecimi;
use App\Domain\Kampanya\Kanal;
use App\Domain\Kampanya\MesajMetni;
use App\Domain\Kampanya\SablonKimligi;
use App\Domain\Kampanya\Senaryo;
use App\Exceptions\GecersizIslemException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kampanya MESAJI — şablon kataloğu, şablon metni, kayıt metni, önizleme.
 * Eski kampanya-sablon-filtre + kampanyaIceriginiGoruntule + kampanyaMesajOnizle karşılığı;
 * yer tutucu kuralları Domain\Kampanya\MesajMetni'nde.
 *
 * Kayıt kuralı (tek): istekte `mesaj` varsa O kullanılır; yoksa seçili şablondan türetilir.
 * Her iki yolda da kampanya aşaması yer tutucuları ({işletmeden}, {indirim}, {ürün_hizmet_paket})
 * kayıtta çözülür, {müşteri}/{gün} gönderimde (eski cron) kişiye göre çözülür.
 */
class KampanyaMesajServisi
{
    /**
     * Şablon kataloğu: senaryolar (sistem + salon) · salonun SMS taslakları · sistem şablonları.
     * value = eski önekli değer (web + Flutter aynı). Kanal filtresi (sistem şablonlarında
     * gorev_turu kolonu varsa) istemciye `kanal` alanı olarak verilir; senaryolar her kanalda görünür.
     */
    public function sablonlar(int $salonId): array
    {
        $senaryolar = DB::table('kampanya_senaryolari')->where('aktif', 1)
            ->where(fn ($w) => $w->whereNull('salon_id')->orWhere('salon_id', $salonId))
            ->orderBy('ad')->get(['id', 'ad', 'salon_id'])
            ->map(fn ($s) => ['value' => 'senaryo-'.$s->id, 'label' => (string) $s->ad, 'tur' => SablonKimligi::SENARYO,
                'kanal' => null, 'duzenlenebilir' => $s->salon_id !== null]);

        $taslaklar = DB::table('sms_taslaklari')->where('salon_id', $salonId)->orderByDesc('id')->get(['id', 'baslik'])
            ->map(fn ($s) => ['value' => 'sablon-'.$s->id, 'label' => (string) $s->baslik, 'tur' => SablonKimligi::TASLAK,
                'kanal' => null, 'duzenlenebilir' => true]);

        $kanalKolonu = Schema::hasColumn('kampanya_sablonlari', 'gorev_turu');
        $sistem = DB::table('kampanya_sablonlari')->orderBy('baslik')->get(array_merge(['id', 'baslik'], $kanalKolonu ? ['gorev_turu'] : []))
            ->map(fn ($s) => ['value' => (string) $s->id, 'label' => (string) $s->baslik, 'tur' => SablonKimligi::SISTEM,
                'kanal' => $kanalKolonu && $s->gorev_turu ? (int) $s->gorev_turu : null, 'duzenlenebilir' => false]);

        return [...$senaryolar, ...$taslaklar, ...$sistem];
    }

    /**
     * Şablonun KAMPANYAYA HAZIR metni (yer tutucular açık; aramada şablon selamı atılmış) +
     * kayıt yan bilgileri. Salona ait olmayan taslak / senaryo → 422.
     *
     * @return array{metin:string, ad:?string, randevu_olustur:?bool}
     */
    public function sablon(int $salonId, SablonKimligi $s, Kanal $kanal): array
    {
        [$metin, $ad, $randevu] = match ($s->tur) {
            SablonKimligi::SENARYO => $this->senaryoSablonu($salonId, $s->id),
            SablonKimligi::TASLAK  => [DB::table('sms_taslaklari')->where('id', $s->id)->where('salon_id', $salonId)->value('taslak_icerik'), null, null],
            default                => $this->sistemSablonu($s->id),
        };
        if ($metin === null) {
            throw new GecersizIslemException('Mesaj şablonu bulunamadı');
        }
        $metin = (string) $metin;

        return [
            'metin'           => $kanal === Kanal::Arama ? MesajMetni::ilkCumleyiAt($metin) : trim($metin),
            'ad'              => $ad !== null ? (string) $ad : null,
            'randevu_olustur' => $randevu,
        ];
    }

    /** Kayda yazılacak metin (kampanya aşaması çözülmüş). */
    public function kayitMetni(int $salonId, string $hamMetin, ?Indirim $indirim, ?string $kalemAdi): string
    {
        return trim(MesajMetni::kampanyaAsamasi($hamMetin, $this->isletmeAdi($salonId), $indirim, $kalemAdi));
    }

    /**
     * Sihirbaz önizlemesi: şablon metni (düzenlenebilir), kayda yazılacak metin ve örnek müşteri
     * için son metin (aramada cron selamı dahil).
     */
    public function onizle(int $salonId, Kanal $kanal, ?SablonKimligi $sablon, ?string $mesaj, ?Indirim $indirim, ?KalemSecimi $kalem): array
    {
        $ham = $mesaj !== null && trim($mesaj) !== '' ? trim($mesaj) : ($sablon ? $this->sablon($salonId, $sablon, $kanal)['metin'] : '');
        $kayit = $this->kayitMetni($salonId, $ham, $indirim, $this->kalemAdi($salonId, $kalem));
        $ornek = $this->ornekMusteri($salonId);

        $onizleme = MesajMetni::onizleme($kayit, $kanal, $ornek['ad'], $ornek['gun'], $this->isletmeAdi($salonId));

        return [
            'sablon_metni'  => $ham,
            'kayit_metni'   => $kayit,
            'onizleme'      => $onizleme,
            'ornek_musteri' => $ornek['ad'],
            'karakter'      => mb_strlen($onizleme),
        ];
    }

    /** Senaryo sihirbazı önizlemesi için çözücü (örnek müşteri + işletme + örnek değerler). */
    public function ornekCozucu(int $salonId, array $ornek = []): callable
    {
        $o = $ornek + Senaryo::ORNEK;
        $musteri = $this->ornekMusteri($salonId, false)['ad'];
        $isletme = $this->isletmeAdi($salonId);

        return fn (string $m) => trim(strtr($m, [
            '{müşteri}' => $musteri, '{işletmeden}' => $isletme, '{indirim}' => (string) $o['indirim'],
            '{gün}' => (string) $o['gun'], '{ürün_hizmet_paket}' => (string) $o['hizmet'],
        ]));
    }

    /** Seçili kalemin adı; salona ait değilse 422. */
    public function kalemAdi(int $salonId, ?KalemSecimi $k): ?string
    {
        if (! $k) {
            return null;
        }
        $ad = match ($k->tur) {
            'hizmet' => DB::table('salon_sunulan_hizmetler as sh')->join('hizmetler as h', 'h.id', '=', 'sh.hizmet_id')
                ->where('sh.salon_id', $salonId)->where('sh.hizmet_id', $k->id)->value('h.hizmet_adi'),
            'urun'   => DB::table('urunler')->where('salon_id', $salonId)->where('id', $k->id)->value('urun_adi'),
            'paket'  => DB::table('paketler')->where('salon_id', $salonId)->where('id', $k->id)->value('paket_adi'),
        };

        return $ad !== null ? trim((string) $ad) : throw new GecersizIslemException('Seçilen hizmet / ürün / paket bulunamadı');
    }

    /** Telaffuz adı (santral), boşsa salon adı — eski kural. */
    public function isletmeAdi(int $salonId): string
    {
        $s = DB::table('salonlar')->where('id', $salonId)->first(['santral_telaffuz_hatirlatma_aramasi', 'salon_adi']);

        return trim((string) ($s?->santral_telaffuz_hatirlatma_aramasi ?: $s?->salon_adi));
    }

    /**
     * Önizleme müşterisi: salonun en son eklenen aktif portföy müşterisi (eski kural).
     *
     * @return array{ad:string, gun:int}
     */
    private function ornekMusteri(int $salonId, bool $gunHesapla = true): array
    {
        $m = DB::table('musteri_portfoy as mp')->join('users as u', 'u.id', '=', 'mp.user_id')
            ->where('mp.salon_id', $salonId)->where('mp.aktif', 1)->orderByDesc('mp.id')->first(['u.id', 'u.name']);
        if (! $m || trim((string) $m->name) === '') {
            return ['ad' => 'Örnek Müşteri', 'gun' => 30];
        }

        return ['ad' => trim((string) $m->name), 'gun' => $gunHesapla ? $this->yoklukGunu((int) $m->id, $salonId) : 0];
    }

    /**
     * {gün}: son GELDİĞİ randevudan bu yana gün (eski KampanyaYonetimi::musteriYoklukGunu
     * BİREBİR — durum=1 & randevuya_geldi=1 & tarih<=bugün; bugün geldiyse 1, hiç yoksa 30).
     */
    public function yoklukGunu(int $userId, int $salonId): int
    {
        $son = DB::table('randevular')->where('user_id', $userId)->where('salon_id', $salonId)
            ->where('durum', 1)->where('randevuya_geldi', 1)->whereDate('tarih', '<=', today())->max('tarih');

        return $son ? max(1, (int) now()->startOfDay()->diffInDays(\Illuminate\Support\Carbon::parse($son)->startOfDay(), true)) : 30;
    }

    /** @return array{0:?string,1:?string,2:null} sistem şablonu (global) [metin, başlık] */
    private function sistemSablonu(int $id): array
    {
        $s = DB::table('kampanya_sablonlari')->where('id', $id)->first(['icerik', 'baslik']);

        return [$s?->icerik, $s?->baslik, null];
    }

    /** @return array{0:?string,1:?string,2:?bool} [metin, ad, randevu_olustur] */
    private function senaryoSablonu(int $salonId, int $id): array
    {
        $s = DB::table('kampanya_senaryolari')->where('id', $id)->where('aktif', 1)
            ->where(fn ($w) => $w->whereNull('salon_id')->orWhere('salon_id', $salonId))->first(['ad', 'gorev_turu', 'adimlar', 'aksiyonlar']);
        if (! $s) {
            return [null, null, null];
        }
        $adimlar = json_decode((string) $s->adimlar, true) ?: [];
        $aks = json_decode((string) $s->aksiyonlar, true) ?: [];

        return [Senaryo::sablonMetni($adimlar, (int) $s->gorev_turu), (string) $s->ad, ! empty($aks['randevu_olustur'])];
    }
}
