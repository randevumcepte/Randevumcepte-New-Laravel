<?php

namespace App\Services\CagriMerkezi;

use App\Domain\CagriMerkezi\AramaFiltresi;
use App\Domain\CagriMerkezi\CagriMerkeziErisim;
use App\Domain\CagriMerkezi\CagriMerkeziEylemi;
use App\Domain\CagriMerkezi\ListeDurumu;
use App\Exceptions\BulunamadiException;
use App\Exceptions\CakismaException;
use App\Exceptions\GecersizIslemException;
use App\Support\AktiviteLog;
use App\Support\KvkkMaske;
use App\Support\ListeIstegi;
use Illuminate\Support\Facades\DB;

/**
 * FİLTREYLE ARAMA LİSTESİ OLUŞTUR (yönetici) — eski arama_filtre_onizleme + arama_listesi_ekle
 * (modaldialogs/arama_listesi_ekle) karşılığı.
 *
 * Seçim üç modla tek uçta (eski ekran tüm id'leri istemciye çekip geri gönderiyordu):
 *   tumu   → filtreye uyan TÜM müşteriler (sunucuda çözülür)
 *   aralik → isim sırasında bas..bit (eski "İlk 100 / Sonraki 100 / 101–200")
 *   secili → istemcinin işaretlediği id'ler (filtre kümesiyle KESİŞTİRİLİR — başka salonun / filtre
 *            dışı müşterisi listeye giremez)
 */
class ListeOlusturmaServisi
{
    /** Tek listede en fazla müşteri (toplu insert parça boyu ile birlikte). */
    private const MAKS = 20000;

    public function __construct(
        private CagriMerkeziErisimServisi $erisim,
        private AramaFiltreSorgusu $filtre,
    ) {}

    /** Form seçenekleri + atanabilir personel (ekran açılışı). */
    public function formVerisi(CagriMerkeziErisim $e): array
    {
        $this->erisim->eylemZorunlu($e, CagriMerkeziEylemi::ListeYonet);

        return ['filtreler' => AramaFiltresi::secenekler()];
    }

    /**
     * Canlı önizleme: filtreye uyan sayı, filtresiz aranabilir toplam ve sayfalı müşteri listesi
     * (sıra no ile — aralık seçimi ekranla aynı).
     */
    public function onizleme(CagriMerkeziErisim $e, AramaFiltresi $f, int $sayfa, int $boy): array
    {
        $this->erisim->eylemZorunlu($e, CagriMerkeziEylemi::ListeYonet);
        $toplam = $this->filtre->say($e->salonId, $f->aramasiz());
        $listeToplam = $f->ara !== null ? $this->filtre->say($e->salonId, $f) : $toplam;
        $satirlar = $this->filtre->sorgu($e->salonId, $f)->distinct()
            ->forPage($sayfa, $boy)->get(['mp.user_id', 'u.name', 'u.cep_telefon', 'u.cinsiyet', 'mp.created_at']);
        $ilkSira = ($sayfa - 1) * $boy;
        $maske = $e->maskele();

        return [
            'toplam'           => $toplam,
            'toplam_filtresiz' => $this->filtre->say($e->salonId, AramaFiltresi::diziden([])),
            'ozet'             => $f->ozet(),
            'data'             => $satirlar->values()->map(fn ($m, $i) => [
                'id' => (int) $m->user_id,
                'ad' => $maske ? KvkkMaske::adSoyad($m->name) : (string) $m->name,
                'telefon' => $maske ? KvkkMaske::telefon($m->cep_telefon) : (string) $m->cep_telefon,
                'sira' => $f->ara === null ? $ilkSira + $i + 1 : null,
                'kayit_tarihi' => $m->created_at ? substr((string) $m->created_at, 0, 10) : null,
            ])->all(),
        ] + ListeIstegi::meta($sayfa, $boy, $listeToplam);
    }

