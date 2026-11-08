<?php

namespace App\Services\Tahsilat\Mobil;

use App\Domain\Stok\HareketTipi;
use App\Domain\Tahsilat\MobilTahsilatHesabi as Hesap;
use App\Exceptions\BulunamadiException;
use App\Models\AdisyonHizmetler;
use App\Models\Adisyonlar;
use App\Models\AdisyonPaketSeanslar;
use App\Models\AdisyonUrunler;
use App\Services\Stok\StokHareketServisi;
use App\Support\AktiviteLog;
use App\Support\Idempotency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Eski mobil ongorusmesatisyapildi: ekrandaki fiyat satir fiyati olur, indirim yetkisi aranmaz, tahsilat yazilmaz.
 * Musterisi olmayan gorusmede telefonla bulunur ya da olusturulur. Sifre SMS'i commit sonrasi gider, idempotency
 * kaydina yazilmaz. Web OnGorusmeSonucServisi'ne dokunulmaz.
 */
class MobilOngorusmeSatisServisi
{
    public function __construct(private MobilKapsam $kapsam, private MobilTahsilatYazici $yazici, private StokHareketServisi $stok, private MobilSms $sms) {}

    public function salonId(int $id): ?int
    {
        $salon = DB::table('on_gorusmeler')->where('id', $id)->value('salon_id');

        return $salon ? (int) $salon : null;
    }

    public function satisYapildi(int $id, int $yetkiliId, array $g): array
    {
        $og = DB::table('on_gorusmeler')->where('id', $id)->first() ?? throw new BulunamadiException('Ön görüşme bulunamadı');
        $salonId = (int) $og->salon_id;
        $this->kapsam->personelOlmali($salonId, $g['personel_id'] ?? null);

        $sifreSms = null;
        $sonuc = Idempotency::calistir('mobil_ongorusme_satis', (string) ($g['idempotency_key'] ?? ''), $yetkiliId, $salonId,
            function () use ($og, $salonId, $yetkiliId, $g, &$sifreSms) {
                DB::table('on_gorusmeler')->where('id', $og->id)->update(['durum' => 1, 'updated_at' => now()]);

                return $this->yaz($og, $this->musteri($og, $salonId, $yetkiliId, $sifreSms), $salonId, $yetkiliId, $g);
            });

        if ($sifreSms) {
            $this->sms->gonder($salonId, $og->cep_telefon, ' hesabınız oluşturulmuş olup '.$sifreSms.' şifrenizle giriş yapabilirsiniz.');
        }
        if (! $sonuc['replay']) {
            AktiviteLog::yaz($salonId, $yetkiliId, 'ongorusme_satis_yapildi', 'ongorusme', $id, (string) $og->ad_soyad, 'Ongorusme satis yapildi');
        }

        return $sonuc;
    }

