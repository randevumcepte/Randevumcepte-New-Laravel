<?php

namespace App\Services\CagriMerkezi;

use App\Domain\CagriMerkezi\AramaRandevuDurumu;
use App\Domain\CagriMerkezi\AramaSonucu;
use App\Domain\CagriMerkezi\CagriMerkeziErisim;
use App\Domain\CagriMerkezi\ListeDurumu;
use App\Support\KvkkMaske;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Arama randevuları (callback) — takvim (Arama Randevuları sekmesi), hatırlatma popup'ı
 * (yaklaşanlar) ve ajanda (Arama Randevularım). Eski arama_randevu_takvim_verileri +
 * cagri_yaklasan_randevular + arama_randevularim karşılığı; durum/renk/faz kuralları
 * Domain\CagriMerkezi\AramaRandevuDurumu'nda (tek yer).
 *
 * Kapsam: personel yalnız kendine atanan listelerin randevularını görür; KVKK maskesi uygulanır.
 */
class AramaRandevuServisi
{
    /**
     * Takvim olayları (tarih aralığı) — Flutter TakvimEvent alanları korunur; ek: durum_kod,
     * islenmis, saat. Varsayılan aralık eskiyle aynı: −31 / +62 gün.
     */
    public function takvim(CagriMerkeziErisim $e, ?string $bas, ?string $bit): array
    {
        $bas = $bas ?: now()->subDays(31)->toDateString();
        $bit = $bit ?: now()->addDays(62)->toDateString();
        $q = $this->taban($e)->leftJoin('salon_personelleri as p', 'p.id', '=', 'al.personel_id')
            ->whereBetween('am.tarih', [$bas, $bit])
            ->where(fn ($w) => $w->whereIn('am.durum', [3, 6, 7])->orWhereNotNull('am.ar_tamamlandi_at'));
        $kayitlar = $q->limit(2000)->get(['am.id', 'am.arama_id', 'am.tarih', 'am.saat', 'am.durum', 'am.musteri_not', 'am.user_id',
            'am.ar_tamamlandi_at', 'am.ar_gecikti', 'u.name as musteri_ad', 'u.cep_telefon', 'p.personel_adi', 'al.personel_id']);

        $simdi = time();
        $maske = $e->maskele();
        $olaylar = $kayitlar->map(function ($k) use ($simdi, $maske) {
            $ts = strtotime($k->tarih.' '.$k->saat);
            $d = AramaRandevuDurumu::takvim($k->durum !== null ? (int) $k->durum : null, $ts, $k->ar_tamamlandi_at, (int) $k->ar_gecikti === 1, $simdi);
            $personel = $k->personel_adi ?: 'Personel';
            $musteri = $maske ? KvkkMaske::adSoyad($k->musteri_ad) : ($k->musteri_ad ?: 'Müşteri');

            return [
                'id'          => (int) $k->id,
                'arama_id'    => (int) $k->arama_id,
                'title'       => $personel.' · '.$musteri,
                'start'       => date('Y-m-d\TH:i:s', $ts),
                'saat'        => date('H:i', $ts),
                'color'       => $d['renk'],
                'durum_kod'   => $d['kod'],
                'durum_metin' => $d['metin'],
                'islenmis'    => $d['islenmis'],
                'personel_id' => (int) $k->personel_id,
                'personel'    => $personel,
                'musteri'     => $musteri ?: 'Müşteri',
                'user_id'     => (int) $k->user_id,
                'not'         => (string) ($k->musteri_not ?? ''),
                'telefon'     => ! $maske && $k->cep_telefon ? (string) $k->cep_telefon : '',
            ];
        })->sortBy('start')->values()->all();

        return ['olaylar' => $olaylar, 'lejand' => AramaRandevuDurumu::lejand(), 'bas' => $bas, 'bit' => $bit];
    }

    /** Hatırlatma popup'ı: [şimdi−12 sa, şimdi+6 dk] bekleyen arama randevuları (aktif listeler). */
    public function yaklasanlar(CagriMerkeziErisim $e): array
    {
        $simdi = time();
        $kayitlar = $this->bekleyenler($e, aktifListe: true)
            ->whereRaw("CONCAT(am.tarih,' ',am.saat) <= ?", [date('Y-m-d H:i:s', $simdi + 360)])
            ->whereRaw("CONCAT(am.tarih,' ',am.saat) >= ?", [date('Y-m-d H:i:s', $simdi - 12 * 3600)])
            ->orderByRaw("CONCAT(am.tarih,' ',am.saat) asc")->limit(30)
            ->get(['am.id', 'am.arama_id', 'am.tarih', 'am.saat', 'am.musteri_not', 'u.name']);

        return ['randevular' => $this->ajandaSatirlari($e, $kayitlar, fn ($k, $ts) => [
            'faz' => AramaRandevuDurumu::faz($ts, $simdi), 'not' => mb_substr((string) ($k->musteri_not ?? ''), 0, 120),
        ])];
    }

