<?php

namespace App\Services\Kasa;

use App\Exceptions\BulunamadiException;
use App\Exceptions\GecersizIslemException;
use App\Models\Masraf;
use App\Models\Tahsilat;
use App\Services\Tahsilat\TahsilatSilServisi;
use App\Support\AktiviteLog;
use App\Support\BildirimKuyrugu;
use App\Support\OnayKodu;
use Illuminate\Support\Facades\DB;

/**
 * Kasa YAZMA — Para Ekle (kasaya_para_ekle), Para Cek + onay kodu (kasadanparaal /
 * paracekmeonaykodugonder) ve kasadaki para girisi / bagimsiz tahsilat silme
 * (tahsilatkaldir'in kasa dali). Kill-switch ve yetki controller'da.
 *
 * Eski davranis korunur: para girisi = tahsilatlar.para_girisi=1, para cekme = masraf;
 * SMS ayarlari 20 (eklendi) / 19 (cekildi) / 23 (silmede hesap sahibi onayi).
 * Duzeltilenler: SMS tum yetkililere gider (eskiden donguden yalniz SONUNCUSU), onay kodu
 * secili SUBENIN yetkililerine gider ve tek kullanimlik / tutara bagli (OnayKodu), kurus
 * kaybolmaz (sayi JSON'da), para cekme aciklamasi listede gorunen 'aciklama'ya yazilir,
 * Para Ekle/Cek aktivite loguna duser, satis tahsilati kasadan silinemez (Satis ekrani).
 */
class KasaYazServisi
{
    private const SMS_PARA_EKLENDI = 20;

    private const SMS_PARA_CEKILDI = 19;

    private const SMS_SILME_ONAYI = 23;

    public function __construct(private TahsilatSilServisi $tahsilatSil) {}

    /** @param array{tarih:string, tutar:float, odeme_yontemi_id:int, personel_id:?int, aciklama:?string} $v */
    public function paraGiris(int $salonId, int $yetkiliId, array $v): array
    {
        return DB::transaction(function () use ($salonId, $yetkiliId, $v) {
            $t = Tahsilat::create([
                'salon_id'         => $salonId,
                'olusturan_id'     => $v['personel_id'] ?? null,
                'odeme_tarihi'     => self::tarihSaat($v['tarih']),
                'tutar'            => round((float) $v['tutar'], 2),
                'odeme_yontemi_id' => $v['odeme_yontemi_id'],
                'notlar'           => $v['aciklama'] ?? null,
                'para_girisi'      => 1,
            ]);
            $ad = $this->personelAdi($v['personel_id'] ?? null);
            AktiviteLog::yaz($salonId, $yetkiliId, 'kasa_para_giris', 'tahsilat', (int) $t->id,
                self::tl($t->tutar).' ₺', 'Kasaya para eklendi', ['personel_id' => $v['personel_id'] ?? null]);
            if ($this->smsAyari($salonId, self::SMS_PARA_EKLENDI)) {
                $this->yetkililereSms($salonId, $yetkiliId, 'Kasanıza '.($ad ?: 'kasa').' tarafından '.self::tl($t->tutar).' TL eklenmiştir.');
            }

            return ['tahsilat_id' => (int) $t->id];
        });
    }

    /** Para cekme onay kodu — secili subenin yetkililerine SMS; kod tutara baglidir. */
    public function paraCikisKodu(int $salonId, int $yetkiliId, float $tutar): array
    {
        $kod = OnayKodu::uret('kasa_cikis', $salonId, $yetkiliId, ['tutar' => round($tutar, 2)]);
        $adet = $this->yetkililereSms($salonId, $yetkiliId, 'Kasadan '.self::tl($tutar).' TL çekilmesi için onay kodu: '.$kod, true);
        if ($adet === 0) {
            throw new GecersizIslemException('Onay kodu gönderilecek yetkili telefonu bulunamadı');
        }

        return ['gonderilen' => $adet];
    }

    /** @param array{tarih:string, tutar:float, odeme_yontemi_id:int, personel_id:?int, aciklama:?string, onay_kodu:string} $v */
    public function paraCikis(int $salonId, int $yetkiliId, array $v): array
    {
        if (! OnayKodu::dogrula('kasa_cikis', $salonId, $yetkiliId, $v['onay_kodu'] ?? null, ['tutar' => round((float) $v['tutar'], 2)])) {
            throw new GecersizIslemException('Doğrulama kodu hatalı veya süresi doldu, lütfen yeni kod isteyin.');
        }

        return DB::transaction(function () use ($salonId, $yetkiliId, $v) {
            $m = Masraf::create([
                'salon_id'         => $salonId,
                'harcayan_id'      => $v['personel_id'] ?? null,
                'tarih'            => self::tarihSaat($v['tarih']),
                'tutar'            => round((float) $v['tutar'], 2),
                'odeme_yontemi_id' => $v['odeme_yontemi_id'],
                'aciklama'         => $v['aciklama'] ?? null,
            ]);
            $ad = $this->personelAdi($v['personel_id'] ?? null);
            AktiviteLog::yaz($salonId, $yetkiliId, 'kasa_para_cikis', 'masraf', (int) $m->id,
                self::tl($m->tutar).' ₺', 'Kasadan para çekildi', ['personel_id' => $v['personel_id'] ?? null]);
            if ($this->smsAyari($salonId, self::SMS_PARA_CEKILDI)) {
                $this->yetkililereSms($salonId, $yetkiliId, 'Kasanızdan '.($ad ?: 'kasa').' tarafından '.self::tl($m->tutar).' TL alınmıştır.');
            }

            return ['masraf_id' => (int) $m->id];
        });
    }

