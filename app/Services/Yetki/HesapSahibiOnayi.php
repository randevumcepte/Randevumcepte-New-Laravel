<?php

namespace App\Services\Yetki;

use App\Domain\Tahsilat\TahsilatNet;
use App\Exceptions\GecersizIslemException;
use App\Services\Oturum\MobilKodGirisi;
use App\Support\BildirimKuyrugu;
use App\Support\OnayKodu;
use App\Support\SmsAyari;
use Illuminate\Support\Facades\DB;

/**
 * SMS ayari 23 — silmede HESAP SAHIBI ONAYI. Tahsilat (kasa ve satis ekrani; silme, duzenlemede tutar dusurme,
 * studyo odeme geri alma), satis kalemi (hizmet/urun/paket) ve adisyon silmenin TEK kapisi; web ve mobil ayni
 * uclardan gecer.
 *
 * Kural (eski tahsilatkaldir / urunadisyondansil / paketadisyondansil / adisyon-hizmet-sil): islemi yapan hesap
 * sahibi degilse ve ayar aciksa hesap sahibine kod gider, kod girilmeden silinmez.
 * Eskiden duzeltilenler: kod kaydin kolonuna duz yaziliyor ve hic temizlenmiyordu (tekrar kullanilabiliyordu),
 * kalem silmede bos-olmayan yanlis kod ilk istekte kodu hic uretmiyordu, hesap sahibi kendi silmesinde de kod
 * bekliyordu, kod yalniz SMS'ti. Burada kod OnayKodu ile tek kullanimlik, kayda bagli, 10 dk; mesaj WhatsApp
 * oncelikli ve acil gider (BildirimKuyrugu 'sms', dogrulama=true).
 */
class HesapSahibiOnayi
{
    public const SMS_AYARI = 23;

    private const AMAC = 'hesap_sahibi_silme';

    private const ROL_HESAP_SAHIBI = 1;

    private const KALEM = [
        'hizmet' => ['adisyon_hizmetler', 'hizmetler', 'hizmet_id', 'hizmet_adi', 'hizmetini'],
        'urun'   => ['adisyon_urunler', 'urunler', 'urun_id', 'urun_adi', 'ürününü'],
        'paket'  => ['adisyon_paketler', 'paketler', 'paket_id', 'paket_adi', 'paketini'],
    ];

    public function __construct(private YetkiServisi $yetki) {}

    /** Ayar 23 acik ve islemi yapan hesap sahibi (personel rol 1 ya da model_has_roles rol 1) degil. */
    public function gerekli(int $salonId, int $yetkiliId): bool
    {
        return SmsAyari::personel($salonId, self::SMS_AYARI) && ! $this->yetki->yetkiliHesapSahibiGorunur($yetkiliId, $salonId);
    }

    public function tahsilatSilme(int $salonId, int $yetkiliId, int $tahsilatId, ?string $kod): void
    {
        $this->tahsilatAzaltma($salonId, $yetkiliId, $tahsilatId, null, $kod);
    }

    /**
     * Ortak TAHSILAT AZALTMA kapisi: silme ($yeniBrut null) ve duzenlemede net tutar dusurme (PATCH /tahsilat/{id} eski
     * kaydi iade edip yeniyi yazar — dusuk net fiilen kismi silmedir). Karsilastirma NET uzerinden (TahsilatNet: eski
     * tahsilatlar.tutar netine karsi yeni brut - komisyon); net dusmuyorsa onay gerekmez. Dusurmede kod yeni NET tutara
     * baglanir (onaylanan tutardan baskasi gecmez; komisyonu artirarak ayni kodla daha dusuk net yazilamaz).
     */
    public function tahsilatAzaltma(int $salonId, int $yetkiliId, int $tahsilatId, ?float $yeniBrut, ?string $kod, float $yeniKomisyon = 0.0): void
    {
        $t = DB::table('tahsilatlar')->where('id', $tahsilatId)->first(['odeme_tarihi', 'tutar']);
        if (! TahsilatNet::azaltmaMi((float) ($t->tutar ?? 0), $yeniBrut, $yeniKomisyon) || ! $this->gerekli($salonId, $yetkiliId)) {
            return;
        }
        $tarih = self::tarih($t->odeme_tarihi ?? null).' tarihli '.self::tl($t->tutar ?? 0).' ₺ tutarındaki tahsilatı';
        if ($yeniBrut === null) {
            $this->kodla($salonId, $yetkiliId, ['tahsilat' => $tahsilatId], $kod, $tarih.' silmek');

            return;
        }
        $netKurus = TahsilatNet::kurus($yeniBrut, $yeniKomisyon);
        $this->kodla($salonId, $yetkiliId, ['tahsilat' => $tahsilatId, 'tutar_kurus' => $netKurus], $kod,
            $tarih.' '.self::tl($netKurus / 100).' ₺ tutarına düşürmek');
    }

    /**
     * Stüdyo "Ödeme alınmadı" (PATCH /adisyon/{id}/odeme alindi=false): adisyonun TUM tahsilatlari silinir — ayni
     * azaltma kapisi, kod adisyona baglanir. Silinecek tahsilat yoksa onay gerekmez.
     */
    public function odemeGeriAlma(int $salonId, int $yetkiliId, int $adisyonId, ?string $kod): void
    {
        $o = DB::table('tahsilatlar')->where('adisyon_id', $adisyonId)->selectRaw('COUNT(*) as adet, COALESCE(SUM(tutar), 0) as toplam')->first();
        if ((int) ($o->adet ?? 0) === 0 || ! $this->gerekli($salonId, $yetkiliId)) {
            return;
        }
        $this->kodla($salonId, $yetkiliId, ['adisyon_odeme' => $adisyonId], $kod,
            'adisyon #'.$adisyonId.' için alınan '.self::tl($o->toplam).' ₺ tutarındaki ödemeyi geri almak');
    }

