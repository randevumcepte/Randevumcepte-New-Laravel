<?php

namespace App\Services\Tahsilat\Mobil;

use App\Models\AdisyonPaketler;
use App\Models\Masraf;
use App\Models\MasrafKategorisi;
use App\Models\Personeller;
use App\Models\Tahsilat;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Mobil para yazicilarinin ortak satirlari: tahsilat, komisyon cifti ve adisyon paketi. Eski ApiController'da
 * her ucta kopyaydi; sonuc satirlari ayni, kod tek.
 */
class MobilTahsilatYazici
{
    public function personelId(int $salonId, int $yetkiliId): ?int
    {
        $id = Personeller::where('salon_id', $salonId)->where('yetkili_id', $yetkiliId)->value('id');

        return $id ? (int) $id : null;
    }

    /** @param  array{salon_id:int, yetkili_id:int, adisyon_id:mixed, musteri_id:mixed, tutar:float, komisyon?:?float, odeme_tarihi:mixed, odeme_yontemi:mixed, notlar:mixed}  $s  komisyon null ise kolona dokunulmaz (eski harici ve hizli satis gibi). */
    public function tahsilatYaz(array $s): Tahsilat
    {
        $t = new Tahsilat();
        $t->adisyon_id = $s['adisyon_id'] ?: null;
        $t->tutar = $s['tutar'];
        if (($s['komisyon'] ?? null) !== null && Schema::hasColumn('tahsilatlar', 'komisyon_tutari')) {
            $t->komisyon_tutari = $s['komisyon'];
        }
        $t->user_id = $s['musteri_id'];
        $t->odeme_tarihi = $s['odeme_tarihi'];
        $t->olusturan_id = $this->personelId($s['salon_id'], $s['yetkili_id']);
        $t->salon_id = $s['salon_id'];
        $t->yapilan_odeme = $s['tutar'];
        $t->odeme_yontemi_id = $s['odeme_yontemi'];
        $t->notlar = $s['notlar'];
        $t->save();

        return $t;
    }

    /** Komisyon > 0: ek tahsilat (gelir, para_girisi=1) + Banka Odemeleri masrafi (gider). Net kasa etkisi 0. */
    public function komisyonYaz(Tahsilat $ana, array $s): void
    {
        if ($s['komisyon'] <= 0) {
            return;
        }
        try {
            $personelId = $this->personelId($s['salon_id'], $s['yetkili_id']);

            $k = new Tahsilat();
            $k->adisyon_id = $s['adisyon_id'] ?: null;
            $k->tutar = $s['komisyon'];
            $k->user_id = $s['musteri_id'];
            $k->odeme_tarihi = $s['odeme_tarihi'];
            $k->olusturan_id = $personelId;
            $k->salon_id = $s['salon_id'];
            $k->yapilan_odeme = $s['komisyon'];
            $k->odeme_yontemi_id = $s['odeme_yontemi'];
            $k->para_girisi = 1;
            $k->notlar = 'Komisyon Geliri (tahsilat #'.$ana->id.')';
            $k->save();

            $kategori = MasrafKategorisi::firstOrCreate(['kategori' => 'Banka Ödemeleri']);
            $m = new Masraf();
            $m->salon_id = $s['salon_id'];
            if (Schema::hasColumn('masraflar', 'adisyon_id')) {
                $m->adisyon_id = $s['adisyon_id'] ?: null;
            }
            $m->harcayan_id = $personelId;
            $m->masraf_kategori_id = $kategori->id;
            $m->tarih = $s['odeme_tarihi'];
            $m->tutar = $s['komisyon'];
            $m->odeme_yontemi_id = $s['odeme_yontemi'];
            $m->aciklama = 'Komisyon Tutarı';
            $m->save();
        } catch (\Throwable $e) {
            Log::error('komisyon kayitlari (mobil) yazilamadi', ['err' => $e->getMessage(), 'tahsilat_id' => $ana->id]);
        }
    }

    /** Eski adisyona_paket_ekle. */
    public function paketEkle(int $adisyonId, array $k): int
    {
        $p = new AdisyonPaketler();
        $p->adisyon_id = $adisyonId;
        $p->paket_id = $k['paket_id'];
        $p->fiyat = $k['fiyat'];
        $p->baslangic_tarihi = $k['baslangic_tarihi'] ?? null;
        $p->seans_araligi = $k['seans_araligi'] ?? null;
        $p->personel_id = $k['personel_id'] ?? null;
        $p->senet_id = $k['senet_id'] ?? null;
        $p->taksitli_tahsilat_id = null;
        if (! empty($k['seans_sayisi'])) {
            $p->seans_sayisi = (int) $k['seans_sayisi'];
            $p->bekleyen_seans = (int) $k['seans_sayisi'];
            $p->kullanilan_seans = 0;
            $p->kullanilmayan_seans = 0;
        }
        $p->save();

        return (int) $p->id;
    }
}
