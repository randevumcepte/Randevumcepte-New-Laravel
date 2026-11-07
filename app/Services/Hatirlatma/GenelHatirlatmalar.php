<?php

namespace App\Services\Hatirlatma;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Salon geneli hatırlatma kartları: her tetikleyici ya tek kart ya null döner. Tutar taşımaz, yalnız sayı.
 * Bir tetikleyicinin hatası diğerlerini düşürmez. Doğum günü kartı yok: istemcide ayrı akışı var.
 */
class GenelHatirlatmalar
{
    private const TETIKLEYICILER = ['saatiGecenRandevular', 'yeniMusteriler', 'maasOdemeleri', 'bekleyenRandevular', 'acikAdisyonlar', 'gecikenAlacaklar'];

    private const AYLAR = [1 => 'Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];

    private const ACIK_ADISYON_TAVANI = 200;

    /** Maaş kartı ayın bu gününden önce çıkmaz. */
    private const MAAS_GUNU = 5;

    public function topla(int $salonId): array
    {
        $liste = [];
        foreach (self::TETIKLEYICILER as $tetikleyici) {
            try {
                if ($kart = $this->{$tetikleyici}($salonId)) {
                    $liste[] = $kart;
                }
            } catch (\Throwable $e) {
                Log::warning('Salon hatırlatma ['.$tetikleyici.']: '.$e->getMessage());
            }
        }

        return $liste;
    }

    private function saatiGecenRandevular(int $salonId): ?array
    {
        $sayi = DB::table('randevular')->where('salon_id', $salonId)->where('durum', 1)->whereNull('randevuya_geldi')
            ->where('tarih', date('Y-m-d'))->whereRaw("CONCAT(tarih,' ',saat) <= ?", [date('Y-m-d H:i:s', strtotime('-15 minutes'))])
            ->count();

        return $sayi > 0 ? self::kart('geldi_gelmedi', 3, 'kirmizi-uyari', '🚨', 'Saati Geçen Randevular',
            $sayi.' randevunun saati geçti, durumu hâlâ işaretlenmemiş.',
            'Geldiyse işlem yapın, gelmediyse veya iptalse lütfen iptal edin — gün sonu raporunuz doğru çıksın.', 'Randevulara Git', $sayi) : null;
    }

    private function yeniMusteriler(int $salonId): ?array
    {
        $sinir = date('Y-m-d H:i:s', strtotime('-24 hours'));
        $sayi = DB::table('musteri_portfoy')->where('salon_id', $salonId)->where('created_at', '>=', $sinir)->count();
        if ($sayi <= 0) {
            return null;
        }
        $isim = DB::table('musteri_portfoy')->join('users', 'users.id', '=', 'musteri_portfoy.user_id')
            ->where('musteri_portfoy.salon_id', $salonId)->where('musteri_portfoy.created_at', '>=', $sinir)
            ->orderByDesc('musteri_portfoy.created_at')->value('users.name') ?: 'Yeni misafir';

        return self::kart('yeni_musteri', 2, 'konfeti-parti', '🎉', 'Yeni Müşteri Geldi! 🎊',
            $sayi === 1 ? "Son 24 saatte $isim portföyünüze eklendi." : "Son 24 saatte $sayi yeni müşteri portföyünüze eklendi.",
            'Tıkla → "Yeni Eklenen Müşteriler" listesini aç (en yeniler üstte), hoş geldin SMS\'i at.', 'Yeni Müşterileri Gör', $sayi);
    }

    /** Önceki ayın maaş ödemesi kaydı olmayan personel: yalnız ad ve sayı, tutar dönmez. */
    private function maasOdemeleri(int $salonId): ?array
    {
        if ((int) date('d') < self::MAAS_GUNU) {
            return null;
        }
        $oncekiAy = strtotime('first day of last month');
        $donem = date('Y-m', $oncekiAy);
        $ay = self::AYLAR[(int) date('m', $oncekiAy)];
        $odenmis = DB::table('personel_maas_odemeleri')->where('salon_id', $salonId)->where('donem', $donem)->pluck('personel_id');
        $adlar = DB::table('salon_personelleri')->where('salon_id', $salonId)->where('aktif', 1)
            ->where(fn ($q) => $q->where('arsivli', false)->orWhereNull('arsivli'))
            ->where('maas', '>', 0)->whereNotIn('id', $odenmis)->pluck('personel_adi');
        $sayi = $adlar->count();
        if ($sayi <= 0) {
            return null;
        }
        $dolu = $adlar->filter()->values();
        $adListesi = $dolu->take(4)->implode(', ').($dolu->count() > 4 ? ' +'.($dolu->count() - 4) : '');

        return self::kart('personel_odeme', 3, 'altin-yagmur', '💸', $ay.' '.date('Y', $oncekiAy).' Maaş Ödemeleri',
            $sayi.' personelin '.$ay.' ayı maaş ödemesi hâlâ kayıtlı değil.', 'Kayıtlı değil: '.$adListesi, 'Maaş & Prim', $sayi, 'personel_odeme_'.$donem);
    }

    private function bekleyenRandevular(int $salonId): ?array
    {
        $sayi = DB::table('randevular')->where('salon_id', $salonId)->where('durum', 0)->where('tarih', date('Y-m-d'))->count();

        return $sayi > 0 ? self::kart('bekleyen_randevu', 2, 'mavi-cinglir', '🔔', 'Onay Bekleyen Randevu',
            'Bugün '.$sayi.' randevu hâlâ onayınızı bekliyor.', 'Cevapsız bırakırsan müşteri başka kapı çalar, hadi onayla!', 'Randevular', $sayi) : null;
    }

    private function acikAdisyonlar(int $salonId): ?array
    {
        $idler = self::silinmemis(DB::table('adisyonlar'), 'adisyonlar')->where('salon_id', $salonId)
            ->where('created_at', '<', date('Y-m-d 00:00:00', strtotime('-1 day')))->limit(self::ACIK_ADISYON_TAVANI)->pluck('id')->all();
        if (! $idler) {
            return null;
        }
        $kalan = array_fill_keys($idler, 0.0);
        foreach (['adisyon_hizmetler', 'adisyon_urunler', 'adisyon_paketler'] as $tablo) {
            foreach (DB::table($tablo)->whereIn('adisyon_id', $idler)->groupBy('adisyon_id')
                ->select('adisyon_id', DB::raw('SUM(IFNULL(fiyat,0) - IFNULL(indirim_tutari,0)) as t'))->pluck('t', 'adisyon_id') as $id => $tutar) {
                $kalan[$id] += (float) $tutar;
            }
        }
        foreach (DB::table('tahsilatlar')->whereIn('adisyon_id', $idler)->groupBy('adisyon_id')
            ->select('adisyon_id', DB::raw('SUM(IFNULL(tutar,0)) as t'))->pluck('t', 'adisyon_id') as $id => $tutar) {
            $kalan[$id] -= (float) $tutar;
        }
        $sayi = count(array_filter($kalan, fn ($t) => $t > 0.01));

        return $sayi > 0 ? self::kart('acik_adisyon', 2, 'turuncu-kasa', '🧾', 'Açık Adisyon Birikti!',
            $sayi.' adisyon dünden kalmış, hâlâ tahsilat bekliyor.', 'Açık adisyon = sahipsiz para. Tahsilatı kapatmayı unutma!', 'Adisyonlara Git', $sayi) : null;
    }

    private function gecikenAlacaklar(int $salonId): ?array
    {
        $sayi = self::silinmemis(DB::table('alacaklar'), 'alacaklar')->where('salon_id', $salonId)
            ->whereNotNull('planlanan_odeme_tarihi')->where('planlanan_odeme_tarihi', '<', date('Y-m-d'))->count();

        return $sayi > 0 ? self::kart('geciken_alacak', 2, 'kirmizi-uyari', '⏰', 'Geciken Alacaklar',
            $sayi.' alacağın planlanan ödeme tarihi geçti.', 'Müşteriyi nazikçe ara, takip etmeyen unutulur!', 'Alacaklara Git', $sayi) : null;
    }

    /** silindi kolonu her kurulumda yok. */
    private static function silinmemis($sorgu, string $tablo)
    {
        return Schema::hasColumn($tablo, 'silindi') ? $sorgu->whereRaw('IFNULL(silindi,0) = 0') : $sorgu;
    }

    private static function kart(string $tip, int $oncelik, string $tema, string $emoji, string $baslik, string $mesaj, string $altMesaj, string $cta, int $sayac, ?string $id = null): array
    {
        return ['id' => $id ?? $tip, 'tip' => $tip, 'oncelik' => $oncelik, 'tema' => $tema, 'emoji' => $emoji,
            'baslik' => $baslik, 'mesaj' => $mesaj, 'altMesaj' => $altMesaj, 'cta_text' => $cta, 'sayac' => $sayac];
    }
}