    /** @param  string  $tip  hizmet|urun|paket */
    public function kalemSilme(int $salonId, int $yetkiliId, string $tip, int $kalemId, ?string $kod): void
    {
        if (! isset(self::KALEM[$tip]) || ! $this->gerekli($salonId, $yetkiliId)) {
            return;
        }
        [$tablo, $katalog, $fk, $adKolonu, $ek] = self::KALEM[$tip];
        $k = DB::table($tablo.' as k')->leftJoin('adisyonlar as a', 'a.id', '=', 'k.adisyon_id')
            ->leftJoin($katalog.' as c', 'c.id', '=', 'k.'.$fk)
            ->where('k.id', $kalemId)->first(['k.fiyat', 'a.tarih', 'c.'.$adKolonu.' as ad']);
        $this->kodla($salonId, $yetkiliId, ['kalem' => $tip.':'.$kalemId], $kod,
            self::tarih($k->tarih ?? null).' tarihli satıştan '.self::tl($k->fiyat ?? 0).' ₺ tutarındaki '.trim(($k->ad ?? '').' '.$ek).' silmek');
    }

    public function adisyonSilme(int $salonId, int $yetkiliId, int $adisyonId, ?string $kod): void
    {
        if (! $this->gerekli($salonId, $yetkiliId)) {
            return;
        }
        $tarih = DB::table('adisyonlar')->where('id', $adisyonId)->value('tarih');
        $toplam = 0.0;
        foreach (self::KALEM as [$tablo]) {
            $toplam += (float) DB::table($tablo)->where('adisyon_id', $adisyonId)->sum('fiyat');
        }
        $this->kodla($salonId, $yetkiliId, ['adisyon' => $adisyonId], $kod,
            self::tarih($tarih).' tarihli '.self::tl($toplam).' ₺ tutarındaki satışı (adisyon #'.$adisyonId.') silmek');
    }

    private function kodla(int $salonId, int $yetkiliId, array $baglam, ?string $kod, string $islem): void
    {
        MobilKodGirisi::reddet(); // mobilde kod girisi yok: kod uretilmez, 409
        OnayKodu::zorunlu(self::AMAC, $salonId, $yetkiliId, $baglam, $kod, function (string $yeni) use ($salonId, $yetkiliId, $islem) {
            $sahipTel = $this->sahipTelefonu($salonId);
            if ($sahipTel === '') {
                throw new GecersizIslemException('Hesap sahibinin telefonu kayıtlı değil; onay kodu gönderilemiyor.');
            }
            $yapan = DB::table('salon_personelleri')->where('salon_id', $salonId)->where('yetkili_id', $yetkiliId)->value('personel_adi');
            BildirimKuyrugu::sms($salonId, $yetkiliId, [['to' => $sahipTel, 'message' => self::mesaj((string) $yapan, $islem, $yeni)]], true);
        }, 'Bu silme işlemi hesap sahibinin onayını gerektiriyor. Hesap sahibine gönderilen onay kodunu girin.');
    }

    /**
     * Hesap sahibinin telefonu, iki kaynaktan (yetki kuraliyla ayni: personel rol 1 VEYA model_has_roles rol 1):
     * once salon_personelleri.role_id=1 satirinin yetkilisi, yoksa (personel satiri olmayan sahip) model_has_roles
     * rol 1 yetkilisi; ikisinde de bos olmayan ilk gsm1.
     */
    public function sahipTelefonu(int $salonId): string
    {
        $personelden = DB::table('salon_personelleri as p')->join('isletmeyetkilileri as y', 'y.id', '=', 'p.yetkili_id')
            ->where('p.salon_id', $salonId)->where('p.role_id', self::ROL_HESAP_SAHIBI)->pluck('y.gsm1');
        $rolden = DB::table('model_has_roles as r')->join('isletmeyetkilileri as y', 'y.id', '=', 'r.model_id')
            ->where('r.salon_id', $salonId)->where('r.role_id', self::ROL_HESAP_SAHIBI)->pluck('y.gsm1');

        return self::ilkDolu([...$personelden->all(), ...$rolden->all()]);
    }

    /** @param  array<int,mixed>  $telefonlar */
    public static function ilkDolu(array $telefonlar): string
    {
        foreach ($telefonlar as $t) {
            if (($t = trim((string) $t)) !== '') {
                return $t;
            }
        }

        return '';
    }

    /** Eski metinle ayni: "<personel> <islem> için onayınızı istiyor. Onay Kodu : 1234". */
    public static function mesaj(string $yapan, string $islem, string $kod): string
    {
        return trim($yapan.' '.$islem).' için onayınızı istiyor. Onay Kodu : '.$kod;
    }

    private static function tarih($t): string
    {
        return $t ? date('d.m.Y', strtotime((string) $t)) : '';
    }

    private static function tl($n): string
    {
        return number_format((float) $n, 2, ',', '.');
    }
}
