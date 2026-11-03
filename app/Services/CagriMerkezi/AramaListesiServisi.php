<?php

namespace App\Services\CagriMerkezi;

use App\Domain\CagriMerkezi\AramaSonucu;
use App\Domain\CagriMerkezi\CagriMerkeziErisim;
use App\Domain\CagriMerkezi\ListeDurumu;
use App\Exceptions\GecersizIslemException;
use App\Support\AktiviteLog;
use App\Support\KvkkMaske;
use Illuminate\Support\Facades\DB;

/**
 * Arama listeleri — kartlar (Çalışma Ekranı liste seçici) + Liste Yönetimi (segment, taşı,
 * personel değiştir, durum, sil, CSV). Eski arama_kartlarim + cagri_liste_* uçlarının karşılığı.
 * Kayıt düzeyi erişim CagriMerkeziErisimServisi'nde; burada iş kuralı + yazım.
 */
class AramaListesiServisi
{
    public function __construct(private CagriMerkeziErisimServisi $erisim) {}

    /**
     * Liste kartları. Personel yalnız kendine atanan; arşiv ayrı görünüm. Çalışma görünümünde
     * kuyruğu boş liste gelmez (hepsi arama randevusu/işlenmiş → zaten takvimde); $tumu
     * (Liste Yönetimi, yalnız yönetici) bu süzmeyi kaldırır.
     */
    public function kartlar(CagriMerkeziErisim $e, bool $arsiv = false, bool $tumu = false): array
    {
        $q = DB::table('arama_listesi')->where('salon_id', $e->salonId);
        $arsiv ? $q->where('durum', ListeDurumu::ARSIV->value) : $q->where(fn ($w) => $w->whereNull('durum')->orWhere('durum', '!=', ListeDurumu::ARSIV->value));
        if (! $e->yonetici) {
            $q->where('personel_id', $e->personelId ?? 0);
        }
        $listeler = $q->orderByRaw('ISNULL(aranacak_tarih) asc')->orderBy('aranacak_tarih')->orderByDesc('id')
            ->get(['id', 'arama_baslik', 'personel_id', 'aranacak_tarih', 'durum']);

        $sayac = AramaKaydiSorgusu::listeSayaclari($listeler->pluck('id')->all());
        $adlar = $this->personelAdlari($listeler->pluck('personel_id')->all());

        $kartlar = $listeler->map(function ($l) use ($sayac, $adlar) {
            $s = $sayac[$l->id] ?? null;
            $toplam = (int) ($s->toplam ?? 0);
            $arandi = (int) ($s->arandi ?? 0);

            return [
                'id'             => (int) $l->id,
                'baslik'         => (string) $l->arama_baslik,
                'personel_id'    => $l->personel_id !== null ? (int) $l->personel_id : null,
                'personel'       => $l->personel_id ? ($adlar[$l->personel_id] ?? null) : null,
                'aranacak_tarih' => $l->aranacak_tarih ? date('d.m.Y', strtotime($l->aranacak_tarih)) : null,
                'durum'          => ListeDurumu::dbden($l->durum)->value,
                'toplam'         => $toplam,
                'arandi'         => $arandi,
                'kuyruk'         => (int) ($s->kuyruk ?? 0),
                'kalan'          => $toplam - $arandi,
                'ilerleme'       => $toplam > 0 ? (int) round($arandi / $toplam * 100) : 0,
            ];
        });
        if (! $arsiv && ! ($tumu && $e->yonetici)) {
            $kartlar = $kartlar->filter(fn ($k) => $k['kuyruk'] > 0);
        }

        return ['kartlar' => $kartlar->values()->all()];
    }

