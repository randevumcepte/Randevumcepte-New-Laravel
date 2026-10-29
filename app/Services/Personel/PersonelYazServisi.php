<?php

namespace App\Services\Personel;

use App\Domain\Personel\Telefon;
use App\Exceptions\CakismaException;
use App\Models\IsletmeYetkilileri;
use App\Models\Personeller;
use App\Models\PersonelCalismaSaatleri;
use App\Models\PersonelMolaSaatleri;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Personel YAZMA servisi (Faz 2). Controller kill-switch (config personel.yazma_aktif)
 * ile korur; is mantigi burada. Eski ApiController personel yazma uclarindan portlandi.
 *
 * KOPRU NOTLARI (eski sistemde vardi, yeni projede HENUZ YOK — enable oncesi dikkat):
 *   - pasif alinca token revoke + force_logout push (OturumServisi) YOK.
 *   - yeni personel sifresi SMS/WhatsApp ile gonderilmiyor; olusturulan sifre
 *     yanitta ('olusturulan_sifre') doner, panel gosterir/iletebilir.
 *   - prim (personel_prim_oranlari) kalem bazli kayit Faz 3'e birakildi.
 */
class PersonelYazServisi
{
    // ===================== Faz 2a — dusuk riskli =====================

    /** Aktif/pasif bayragi. (NOT: pasifte token revoke koprusu yok — bkz. sinif notu.) */
    public function durum(int $personelId, bool $aktif): array
    {
        $p = Personeller::find($personelId);
        if (! $p) {
            abort(404, 'Personel bulunamadı');
        }
        $p->aktif = $aktif;
        $p->save();

        return ['id' => (int) $p->id, 'aktif' => (int) $p->aktif];
    }

    /** Takvimde gorunurlugu tersine cevir. */
    public function takvimToggle(int $personelId): array
    {
        $p = Personeller::find($personelId);
        if (! $p) {
            abort(404, 'Personel bulunamadı');
        }
        $p->takvimde_gorunsun = ! ((bool) $p->takvimde_gorunsun);
        $p->save();

        return ['takvimde_gorunsun' => (int) $p->takvimde_gorunsun];
    }

    /** Aktif personeller arasinda sirayi delta (-1 yukari / +1 asagi) kaydir. */
    public function siralama(int $salonId, int $personelId, int $delta): array
    {
        if ($delta !== 1 && $delta !== -1) {
            return ['sonuc' => 'error', 'mesaj' => 'delta 1 veya -1 olmalı'];
        }

        $aktifler = Personeller::where('salon_id', $salonId)
            ->where('aktif', 1)
            ->orderBy('takvim_sirasi', 'asc')
            ->orderBy('id', 'asc')
            ->get()
            ->values();

        $idx = $aktifler->search(fn ($p) => (int) $p->id === $personelId);
        if ($idx === false) {
            return ['sonuc' => 'notfound'];
        }
        $hedefIdx = $idx + $delta;
        if ($hedefIdx < 0 || $hedefIdx >= $aktifler->count()) {
            return ['sonuc' => 'sinir'];
        }

        $a = $aktifler[$idx];
        $b = $aktifler[$hedefIdx];
        $aSira = $a->takvim_sirasi;
        $bSira = $b->takvim_sirasi;
        if ($aSira != $bSira) {
            // 3 adimli swap (olasi UNIQUE constraint icin negatif temp deger).
            $a->takvim_sirasi = -1 * abs($a->id);
            $a->save();
            $b->takvim_sirasi = $aSira;
            $b->save();
            $a->takvim_sirasi = $bSira;
            $a->save();
        }

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
     * @return array{personel_id:int, yeni_mi:bool, yeni_hesap:bool, olusturulan_sifre:?string}
     */
    public function ekleDuzenle(array $g): array
    {
        $salonId = (int) $g['salon_id'];
        $personelId = ! empty($g['personel_id']) ? (int) $g['personel_id'] : null;
        $tel = Telefon::normalize($g['cep_telefon'] ?? '');
        $duzenleme = $personelId !== null;

        return DB::transaction(function () use ($g, $salonId, $personelId, $tel, $duzenleme) {
            $yetkili = null;
            $yeniHesap = false;
            $olusturulanSifre = null;
            $personel = null;

            if ($duzenleme) {
                $personel = Personeller::where('id', $personelId)->where('salon_id', $salonId)->first();
                if (! $personel) {
                    abort(404, 'Güncellenecek personel bulunamadı');
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
                $olusturulanSifre = $this->sifreUret();
                $yetkili->password = Hash::make($olusturulanSifre);
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
            $personel->maas = $g['maas'] ?? null;
            $personel->hizmet_prim_yuzde = $g['hizmet_prim_yuzde'] ?? null;
            $personel->urun_prim_yuzde = $g['urun_prim_yuzde'] ?? null;
            $personel->paket_prim_yuzde = $g['paket_prim_yuzde'] ?? null;
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

            return [
                'personel_id'       => (int) $personel->id,
                'yeni_mi'           => ! $duzenleme,
                'yeni_hesap'        => $yeniHesap,
                'olusturulan_sifre' => $olusturulanSifre,
            ];
        });
    }

    // ===================== yardimcilar =====================

    /** Verilen gun dizilerini sil-ve-yaz (bos dizi gelirse o tur korunur). */
    private function saatleriYaz(int $personelId, array $calisma, array $mola): void
    {
        if (! empty($calisma)) {
            PersonelCalismaSaatleri::where('personel_id', $personelId)->delete();
            foreach ($calisma as $gun) {
                PersonelCalismaSaatleri::create([
                    'personel_id'     => $personelId,
                    'haftanin_gunu'   => (int) ($gun['haftanin_gunu'] ?? 0),
                    'calisiyor'       => (int) ($gun['calisiyor'] ?? 0),
                    'baslangic_saati' => $gun['baslangic_saati'] ?? null,
                    'bitis_saati'     => $gun['bitis_saati'] ?? null,
                ]);
            }
        }

        if (! empty($mola)) {
            PersonelMolaSaatleri::where('personel_id', $personelId)->delete();
            foreach ($mola as $gun) {
                PersonelMolaSaatleri::create([
                    'personel_id'     => $personelId,
                    'haftanin_gunu'   => (int) ($gun['haftanin_gunu'] ?? 0),
                    'mola_var'        => (int) ($gun['mola_var'] ?? 0),
                    'baslangic_saati' => $gun['baslangic_saati'] ?? null,
                    'bitis_saati'     => $gun['bitis_saati'] ?? null,
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

    /** 6 haneli sifre (eski charset: karisabilir i/l/0/O atilmis). */
    private function sifreUret(): string
    {
        $random = str_shuffle('abcdefghjklmnopqrstuvwxyzABCDEFGHJKLMNOPQRSTUVWXYZ1234567890');

        return substr($random, 0, 6);
    }
}