    /**
     * Kasadaki para girisi / bagimsiz tahsilati siler. Hesap sahibi olmayan personel ve
     * SMS ayari 23 aciksa hesap sahibinin onay kodu gerekir: kod yoksa uretilip gonderilir
     * ve {dogrulama_gerekli:true} doner (eski akisla ayni iki adim).
     */
    public function gelirSil(int $salonId, int $yetkiliId, int $tahsilatId, ?string $onayKodu): array
    {
        $t = Tahsilat::where('id', $tahsilatId)->where('salon_id', $salonId)->first();
        if (! $t) {
            throw new BulunamadiException('Kayıt bulunamadı');
        }
        $bagsiz = (bool) DB::table('tahsilatlar as t')->where('t.id', $tahsilatId)->value(DB::raw(KasaTutar::bagsizSql()));
        if (! $t->para_girisi && ! (! $t->adisyon_id || $bagsiz)) {
            throw new GecersizIslemException('Satış tahsilatı kasadan silinemez; Satış Takibi ekranından iade edin.');
        }

        if ($this->hesapSahibiOnayiGerekli($salonId, $yetkiliId)) {
            $baglam = ['tahsilat_id' => $tahsilatId];
            if ($onayKodu === null || $onayKodu === '') {
                $kod = OnayKodu::uret('kasa_gelir_sil', $salonId, $yetkiliId, $baglam);
                $sahip = DB::table('salon_personelleri as p')->join('isletmeyetkilileri as y', 'y.id', '=', 'p.yetkili_id')
                    ->where('p.salon_id', $salonId)->where('p.role_id', 1)->value('y.gsm1');
                if (! trim((string) $sahip)) {
                    throw new GecersizIslemException('Hesap sahibinin telefonu kayıtlı değil; onay kodu gönderilemiyor.');
                }
                $islemYapan = DB::table('salon_personelleri')->where('salon_id', $salonId)->where('yetkili_id', $yetkiliId)->value('personel_adi');
                BildirimKuyrugu::sms($salonId, $yetkiliId, [['to' => $sahip, 'message' => trim($islemYapan.' '.date('d.m.Y', strtotime((string) $t->odeme_tarihi))
                    .' tarihli '.self::tl($t->tutar).' ₺ tutarındaki tahsilatı silmek için onayınızı istiyor. Onay Kodu : '.$kod)]], true);

                return ['silindi' => false, 'dogrulama_gerekli' => true];
            }
            if (! OnayKodu::dogrula('kasa_gelir_sil', $salonId, $yetkiliId, $onayKodu, $baglam)) {
                throw new GecersizIslemException('Onay kodu hatalı veya süresi doldu.');
            }
        }

        AktiviteLog::yaz($salonId, $yetkiliId, 'tahsilat_sil', 'tahsilat', $tahsilatId, self::tl($t->tutar).' ₺',
            $t->para_girisi ? 'Kasa para girişi silindi' : 'Bağımsız tahsilat silindi', ['kaynak_ekran' => 'kasa']);
        $sonuc = $this->tahsilatSil->sil($tahsilatId, ['yetkili_id' => $yetkiliId, 'salon_id' => $salonId]);

        return ['silindi' => true, 'dogrulama_gerekli' => false] + $sonuc;
    }

    /** Eski kural: islem yapan hesap sahibi (role_id 1) degilse ve ayar 23 aciksa. */
    private function hesapSahibiOnayiGerekli(int $salonId, int $yetkiliId): bool
    {
        $rol = DB::table('salon_personelleri')->where('salon_id', $salonId)->where('yetkili_id', $yetkiliId)->value('role_id');

        return $rol !== null && (int) $rol > 1 && $this->smsAyari($salonId, self::SMS_SILME_ONAYI);
    }

    private function smsAyari(int $salonId, int $ayarId): bool
    {
        return (bool) DB::table('salon_sms_ayarlari')->where('salon_id', $salonId)->where('ayar_id', $ayarId)->value('personel');
    }

    /** Salonun yetkili personeline (rol 1/2/3) SMS — HER birine (eski donguden yalniz sonuncusu). */
    private function yetkililereSms(int $salonId, int $yetkiliId, string $mesaj, bool $dogrulama = false): int
    {
        $telefonlar = DB::table('salon_personelleri as p')
            ->join('model_has_roles as r', fn ($j) => $j->on('r.model_id', '=', 'p.yetkili_id')->where('r.salon_id', $salonId))
            ->where('p.salon_id', $salonId)->whereIn('p.role_id', [1, 2, 3])
            ->whereNotNull('p.cep_telefon')->distinct()->pluck('p.cep_telefon');

        return BildirimKuyrugu::sms($salonId, $yetkiliId, $telefonlar->map(fn ($tel) => ['to' => $tel, 'message' => $mesaj])->all(), $dogrulama);
    }

    private function personelAdi(?int $personelId): ?string
    {
        return $personelId ? DB::table('salon_personelleri')->where('id', $personelId)->value('personel_adi') : null;
    }

    /** Bugun girilen kayitta simdiki saat (gun sonu saati anlamli olsun), gecmis gunde 12:00. */
    private static function tarihSaat(string $tarih): string
    {
        return $tarih === date('Y-m-d') ? date('Y-m-d H:i:s') : $tarih.' 12:00:00';
    }

    private static function tl($n): string
    {
        return number_format((float) $n, 2, ',', '.');
    }
}