    /**
     * @param array{baslik:string, personel_id:int, aranacak_tarih:?string, mod:string, ids?:int[], bas?:?int, bit?:?int} $v
     */
    public function olustur(CagriMerkeziErisim $e, AramaFiltresi $f, array $v): array
    {
        $this->erisim->eylemZorunlu($e, CagriMerkeziEylemi::ListeYonet);
        $baslik = trim($v['baslik']);
        $f = $f->aramasiz();

        $ids = match ($v['mod']) {
            'tumu'   => $this->filtre->idler($e->salonId, $f),
            'aralik' => $this->filtre->idler($e->salonId, $f, max(1, (int) $v['bas']), max(0, (int) $v['bit'] - (int) $v['bas'] + 1)),
            'secili' => array_values(array_intersect(array_map('intval', $v['ids'] ?? []), $this->filtre->idler($e->salonId, $f))),
            default  => throw new GecersizIslemException('Geçersiz seçim modu'),
        };
        if (! $ids) {
            throw new GecersizIslemException('Seçilen filtreye uyan müşteri bulunamadı.');
        }

        $l = $this->listeYaz($e->salonId, $e->yetkiliId, $baslik, (int) $v['personel_id'], $v['aranacak_tarih'] ?? null, $f->snapshot(), $ids);
        $mesaj = count($ids).' müşteri ile "'.$baslik.'" arama listesi oluşturuldu ('.$l['personel_adi'].').';
        AktiviteLog::yaz($e->salonId, $e->yetkiliId, 'cagri_liste_olustur', 'arama_listesi', $l['id'], $baslik, $mesaj,
            ['adet' => count($ids), 'personel_id' => (int) $v['personel_id'], 'filtre' => $f->ozet(), 'mod' => $v['mod']]);

        return ['id' => $l['id'], 'adet' => count($ids), 'mesaj' => $mesaj];
    }

    /**
     * Arama listesi + aranacak müşteriler yazımı — TEK yer (filtreyle oluşturma ve Reklam Yönetimi
     * "Personele Ata" ortak). Personel salona ait olmalı; aynı başlıkta liste varsa 409.
     *
     * @param  int[]  $ids  user_id'ler
     * @return array{id:int, personel_adi:string}
     */
    public function listeYaz(int $salonId, ?int $yetkiliId, string $baslik, int $personelId, ?string $aranacakTarih, array $snapshot, array $ids): array
    {
        if (count($ids) > self::MAKS) {
            throw new GecersizIslemException('Bir listede en fazla '.number_format(self::MAKS, 0, ',', '.').' müşteri olabilir; filtreyi daraltın.');
        }
        $personel = DB::table('salon_personelleri')->where('id', $personelId)->where('salon_id', $salonId)->first(['id', 'personel_adi'])
            ?? throw new BulunamadiException('Geçerli bir personel seçin');

        $id = DB::transaction(function () use ($salonId, $yetkiliId, $baslik, $personel, $aranacakTarih, $snapshot, $ids) {
            if (DB::table('arama_listesi')->where('salon_id', $salonId)->where('arama_baslik', $baslik)->lockForUpdate()->exists()) {
                throw new CakismaException('Bu isimde bir arama listesi zaten mevcut.');
            }
            $simdi = now();
            $id = DB::table('arama_listesi')->insertGetId([
                'salon_id' => $salonId, 'arama_baslik' => mb_substr($baslik, 0, 255), 'personel_id' => (int) $personel->id,
                'aranacak_tarih' => $aranacakTarih, 'filtre_snapshot' => json_encode($snapshot ?: new \stdClass, JSON_UNESCAPED_UNICODE),
                'olusturan_yetkili_id' => $yetkiliId, 'durum' => ListeDurumu::AKTIF->value, 'created_at' => $simdi, 'updated_at' => $simdi,
            ]);
            foreach (array_chunk($ids, 500) as $parca) {
                DB::table('aranacak_musteriler')->insert(array_map(fn ($uid) => [
                    'user_id' => $uid, 'arama_id' => $id, 'durum' => null, 'created_at' => $simdi, 'updated_at' => $simdi,
                ], $parca));
            }

            return (int) $id;
        });

        return ['id' => $id, 'personel_adi' => (string) $personel->personel_adi];
    }
}
