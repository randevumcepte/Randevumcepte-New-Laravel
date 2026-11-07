<?php

namespace App\Services\Personel;

use App\Domain\Personel\Telefon;
use App\Exceptions\BulunamadiException;
use App\Exceptions\CakismaException;
use App\Models\IsletmeYetkilileri;
use App\Models\Personeller;
use App\Models\PersonelPrimOrani;
use App\Support\AktiviteLog;
use App\Support\BildirimKuyrugu;
use App\Support\HaftalikSaatDeposu;
use App\Support\TakvimSirasi;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Personel YAZMA servisi. Controller kill-switch (config personel.yazma_aktif) ile korur;
 * is mantigi burada. Eski ApiController personel yazma uclarindan portlandi.
 *
 * Yan etkiler v2'de URETILMEZ; ayni DB islemi icinde v2_bildirim_kuyrugu'na yazilir ve
 * eski monolitin `v2:bildirim-isle` komutu MEVCUT kodla gonderir (BildirimKuyrugu):
 *   - personel_sifre : sifre uret + kaydet + WhatsApp/SMS (yeni hesap dahil)
 *   - personel_pasif : baska aktif salonu yoksa token revoke + force_logout push
 * Islemler eski Audit ile ayni tabloya (AktiviteLog, ayni action adlari) loglanir.
 */
class PersonelYazServisi
{
    /** Aktif/pasif. Pasifte oturum sonlandirma eski koda kuyruktan devredilir. */
    public function durum(int $personelId, bool $aktif, ?int $yapanId = null): array
    {
        return DB::transaction(function () use ($personelId, $aktif, $yapanId) {
            $p = $this->bul($personelId);
            $p->aktif = $aktif;
            $p->save();

            if (! $aktif) {
                BildirimKuyrugu::yaz((int) $p->salon_id, 'personel_pasif', null, $yapanId, ['personel_id' => (int) $p->id]);
            }
            AktiviteLog::yaz((int) $p->salon_id, $yapanId, $aktif ? 'personel_aktif' : 'personel_pasif', 'personel',
                (int) $p->id, $p->personel_adi, $aktif ? 'Personel aktife alındı' : 'Personel pasife alındı');

            return ['id' => (int) $p->id, 'aktif' => (int) $p->aktif];
        });
    }

    /** Takvimde gorunurlugu tersine cevir. */
    public function takvimToggle(int $personelId, ?int $yapanId = null): array
    {
        $p = $this->bul($personelId);
        $p->takvimde_gorunsun = ! ((bool) $p->takvimde_gorunsun);
        $p->save();

        AktiviteLog::yaz((int) $p->salon_id, $yapanId, 'personel_takvim_toggle', 'personel', (int) $p->id, $p->personel_adi,
            'Personel takvimde gorunurlugu degistirildi', ['takvimde_gorunsun' => (int) $p->takvimde_gorunsun]);

        return ['takvimde_gorunsun' => (int) $p->takvimde_gorunsun];
    }

    /**
     * Yeni giris sifresi gonder: uretim + kayit + WhatsApp/SMS eski koddadir
     * (personelSifreUretVeGonder); burada yalnizca kuyruga yazilir. Log'u o metod yazar.
     */
    public function sifreGonder(int $personelId, ?int $yapanId = null): array
    {
        $p = $this->bul($personelId);
        if (! $p->yetkili_id) {
            throw new CakismaException('Personelin giriş hesabı yok; önce kaydedin.');
        }
        BildirimKuyrugu::yaz((int) $p->salon_id, 'personel_sifre', null, $yapanId, ['personel_id' => (int) $p->id]);

        return ['kuyruga_alindi' => true];
    }

    /** Aktif personeller arasinda sirayi delta (-1 yukari / +1 asagi) kaydir. */
    public function siralama(int $salonId, int $personelId, int $delta, ?int $yapanId = null): array
    {
        if ($delta !== 1 && $delta !== -1) {
            return ['sonuc' => 'error', 'mesaj' => 'delta 1 veya -1 olmalı'];
        }

        $sonuc = TakvimSirasi::kaydir('salon_personelleri', ['salon_id' => $salonId, 'aktif' => 1], $personelId, $delta);
        if ($sonuc !== 'ok') {
            return ['sonuc' => $sonuc];
        }

        AktiviteLog::yaz($salonId, $yapanId, 'personel_sirala', 'personel', $personelId, null,
            'Personel takvim sirasi degistirildi', ['delta' => $delta]);

        return ['sonuc' => 'ok'];
    }

    /** Haftalik calisma + mola saatlerini yeniden yaz (v2 temiz dizi sozlesmesi). */
    public function saatKaydet(int $personelId, array $calisma, array $mola): array
    {
        DB::transaction(fn () => $this->saatleriYaz($personelId, $calisma, $mola));

        return ['sonuc' => 'ok'];
    }

    // ===================== Faz 2b — capraz-kesit =====================