    /** Sonuca göre segmentler (sabit set, 0 olsa da; 'Arandı' yalnız varsa). */
    public function segmentler(CagriMerkeziErisim $e, int $listeId): array
    {
        $this->erisim->yoneticiZorunlu($e);
        $liste = $this->erisim->liste($listeId, $e);
        $sayim = [];
        foreach (DB::table('aranacak_musteriler')->where('arama_id', $listeId)->select('durum', DB::raw('COUNT(*) as adet'))->groupBy('durum')->get() as $r) {
            $k = $r->durum === null ? 'bekleyen' : (string) (int) $r->durum;
            $sayim[$k] = ($sayim[$k] ?? 0) + (int) $r->adet;
        }
        $kodlar = AramaSonucu::segmentSirasi();
        if (($sayim['1'] ?? 0) > 0) {
            $kodlar[] = '1';
        }

        return [
            'id'          => (int) $liste->id,
            'baslik'      => (string) $liste->arama_baslik,
            'personel_id' => $liste->personel_id !== null ? (int) $liste->personel_id : null,
            'personel_ad' => $liste->personel_id ? (string) DB::table('salon_personelleri')->where('id', $liste->personel_id)->value('personel_adi') : '',
            'durum'       => ListeDurumu::dbden($liste->durum)->value,
            'toplam'      => array_sum($sayim),
            'segmentler'  => array_map(fn ($k) => ['kod' => $k, 'ad' => AramaSonucu::segmentEtiketi($k), 'adet' => $sayim[$k] ?? 0, 'renk' => $k === 'bekleyen' ? 'gri' : AramaSonucu::renkKodu($k)], $kodlar),
            'varsayilan_aktarim' => AramaSonucu::sonucAlinamayanlar(),
        ];
    }

    public function durumDegistir(CagriMerkeziErisim $e, int $listeId, ListeDurumu $durum): array
    {
        $this->erisim->yoneticiZorunlu($e);
        $liste = $this->erisim->liste($listeId, $e);
        DB::table('arama_listesi')->where('id', $listeId)->update(['durum' => $durum->value, 'updated_at' => now()]);
        $this->log($e, 'cagri_liste_durum_degistir', $listeId, $liste->arama_baslik, 'Liste '.$durum->gecisMetni(), ['durum' => $durum->value]);

        return ['durum' => $durum->value, 'mesaj' => '"'.$liste->arama_baslik.'" listesi '.$durum->gecisMetni().'.'];
    }

    /** Listenin tamamını başka personele ata; null → atamayı geri al (hiçbir kuyrukta görünmez). */
    public function personelDegistir(CagriMerkeziErisim $e, int $listeId, ?int $personelId): array
    {
        $this->erisim->yoneticiZorunlu($e);
        $liste = $this->erisim->liste($listeId, $e);
        $hedef = $personelId ? $this->erisim->personel($personelId, $e) : null;
        DB::table('arama_listesi')->where('id', $listeId)->update(['personel_id' => $hedef?->id, 'updated_at' => now()]);
        $mesaj = $hedef ? 'Liste "'.$hedef->personel_adi.'" personeline atandı.' : 'Atama geri alındı — bu liste artık hiçbir personelde görünmüyor.';
        $this->log($e, $hedef ? 'cagri_liste_personel_degistir' : 'cagri_liste_atama_kaldir', $listeId, $liste->arama_baslik, $mesaj, ['personel_id' => $hedef?->id]);

        return ['personel_id' => $hedef?->id, 'mesaj' => $mesaj];
    }