    /**
     * Ajanda: ?tarih verilirse o günün bekleyen + tamamlanan randevuları; yoksa tüm bekleyenler.
     */
    public function ajanda(CagriMerkeziErisim $e, ?string $tarih): array
    {
        $q = $tarih
            ? $this->taban($e)->where('am.tarih', $tarih)->where(fn ($w) => $w->where('am.durum', 3)->orWhereNotNull('am.ar_tamamlandi_at'))
            : $this->bekleyenler($e);
        $kayitlar = $q->orderByRaw("CONCAT(am.tarih,' ',am.saat) asc")->limit(500)
            ->get(['am.id', 'am.arama_id', 'am.tarih', 'am.saat', 'am.musteri_not', 'am.ar_tamamlandi_at', 'am.ar_gecikti', 'u.name']);
        $simdi = time();

        return ['randevular' => $this->ajandaSatirlari($e, $kayitlar, function ($k, $ts) use ($simdi) {
            $durum = AramaRandevuDurumu::ajanda($ts, $k->ar_tamamlandi_at, (int) $k->ar_gecikti === 1, $simdi);

            return ['durum' => $durum, 'gecti' => $durum === 'gecikti' ? 1 : 0, 'not' => mb_substr((string) ($k->musteri_not ?? ''), 0, 200)];
        })];
    }

    // ───────────────────────────── iç adımlar ─────────────────────────────

    /** Salonun (personelde: kendi listelerinin) zamanlı aranacak kayıtları. */
    private function taban(CagriMerkeziErisim $e): Builder
    {
        $q = DB::table('aranacak_musteriler as am')->join('arama_listesi as al', 'al.id', '=', 'am.arama_id')
            ->leftJoin('users as u', 'u.id', '=', 'am.user_id')
            ->where('al.salon_id', $e->salonId)
            ->whereNotNull('am.tarih')->where('am.tarih', '!=', '')
            ->whereNotNull('am.saat')->where('am.saat', '!=', '');
        if (! $e->yonetici) {
            $q->where('al.personel_id', $e->personelId ?? 0);
        }

        return $q;
    }

    /** Bekleyen (tamamlanmamış) arama randevuları; $aktifListe → arşiv/pasif listeler hariç. */
    private function bekleyenler(CagriMerkeziErisim $e, bool $aktifListe = false): Builder
    {
        $q = $this->taban($e)->where('am.durum', AramaSonucu::TEKRAR_ARANACAK->value)->whereNull('am.ar_tamamlandi_at');
        if ($aktifListe) {
            $q->where(fn ($w) => $w->whereNull('al.durum')->orWhereNotIn('al.durum', [ListeDurumu::ARSIV->value, ListeDurumu::PASIF->value]));
        }

        return $q;
    }

    /** Ortak ajanda/popup satırı + ses özeti; $ek(kayit, ts) ekrana özgü alanları verir. */
    private function ajandaSatirlari(CagriMerkeziErisim $e, $kayitlar, callable $ek): array
    {
        $ses = AramaKaydiSorgusu::sesOzetleri($kayitlar->pluck('id')->all());
        $maske = $e->maskele();

        return $kayitlar->map(function ($k) use ($ses, $maske, $ek) {
            $ts = strtotime($k->tarih.' '.$k->saat);
            $s = $ses[$k->id] ?? null;

            return [
                'id'         => (int) $k->id,
                'arama_id'   => (int) $k->arama_id,
                'ad'         => $maske ? KvkkMaske::adSoyad($k->name) : ((string) $k->name ?: 'Müşteri'),
                'tarih'      => date('d.m.Y', $ts),
                'saat'       => date('H:i', $ts),
                'ses_sayisi' => $s ? (int) $s->adet : 0,
                'ses_son'    => $s && $s->son ? date('d.m.Y H:i', strtotime($s->son)) : '',
            ] + $ek($k, $ts);
        })->all();
    }
}