    /**
     * Personel ekle (personel_id yok) veya duzenle (var). Login (IsletmeYetkilileri)
     * ensure + rol senkron (salon_personelleri.role_id + model_has_roles) + opsiyonel saatler.
     *
     * Yeni hesapta gercek sifre v2'de uretilip DONDURULMEZ: hesap guclu rastgele bir
     * yer tutucu ile acilir, sifre eski koddaki personelSifreUretVeGonder ile
     * (kuyruk: personel_sifre, yeni_hesap=1) uretilip personele WhatsApp/SMS gider.
     *
     * @return array{personel_id:int, yeni_mi:bool, yeni_hesap:bool, sifre_gonderiliyor:bool}
     */
    public function ekleDuzenle(array $g): array
    {
        $salonId = (int) $g['salon_id'];
        $personelId = ! empty($g['personel_id']) ? (int) $g['personel_id'] : null;
        $tel = Telefon::normalize($g['cep_telefon'] ?? '');
        $duzenleme = $personelId !== null;
        $yapanId = isset($g['yapan_id']) ? (int) $g['yapan_id'] : null;

        return DB::transaction(function () use ($g, $salonId, $personelId, $tel, $duzenleme, $yapanId) {
            $yetkili = null;
            $yeniHesap = false;
            $personel = null;

            if ($duzenleme) {
                $personel = Personeller::where('id', $personelId)->where('salon_id', $salonId)->first();
                if (! $personel) {
                    throw new BulunamadiException('Güncellenecek personel bulunamadı');
                }
                // Duzenlemede yetkili DAIMA personelin yetkili_id'si uzerinden (telefonla arama bug'i).
                if ($personel->yetkili_id) {
                    $yetkili = IsletmeYetkilileri::find($personel->yetkili_id);
                }
            } elseif ($tel !== ''
                && Personeller::where('cep_telefon', $tel)->where('salon_id', $salonId)->exists()
                && IsletmeYetkilileri::where('gsm1', $tel)->exists()) {
                // Ekleme: ayni salonda ayni telefon + ayni gsm login varsa duplicate.
                throw new CakismaException('Bu cep telefonu ile zaten bir kayıt mevcut.');
            }

            // Yetkili hala yoksa ve telefon doluysa gsm ile ara (bos gsm'li kayitlari yakalamasin).
            if (! $yetkili && $tel !== '') {
                $yetkili = IsletmeYetkilileri::where('gsm1', $tel)->first();
            }
            if (! $yetkili) {
                $yetkili = new IsletmeYetkilileri();
                $yeniHesap = true;
            }

            $yetkili->unvan = $g['unvan'] ?? null;
            $yetkili->cinsiyet = $g['cinsiyet'] ?? null;
            $yetkili->name = $g['personel_adi'] ?? null;
            if ($tel !== '') {
                $yetkili->gsm1 = $tel;
            } elseif ($yeniHesap) {
                $yetkili->gsm1 = '';
            }
            if ($yeniHesap) {
                $yetkili->profil_resim = '/public/isletmeyonetim_assets/img/avatar.png';
                // Yer tutucu (kimse bilmez); gercek sifre kuyruktan uretilip gonderilir.
                $yetkili->password = Hash::make(Str::random(40));
                $yetkili->aktif = true;
            }
            $yetkili->save();

            if (! $personel) {
                $personel = new Personeller();
                $personel->aktif = true;
                $personel->takvimde_gorunsun = true;
                $personel->renk = $this->siradakiRenk($salonId);
            }
            $personel->personel_adi = $g['personel_adi'] ?? null;
            $personel->unvan = $g['unvan'] ?? null;
            // Telefonu SADECE dolu VE maskeli degilse yaz (mevcut gercek numara korunur).
            if ($tel !== '' && ! Telefon::maskeliMi($tel)) {
                $personel->cep_telefon = $tel;
            }
            $personel->salon_id = $salonId;
            $personel->cinsiyet = $g['cinsiyet'] ?? null;
            if (empty($g['maas_koru'])) {
                $personel->maas = $g['maas'] ?? null;
                $personel->hizmet_prim_yuzde = $g['hizmet_prim_yuzde'] ?? null;
                $personel->urun_prim_yuzde = $g['urun_prim_yuzde'] ?? null;
                $personel->paket_prim_yuzde = $g['paket_prim_yuzde'] ?? null;
                // Kaleme göre (detayli) prim mod bayraklari.
                $personel->hizmet_prim_detayli = empty($g['hizmet_prim_detayli']) ? 0 : 1;
                $personel->urun_prim_detayli   = empty($g['urun_prim_detayli']) ? 0 : 1;
                $personel->paket_prim_detayli  = empty($g['paket_prim_detayli']) ? 0 : 1;
            }
            $personel->yetkili_id = $yetkili->id;
            $rol = $g['sistem_yetki'] ?? null;
            if ($rol !== null && $rol !== '') {
                $personel->role_id = (int) $rol;
            }
            $personel->save();

            // Rol senkron: model_has_roles (salon-scoped). ESKI model_type string sart —
            // eski app (Flutter'i hala besliyor) 'App\IsletmeYetkilileri' ile sorgular.
            if ($rol !== null && $rol !== '' && is_numeric($rol)) {
                DB::table('model_has_roles')
                    ->where('model_id', $yetkili->id)
                    ->where('salon_id', $salonId)
                    ->delete();
                DB::table('model_has_roles')->insert([
                    'role_id'    => (int) $rol,
                    'model_type' => 'App\\IsletmeYetkilileri',
                    'model_id'   => $yetkili->id,
                    'salon_id'   => $salonId,
                ]);
            }

            // Saatler opsiyonel (bos gelirse dokunma).
            $this->saatleriYaz((int) $personel->id, $g['calisma_saatleri'] ?? [], $g['mola_saatleri'] ?? []);

            // Kaleme göre prim oranlari (gönderilen tur icin sil-ve-yaz; eski personelekleduzenle ile ayni).
            $this->primOranlariYaz((int) $personel->id, $salonId, $g['prim_kalemleri'] ?? []);

            // Yeni hesabin sifresi: telefon varsa eski koddan uretilip gonderilsin.
            $sifreGonderiliyor = $yeniHesap && (string) $yetkili->gsm1 !== '';
            if ($sifreGonderiliyor) {
                BildirimKuyrugu::yaz($salonId, 'personel_sifre', null, $yapanId,
                    ['personel_id' => (int) $personel->id, 'yeni_hesap' => 1]);
            }

            AktiviteLog::yaz($salonId, $yapanId, $duzenleme ? 'personel_guncelle' : 'personel_ekle', 'personel',
                (int) $personel->id, $personel->personel_adi, $duzenleme ? 'Personel güncellendi' : 'Yeni personel eklendi');

            return [
                'personel_id'        => (int) $personel->id,
                'yeni_mi'            => ! $duzenleme,
                'yeni_hesap'         => $yeniHesap,
                'sifre_gonderiliyor' => $sifreGonderiliyor,
            ];
        });
    }