    /**
     * Seçilen sonuç kodlarındaki TÜM müşterileri YENİ listeye taşı ve personele ata (eski
     * cagri_segment_ata + cagri_toplu_aktar tek akış). Görüşme geçmişi / ses kayıtları da taşınır.
     *
     * @param array<int,string> $kodlar ('bekleyen' | durum kodu); boş → sonuç alınamayanlar
     */
    public function tasi(CagriMerkeziErisim $e, int $listeId, array $kodlar, int $personelId, ?string $baslik): array
    {
        $this->erisim->yoneticiZorunlu($e);
        $liste = $this->erisim->liste($listeId, $e);
        $hedef = $this->erisim->personel($personelId, $e);
        $kodlar = array_values(array_unique(array_map('strval', $kodlar ?: AramaSonucu::sonucAlinamayanlar())));

        $sonuc = DB::transaction(function () use ($e, $liste, $hedef, $kodlar, $baslik) {
            $ids = AramaKaydiSorgusu::sonucKodlari(DB::table('aranacak_musteriler')->where('arama_id', $liste->id), $kodlar)
                ->lockForUpdate()->pluck('id')->all();
            if (! $ids) {
                throw new GecersizIslemException('Taşınacak uygun müşteri yok.');
            }
            $baslik = trim((string) $baslik) ?: $liste->arama_baslik.' — '.$this->segmentAdi($kodlar);
            $yeniId = DB::table('arama_listesi')->insertGetId([
                'salon_id' => $e->salonId, 'arama_baslik' => mb_substr($baslik, 0, 255), 'personel_id' => $hedef->id,
                'aranacak_tarih' => null, 'olusturan_yetkili_id' => $e->yetkiliId, 'durum' => ListeDurumu::AKTIF->value,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('aranacak_musteriler')->whereIn('id', $ids)->update(['arama_id' => $yeniId, 'updated_at' => now()]);
            DB::table('gorusme_notlari')->whereIn('aranacak_musteri_id', $ids)->update(['arama_id' => $yeniId]);

            return ['adet' => count($ids), 'yeni_liste_id' => (int) $yeniId, 'baslik' => $baslik];
        });

        $mesaj = $sonuc['adet'].' müşteri "'.$hedef->personel_adi.'" personeline taşındı (yeni liste: '.$sonuc['baslik'].').';
        $this->log($e, 'cagri_liste_segment_tasi', (int) $liste->id, $liste->arama_baslik, $mesaj,
            ['kodlar' => $kodlar, 'adet' => $sonuc['adet'], 'yeni_liste_id' => $sonuc['yeni_liste_id'], 'personel_id' => (int) $hedef->id]);

        return $sonuc + ['mesaj' => $mesaj];
    }

    /** Listeyi + içindeki tüm müşteri ve görüşme kayıtlarını siler (geri alınamaz). */
    public function sil(CagriMerkeziErisim $e, int $listeId): array
    {
        $this->erisim->yoneticiZorunlu($e);
        $liste = $this->erisim->liste($listeId, $e);
        $adet = DB::transaction(function () use ($listeId) {
            $ids = DB::table('aranacak_musteriler')->where('arama_id', $listeId)->pluck('id')->all();
            DB::table('gorusme_notlari')->where('arama_id', $listeId)->delete();
            if ($ids) {
                DB::table('gorusme_notlari')->whereIn('aranacak_musteri_id', $ids)->delete();
            }
            DB::table('aranacak_musteriler')->where('arama_id', $listeId)->delete();
            DB::table('arama_listesi')->where('id', $listeId)->delete();

            return count($ids);
        });
        $mesaj = '"'.$liste->arama_baslik.'" listesi ve '.$adet.' müşteri kaydı silindi.';
        $this->log($e, 'cagri_liste_sil', $listeId, $liste->arama_baslik, $mesaj, ['adet' => $adet]);

        return ['mesaj' => $mesaj];
    }

    /**
     * Segment CSV (Excel uyumlu, UTF-8 BOM, ';'). KVKK: maskeli kullanıcıya maskeli yazılır
     * (yönetici zorunlu olduğundan pratikte tam veri; kural tek yerde kalsın).
     *
     * @return array{dosya:string, icerik:string}
     */
    public function csv(CagriMerkeziErisim $e, int $listeId, string $kod): array
    {
        $this->erisim->yoneticiZorunlu($e);
        $liste = $this->erisim->liste($listeId, $e);
        $satirlar = AramaKaydiSorgusu::sonucKodlari(
            DB::table('aranacak_musteriler as am')->leftJoin('users as u', 'u.id', '=', 'am.user_id')->where('am.arama_id', $listeId), [$kod], 'am')
            ->orderBy('u.name')->get(['u.name', 'u.cep_telefon', 'am.durum', 'am.musteri_not', 'am.tarih', 'am.saat']);

        $temiz = fn ($s) => str_replace(["\r", "\n", ';'], ' ', (string) $s);
        $csv = "\xEF\xBB\xBF"."Ad Soyad;Telefon;Durum;Gorusme Notu;Randevu Tarih;Randevu Saat\n";
        foreach ($satirlar as $s) {
            $ad = $e->maskele() ? KvkkMaske::adSoyad($s->name) : $s->name;
            $tel = $e->maskele() ? KvkkMaske::telefon($s->cep_telefon) : $s->cep_telefon;
            $csv .= implode(';', array_map($temiz, [$ad, $tel, AramaSonucu::metin($s->durum), $s->musteri_not, $s->tarih, $s->saat]))."\n";
        }
        $this->log($e, 'csv_export', $listeId, $liste->arama_baslik, 'Arama listesi segmenti indirildi ('.AramaSonucu::segmentEtiketi($kod).')', ['kod' => $kod, 'adet' => $satirlar->count()]);

        $etiket = preg_replace('/[^a-zA-Z0-9]/', '', strtr(AramaSonucu::segmentEtiketi($kod), ['ç' => 'c', 'Ç' => 'C', 'ğ' => 'g', 'Ğ' => 'G', 'ı' => 'i', 'İ' => 'I', 'ö' => 'o', 'Ö' => 'O', 'ş' => 's', 'Ş' => 'S', 'ü' => 'u', 'Ü' => 'U']));

        return ['dosya' => 'liste-'.$listeId.'-'.$etiket.'.csv', 'icerik' => $csv];
    }

    /** Personelin "Arama Randevuları (Manuel)" listesi (yoksa oluşturur) — manuel ekleme + callback devri. */
    public function manuelListeId(CagriMerkeziErisim $e, int $personelId): int
    {
        $baslik = (string) config('cagri_merkezi.manuel_liste_baslik');
        $id = DB::table('arama_listesi')->where('salon_id', $e->salonId)->where('personel_id', $personelId)->where('arama_baslik', $baslik)->value('id');

        return (int) ($id ?: DB::table('arama_listesi')->insertGetId([
            'salon_id' => $e->salonId, 'arama_baslik' => $baslik, 'personel_id' => $personelId, 'aranacak_tarih' => null,
            'durum' => ListeDurumu::AKTIF->value, 'olusturan_yetkili_id' => $e->yetkiliId, 'filtre_snapshot' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]));
    }

    /** @param array<int,int|null> $idler @return array<int,string> */
    public function personelAdlari(array $idler): array
    {
        $idler = array_values(array_unique(array_filter(array_map('intval', $idler))));

        return $idler ? DB::table('salon_personelleri')->whereIn('id', $idler)->pluck('personel_adi', 'id')->all() : [];
    }

    private function segmentAdi(array $kodlar): string
    {
        if (count($kodlar) === 1) {
            return AramaSonucu::segmentEtiketi($kodlar[0]);
        }
        $varsayilan = AramaSonucu::sonucAlinamayanlar();
        sort($varsayilan);
        $s = $kodlar;
        sort($s);

        return $s === $varsayilan ? 'Sonuç Alınamayanlar' : 'Seçili Sonuçlar';
    }

    private function log(CagriMerkeziErisim $e, string $islem, int $listeId, ?string $baslik, string $aciklama, array $meta = []): void
    {
        AktiviteLog::yaz($e->salonId, $e->yetkiliId, $islem, 'arama_listesi', $listeId, $baslik, $aciklama, $meta);
    }
}