    private function musteri(object $og, int $salonId, int $yetkiliId, ?string &$sifreSms): int
    {
        if ($og->user_id !== null) {
            return (int) $og->user_id;
        }
        $id = DB::table('users')->where('cep_telefon', $og->cep_telefon)->value('id');
        if (! $id) {
            $sifreSms = substr(str_shuffle('abcdefghjklmnopqrstuvwxyzABCDEFGHJKLMNOPQRSTUVWXYZ1234567890'), 0, 6);
            $id = DB::table('users')->insertGetId([
                'name' => $og->ad_soyad, 'cep_telefon' => $og->cep_telefon, 'password' => Hash::make($sifreSms), 'cinsiyet' => $og->cinsiyet,
                'meslek' => $og->meslek, 'adres' => $og->adres, 'musteri_tipi' => $og->musteri_tipi, 'il_id' => $og->il_id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        if (! DB::table('musteri_portfoy')->where('salon_id', $salonId)->where('user_id', $id)->exists()) {
            DB::table('musteri_portfoy')->insert([
                'salon_id' => $salonId, 'user_id' => $id, 'aktif' => 1, 'olusturan_personel_id' => $this->yazici->personelId($salonId, $yetkiliId),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return (int) $id;
    }

    private function yaz(object $og, int $musteriId, int $salonId, int $yetkiliId, array $g): array
    {
        $satici = ! empty($g['personel_id']) ? $g['personel_id'] : $og->personel_id;
        $tarih = ! empty($g['baslangic_tarihi']) ? $g['baslangic_tarihi'] : date('Y-m-d');
        $girilenFiyat = $g['fiyat'] ?? null;
        $adisyonId = null;

        if ($og->paket_id !== null) {
            $paket = DB::table('paketler')->where('id', $og->paket_id)->first();
            $hizmetler = DB::table('paket_hizmetler')->where('paket_id', $og->paket_id)->get(['fiyat', 'seans']);
            $adisyonId = (int) Adisyonlar::ac($salonId, $musteriId, $yetkiliId, $tarih)->id;
            $aralik = (int) ($paket->seans_araligi ?? 0);
            if ($aralik <= 0 && ! empty($g['seans_araligi'])) {
                $aralik = (int) $g['seans_araligi'];
            }
            // Sinirsiz pakette seans satiri onceden acilmaz (0); seansli pakette varsayilan bolge basina hak. Hak satisa da yazilir
            // (eski kod yazmiyordu: seans takibinde satis 0 hakla gorunuyordu).
            $seansSayisi = Hesap::paketSeansSayisi($g['seans_sayisi'] ?? null, $hizmetler->map(fn ($h) => (array) $h)->all(),
                $paket->miktar ?? null, (int) ($paket->sinirsiz ?? 0) === 1);
            $adisyonPaketId = $this->yazici->paketEkle($adisyonId, [
                'paket_id' => $og->paket_id, 'fiyat' => Hesap::paketSatisFiyati($hizmetler->sum('fiyat'), $paket->fiyat ?? null, $girilenFiyat),
                'baslangic_tarihi' => $g['baslangic_tarihi'] ?? null, 'seans_araligi' => $aralik, 'personel_id' => $satici,
                'seans_sayisi' => $seansSayisi,
            ]);
            foreach (Hesap::seansTarihleri($g['baslangic_tarihi'] ?? null, $seansSayisi, $aralik) as $seansTarih) {
                $s = new AdisyonPaketSeanslar();
                $s->adisyon_paket_id = $adisyonPaketId;
                $s->seans_tarih = $seansTarih;
                $s->save();
            }
        } elseif ($og->urun_id !== null) {
            $urun = DB::table('urunler')->where('id', $og->urun_id)->first();
            $adisyonId = (int) Adisyonlar::ac($salonId, $musteriId, $yetkiliId, $tarih)->id;
            $u = new AdisyonUrunler();
            $u->islem_tarihi = $tarih;
            $u->adisyon_id = $adisyonId;
            $u->urun_id = $og->urun_id;
            $u->personel_id = $satici;
            $u->adet = $g['urun_adedi'] ?? null;
            $u->fiyat = Hesap::urunSatisFiyati($urun->fiyat, $g['urun_adedi'] ?? 0, $girilenFiyat);
            $u->save();
            $this->stok->kaydet([
                'salon_id' => $urun->salon_id, 'urun_id' => $urun->id, 'depo_id' => $urun->varsayilan_depo_id,
                'miktar' => abs((float) ($g['urun_adedi'] ?? 0)), 'tip' => HareketTipi::Satis, 'referans_tip' => 'ongorusme_donusumu', 'referans_id' => $u->id,
                'birim_satis_fiyati' => $urun->fiyat, 'birim_alis_fiyati' => $urun->alis_fiyati, 'aciklama' => 'On gorusmeden urun satisina donusum', 'kullanici_id' => $yetkiliId,
            ]);
        } elseif ($og->hizmet_id !== null) {
            $hizmet = DB::table('hizmetler')->where('id', $og->hizmet_id)->first();
            $adisyonId = (int) Adisyonlar::ac($salonId, $musteriId, $yetkiliId, $tarih)->id;
            $h = new AdisyonHizmetler();
            $h->adisyon_id = $adisyonId;
            $h->hizmet_id = $og->hizmet_id;
            $h->personel_id = $satici;
            $h->islem_tarihi = $tarih;
            $h->islem_saati = date('H:i:s');
            $h->sure = $hizmet->sure ?? 60;
            $h->fiyat = Hesap::hizmetSatisFiyati($hizmet->fiyat ?? null, $girilenFiyat);
            $seans = Hesap::hizmetSeansSayisi($g['seans_sayisi'] ?? '');
            $h->seans_sayisi = $seans;
            $h->bekleyen_seans = $seans;
            $h->save();
        }

        return ['sonuc' => 'Başarılı', 'adisyon_id' => $adisyonId, 'musteri_id' => $musteriId];
    }
}
