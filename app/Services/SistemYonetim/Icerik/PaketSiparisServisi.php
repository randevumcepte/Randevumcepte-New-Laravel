<?php

namespace App\Services\SistemYonetim\Icerik;

use App\Domain\SistemYonetim\SiparisFaturaDurumu;
use App\Domain\SistemYonetim\SiparisOdemeDurumu;
use App\Exceptions\BulunamadiException;
use App\Exceptions\GecersizIslemException;
use App\Models\SistemYoneticisi;
use App\Services\SistemYonetim\Denetim;
use App\Support\SorguSayfasi;
use App\Support\ListeIstegi;
use Illuminate\Support\Facades\DB;

/** Duyuru paketi siparisleri: odenenler VoiceTelekom panelinden elle yuklenip burada isaretlenir. */
class PaketSiparisServisi
{
    private const TABLO = 'sms_paket_siparisleri';

    private const YUKLENMEDI = 0;

    private const YUKLENDI = 1;

    public const SIRALAMA = ['tarih' => 's.id', 'salon' => 'l.salon_adi', 'sms_adet' => 's.sms_adet', 'tutar' => 's.tutar', 'odeme' => 's.durum', 'yukleme' => 's.yukleme_durumu', 'fatura' => 's.fatura_durumu'];

    public function listele(array $liste): array
    {
        $q = DB::table(self::TABLO.' as s')->leftJoin('salonlar as l', 'l.id', '=', 's.salon_id')
            ->select('s.id', 's.salon_id', 's.sms_adet', 's.tutar', 's.durum', 's.yukleme_durumu', 's.yukleme_tarihi', 's.fatura_durumu', 's.created_at', 'l.salon_adi');
        if ($like = ListeIstegi::like($liste['ara'] ?? null)) {
            $q->where(fn ($w) => $w->where('l.salon_adi', 'like', $like)->orWhere('s.merchant_oid', 'like', $like)->orWhere('s.sms_adet', 'like', $like));
        }

        return SorguSayfasi::uret($q, $liste, [], self::SIRALAMA, fn ($s) => $this->satir($s), 's.id')
            + ['bekleyen' => $this->bekleyenler()->count()];
    }

    /** Yalniz odenmis ve henuz yuklenmemis siparis; koşul sorguda, cift tik ikinci kez yazmaz. */
    public function yuklendiIsaretle(int $id, SistemYoneticisi $yonetici): void
    {
        $siparis = DB::table(self::TABLO)->where('id', $id)->first(['id', 'salon_id', 'sms_adet']);
        if (! $siparis) {
            throw new BulunamadiException('Sipariş bulunamadı');
        }

        $yazilan = $this->bekleyenler()->where('id', $id)->update([
            'yukleme_durumu' => self::YUKLENDI,
            'yukleme_tarihi' => now(),
            'yukleyen'       => $yonetici->name,
            'updated_at'     => now(),
        ]);
        if (! $yazilan) {
            throw new GecersizIslemException('Yalnız ödenmiş ve yüklenmemiş sipariş işaretlenebilir.');
        }

        Denetim::yaz('duyuru_paketi_yuklendi', 'sms_paket_siparis', $id, $siparis->sms_adet.' SMS', 'Salon #'.$siparis->salon_id);
    }

    private function bekleyenler()
    {
        return DB::table(self::TABLO)->where('durum', SiparisOdemeDurumu::Odendi->value)->where('yukleme_durumu', self::YUKLENMEDI);
    }

    private function satir(object $s): array
    {
        $odendi = (int) $s->durum === SiparisOdemeDurumu::Odendi->value;
        $yuklendi = (int) $s->yukleme_durumu === self::YUKLENDI;

        return [
            'id'             => (int) $s->id,
            'tarih'          => $s->created_at,
            'salon_adi'      => $s->salon_adi ?: 'Salon #'.$s->salon_id,
            'sms_adet'       => (int) $s->sms_adet,
            'tutar'          => (float) $s->tutar,
            'odeme'          => SiparisOdemeDurumu::rozet((int) $s->durum),
            'fatura'         => SiparisFaturaDurumu::rozet((int) $s->fatura_durumu),
            'yukleme'        => $yuklendi ? ['etiket' => 'Yüklendi', 'renk' => 'success'] : ($odendi ? ['etiket' => 'Bekliyor', 'renk' => 'warning'] : null),
            'yukleme_tarihi' => $s->yukleme_tarihi,
            'yuklenebilir'   => $odendi && ! $yuklendi,
        ];
    }
}