    // ===================== yardimcilar =====================

    /** Verilen gun dizilerini sil-ve-yaz (bos dizi gelirse o tur korunur). */
    private function saatleriYaz(int $personelId, array $calisma, array $mola): void
    {
        if (! empty($calisma)) {
            HaftalikSaatDeposu::yaz('personel', $personelId, HaftalikSaatDeposu::CALISMA, $calisma);
        }
        if (! empty($mola)) {
            HaftalikSaatDeposu::yaz('personel', $personelId, HaftalikSaatDeposu::MOLA, $mola);
        }
    }

    /**
     * Kaleme göre prim oranlarini yaz (idempotent upsert). $kalemler:
     * {hizmet:{kalem_id:yuzde}, urun:{...}, paket:{...}}. Gönderilen tur icin
     * mevcut satirlar silinip yeniden yazilir (bos map → o turu temizler).
     */
    private function primOranlariYaz(int $personelId, int $salonId, $kalemler): void
    {
        if (! is_array($kalemler)) {
            return;
        }
        foreach (['hizmet', 'urun', 'paket'] as $tur) {
            if (! array_key_exists($tur, $kalemler)) {
                continue; // o tur hic gönderilmediyse dokunma
            }
            $map = is_array($kalemler[$tur]) ? $kalemler[$tur] : [];
            PersonelPrimOrani::where('personel_id', $personelId)->where('tur', $tur)
                // Pasif paketin orani formda yok; silinirse eski satislarin primi degisir.
                ->when($tur === 'paket', fn ($q) => $q->whereNotIn('kalem_id', DB::table('paketler')->where('salon_id', $salonId)->where('aktif', 0)->pluck('id')))
                ->delete();
            foreach ($map as $kalemId => $yuzde) {
                $kalemId = (int) $kalemId;
                $yuzde = (float) str_replace(',', '.', (string) $yuzde);
                if ($kalemId <= 0 || $yuzde <= 0) {
                    continue; // bos/0 satir kaydedilmez
                }
                PersonelPrimOrani::create([
                    'personel_id' => $personelId,
                    'salon_id'    => $salonId,
                    'tur'         => $tur,
                    'kalem_id'    => $kalemId,
                    'yuzde'       => $yuzde,
                ]);
            }
        }
    }

    /** Yeni kayit icin siradaki renk (1..10 dongusel), eski personelekleduzenle ile birebir. */
    private function siradakiRenk(int $salonId): int
    {
        $son = Personeller::where('salon_id', $salonId)
            ->whereNotNull('renk')
            ->orderBy('id', 'desc')
            ->first();

        if ($son === null) {
            return 1;
        }

        return ((int) $son->renk === 10) ? 1 : ((int) $son->renk + 1);
    }

    private function bul(int $personelId): Personeller
    {
        return Personeller::find($personelId) ?? throw new BulunamadiException('Personel bulunamadı');
    }
}
