<?php

namespace App\Services\Form;

use App\Domain\Form\ArsivDurum;
use App\Domain\Form\FormLinki;
use App\Domain\Form\OdemePlani;
use App\Domain\Form\SabitFormlar;
use App\Domain\Personel\Telefon;
use App\Exceptions\BulunamadiException;
use App\Exceptions\GecersizIslemException;
use App\Models\Arsiv;
use App\Models\FormTaslaklari;
use App\Models\Personeller;
use App\Support\AktiviteLog;
use App\Support\BildirimKuyrugu;
use App\Support\DosyaYukleme;
use App\Support\EskiPanelLinki;
use App\Support\Yetki\YetkiSabitleri;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Arşiv YAZMA işlemleri — TEK KAYNAK (web v2 + Flutter).
 *
 * Eski kaynaklar: StoreAdminController@arsivformekleme / formutekrargonder / arsivonaylaform
 * / arsiviptalform / haricibelgeekleme / sozlesmeOlustur ve ApiController karşılıkları.
 * Bilinçli farklar:
 *   - Her işlem salon KAPSAMINDA (eskide arsiv_id ile başka salonun kaydı değişebiliyordu).
 *   - Müşteri salonun portföyünde olmalı (rastgele user_id'ye SMS gitmez).
 *   - Link metni tek yerde (FormLinki): yeniden gönderimde form 9-13 özel sayfaları korunur.
 *   - Müşteri kartındaki bilgiler (doğum tarihi/TC) form gönderirken EZİLMEZ (v1 hatası).
 *   - Maskeli telefon gelirse (musteri.telefon_gor kapalı) kayıtlı telefon kullanılır.
 *   - SMS/WhatsApp v2'den gönderilmez: 'form_link' kuyruğa yazılır, eski monolit gönderir
 *     (WhatsApp bağlıysa önce WA, olmazsa SMS — ApiController@_formLinkGonder).
 */
class ArsivIslemServisi
{
    private const ODEME_SEKILLERI = ['pesin', 'taksit', 'kredi_karti'];

    /** Arşiv kaydının salonu (kapsam denetimi için; yoksa null). */
    public function salonId(int $arsivId): ?int
    {
        $id = Arsiv::whereKey($arsivId)->value('salon_id');

        return $id ? (int) $id : null;
    }

    /** Form gönder modalı: seçilen müşterinin telefonu (yetkiye göre maskeli). */
    public function musteriBilgi(int $salonId, int $userId, bool $telGor): array
    {
        $this->portfoyDenetle($salonId, $userId);
        $tel = (string) DB::table('users')->where('id', $userId)->value('cep_telefon');

        return ['telefon' => $telGor ? $tel : YetkiSabitleri::telefonMaskele($tel)];
    }

    /**
     * Müşteriye form gönder (arşive form kaydı + link mesajı).
     *
     * @param array{form_id:int,user_id:int,telefon:?string,personel_id:?int,hizmet_id:?int,
     *              toplam_ucret:?float,kapora:?float,sadece_kaydet:bool} $g
     */
    public function formGonder(int $salonId, int $yapanId, array $g): array
    {
        $form = FormTaslaklari::where('salon_id', $salonId)->whereKey((int) $g['form_id'])->first();
        if (! $form) {
            throw new GecersizIslemException('Form/sözleşme türünü seçiniz.');
        }
        if (empty($g['user_id'])) {
            throw new GecersizIslemException('Müşteri/danışan seçiniz.');
        }
        $this->portfoyDenetle($salonId, (int) $g['user_id']);
        if ($hata = SabitFormlar::ucretHatasi((int) $form->id, $g['toplam_ucret'], $g['kapora'])) {
            throw new GecersizIslemException($hata);
        }
        $personelId = ! empty($g['personel_id']) ? $this->personelDenetle($salonId, (int) $g['personel_id']) : null;
        $olusturan = $this->yapanPersonel($salonId, $yapanId);

        return DB::transaction(function () use ($salonId, $yapanId, $g, $form, $personelId, $olusturan) {
            $arsiv = Arsiv::create([
                'salon_id'       => $salonId,
                'user_id'        => (int) $g['user_id'],
                'form_id'        => (int) $form->id,
                'personel_id'    => $personelId ?? ($olusturan ?: 0),
                'form_olusturan' => $olusturan,
                'dogrulama_kodu' => FormLinki::dogrulamaKodu(),
                'toplam_ucret'   => $g['toplam_ucret'],
                'kapora'         => $g['kapora'],
                'hizmet_id'      => ($g['hizmet_id'] ?? null) ?: null,
                'cevapladi'      => 0,
                'cevapladi2'     => 0,
            ]);

            AktiviteLog::yaz($salonId, $yapanId, 'form_gonder', 'arsiv', (int) $arsiv->id,
                $form->form_adi.' — '.$this->musteriAdi((int) $g['user_id']), 'Müşteriye form / sözleşme gönderildi',
                ['form_id' => (int) $form->id, 'user_id' => (int) $g['user_id'], 'tutar' => $g['toplam_ucret'], 'kapora' => $g['kapora']]);

            $gonderildi = false;
            if (empty($g['sadece_kaydet'])) {
                $mesajlar = [['to' => $this->musteriTelefonu((int) $g['user_id'], $g['telefon'] ?? null),
                    'message' => FormLinki::musteriMesaji(EskiPanelLinki::host(), $arsiv->toArray(), (bool) $form->is_dinamik)]];
                if ($personelId && ($pm = FormLinki::personelMesaji(EskiPanelLinki::host(), $arsiv->toArray(), (bool) $form->is_dinamik, $personelId))) {
                    $mesajlar[] = ['to' => (string) Personeller::whereKey($personelId)->value('cep_telefon'), 'message' => $pm];
                }
                $gonderildi = $this->kuyrugaYaz($salonId, $yapanId, $arsiv, $mesajlar, true);
            }

            return ['arsiv_id' => (int) $arsiv->id, 'gonderildi' => $gonderildi];
        });
    }

    /**
     * Formu yeniden gönder: yeni onay kodu, cevap/durum sıfırlanır (onaylı/iptal kayıt
     * yeniden bekleyene döner — eskiyle aynı). Kara listedeki müşteriye gönderilmez.
     */
    public function tekrarGonder(int $arsivId, int $yapanId): array
    {
        $arsiv = $this->bul($arsivId);
        if (ArsivDurum::tur($arsiv->toArray()) === ArsivDurum::TUR_HARICI) {
            throw new GecersizIslemException('Harici belge gönderilemez.');
        }

        return DB::transaction(function () use ($arsiv, $yapanId) {
            $arsiv->fill(['cevapladi' => 0, 'durum' => null, 'dogrulama_kodu' => FormLinki::dogrulamaKodu()])->save();

            $gonderildi = false;
            $karaListe = (int) DB::table('musteri_portfoy')->where('user_id', $arsiv->user_id)
                ->where('salon_id', $arsiv->salon_id)->value('kara_liste') === 1;
            if ($arsiv->user_id && ! $karaListe) {
                $dinamik = (bool) FormTaslaklari::whereKey($arsiv->form_id)->value('is_dinamik');
                $gonderildi = $this->kuyrugaYaz((int) $arsiv->salon_id, $yapanId, $arsiv, [[
                    'to'      => $this->musteriTelefonu((int) $arsiv->user_id, null),
                    'message' => FormLinki::musteriMesaji(EskiPanelLinki::host(), $arsiv->toArray(), $dinamik),
                ]], true);
            }
            AktiviteLog::yaz((int) $arsiv->salon_id, $yapanId, 'form_gonder', 'arsiv', (int) $arsiv->id, null,
                $gonderildi ? 'Form müşteriye yeniden gönderildi' : 'Form yeniden gönderime hazırlandı (müşteri kara listede)');

            return ['arsiv_id' => (int) $arsiv->id, 'gonderildi' => $gonderildi];
        });
    }

    public function onayla(int $arsivId, int $yapanId): void
    {
        $this->durumYaz($arsivId, 1, 'arsiv_onayla', 'Form onaylandı', $yapanId);
    }

    public function iptal(int $arsivId, int $yapanId): void
    {
        $this->durumYaz($arsivId, 0, 'arsiv_iptal', 'Form iptal edildi', $yapanId);
    }

    /**
     * Harici belge yükle. Dosya eski monolitin public/formlar dizinine BENZERSİZ adla
     * yazılır (eskide aynı adlı dosyalar birbirini eziyordu); uzantı beyaz listede.
     */
    public function hariciBelgeEkle(int $salonId, int $yapanId, array $g, ?UploadedFile $dosya): array
    {
        $baslik = trim((string) ($g['baslik'] ?? ''));
        if ($baslik === '') {
            throw new GecersizIslemException('Form başlığı zorunludur.');
        }
        if (empty($g['user_id'])) {
            throw new GecersizIslemException('Müşteri/danışan seçiniz.');
        }
        if (empty($g['personel_id'])) {
            throw new GecersizIslemException('İşlemi yapacak personeli seçiniz.');
        }
        $this->portfoyDenetle($salonId, (int) $g['user_id']);
        $personelId = $this->personelDenetle($salonId, (int) $g['personel_id']);
        $uzanti = $this->dosyaKaydet($dosya);

        $arsiv = Arsiv::create([
            'salon_id'       => $salonId,
            'user_id'        => (int) $g['user_id'],
            'personel_id'    => $personelId,
            'form_id'        => 0,
            'harici_belge'   => mb_substr($baslik, 0, 250),
            'uzanti'         => $uzanti,
            'form_olusturan' => $this->yapanPersonel($salonId, $yapanId),
        ]);
        AktiviteLog::yaz($salonId, $yapanId, 'harici_belge_ekle', 'arsiv', (int) $arsiv->id, $baslik, 'Harici belge eklendi');

        return ['arsiv_id' => (int) $arsiv->id];
    }

    /**
     * Hizmet sözleşmesi oluştur + müşteriye imza linki (web sozlesmeOlustur birebir):
     * salon yetkilisi oluştururken panelde imzalar (çift taraflı; cevapladi2=1).
     */
    public function sozlesmeOlustur(int $salonId, int $yapanId, array $g, string $ip): array
    {
        $userId = (int) ($g['user_id'] ?? 0);
        $telefon = trim((string) ($g['cep_telefon'] ?? ''));
        if (! $userId || $telefon === '') {
            throw new GecersizIslemException('Müşteri ve cep telefon zorunlu.');
        }
        $this->portfoyDenetle($salonId, $userId);
        $imza = (string) ($g['salon_imza'] ?? '');
        if (! str_starts_with($imza, 'data:image/') || strlen($imza) < 500) {
            throw new GecersizIslemException('Salon yetkilisi imzası zorunludur. Lütfen imza alanına imzanızı atın.');
        }
        $hizmetId = ! empty($g['hizmet_id']) ? (int) $g['hizmet_id'] : null;
        if ($hizmetId && ! DB::table('salon_sunulan_hizmetler')->where('id', $hizmetId)->where('salon_id', $salonId)->exists()) {
            throw new GecersizIslemException('Hizmet bulunamadı.');
        }
        $paketId = ! empty($g['paket_id']) ? (int) $g['paket_id'] : null;
        if ($paketId && ! DB::table('paketler')->where('id', $paketId)->where('salon_id', $salonId)->exists()) {
            throw new GecersizIslemException('Paket bulunamadı.');
        }

        $toplam = (float) ($g['toplam_ucret'] ?? 0);
        $kapora = (float) ($g['kapora'] ?? 0);
        $odeme = in_array($g['odeme_sekli'] ?? null, self::ODEME_SEKILLERI, true) ? $g['odeme_sekli'] : 'pesin';
        $taksitSayisi = (int) ($g['taksit_sayisi'] ?? 0);
        $taksit = ['taksit_sayisi' => null, 'taksit_tutari' => null, 'ilk_taksit_tarihi' => null, 'odeme_plani_json' => null];
        if ($odeme === 'taksit' && $taksitSayisi >= 1) {
            $tt = isset($g['taksit_tutari']) && $g['taksit_tutari'] !== '' ? (float) $g['taksit_tutari'] : null;
            $plan = OdemePlani::uret($toplam, $kapora, min(60, $taksitSayisi), $tt, ($g['ilk_taksit_tarihi'] ?? null) ?: null);
            $taksit = [
                'taksit_sayisi'     => min(60, $taksitSayisi),
                'taksit_tutari'     => $plan['taksit_tutari'],
                'ilk_taksit_tarihi' => ($g['ilk_taksit_tarihi'] ?? null) ?: null,
                'odeme_plani_json'  => json_encode($plan['plan'], JSON_UNESCAPED_UNICODE),
            ];
        }
        $olusturan = $this->yapanPersonel($salonId, $yapanId);

        return DB::transaction(function () use ($salonId, $yapanId, $g, $userId, $telefon, $imza, $hizmetId, $paketId, $toplam, $kapora, $odeme, $taksit, $olusturan, $ip) {
            $arsiv = Arsiv::create([
                'salon_id'              => $salonId,
                'user_id'               => $userId,
                'form_id'               => 0,
                'is_sozlesme'           => 1,
                'harici_belge'          => 'Hizmet Sözleşmesi',
                'personel_id'           => $olusturan ?: 0,
                'form_olusturan'        => $olusturan,
                'dogrulama_kodu'        => FormLinki::dogrulamaKodu(),
                'hizmet_id'             => $hizmetId,
                'paket_id'              => $paketId,
                'seans_sayisi'          => ! empty($g['seans_sayisi']) ? (int) $g['seans_sayisi'] : null,
                'toplam_ucret'          => $toplam,
                'kapora'                => $kapora,
                'sozlesme_notu'         => trim((string) ($g['sozlesme_notu'] ?? '')) ?: null,
                'sozlesme_metni'        => trim((string) ($g['sozlesme_metni'] ?? '')) ?: null,
                'odeme_sekli'           => $odeme,
                'gecerlilik_tarihi'     => ($g['gecerlilik_tarihi'] ?? null) ?: null,
                'salon_imza'            => $imza,
                'salon_yetkili_ad'      => trim((string) ($g['salon_yetkili_ad'] ?? '')) ?: null,
                'salon_yetkili_telefon' => trim((string) ($g['salon_yetkili_telefon'] ?? '')) ?: null,
                'salon_imza_ip'         => $ip,
                'salon_imza_zaman'      => now(),
                'cevapladi'             => 0,
                'cevapladi2'            => 1,
            ] + $taksit);

            AktiviteLog::yaz($salonId, $yapanId, 'sozlesme_olustur', 'sozlesme', (int) $arsiv->id,
                'Hizmet Sözleşmesi — '.$this->musteriAdi($userId), 'Hizmet sözleşmesi oluşturuldu', ['user_id' => $userId]);

            $gonderildi = $this->kuyrugaYaz($salonId, $yapanId, $arsiv, [[
                'to'      => $this->musteriTelefonu($userId, $telefon),
                'message' => FormLinki::musteriMesaji(EskiPanelLinki::host(), $arsiv->toArray(), false),
            ]], true);

            return ['arsiv_id' => (int) $arsiv->id, 'gonderildi' => $gonderildi];
        });
    }

    // ---------------------------------------------------------------------

    private function bul(int $arsivId): Arsiv
    {
        $a = Arsiv::whereKey($arsivId)->first();
        if (! $a) {
            throw new BulunamadiException('Kayıt bulunamadı.');
        }

        return $a;
    }

    private function durumYaz(int $arsivId, int $durum, string $action, string $aciklama, int $yapanId): void
    {
        $arsiv = $this->bul($arsivId);
        $arsiv->fill(['durum' => $durum, 'cevapladi' => 0])->save();
        AktiviteLog::yaz((int) $arsiv->salon_id, $yapanId, $action, 'arsiv', (int) $arsiv->id, null, $aciklama);
    }

    /** Link mesajlarını outbox'a yaz (boş numaralar elenir). Gönderilecek mesaj varsa true. */
    private function kuyrugaYaz(int $salonId, int $yapanId, Arsiv $arsiv, array $mesajlar, bool $smsBildirim): bool
    {
        $mesajlar = array_values(array_filter($mesajlar, fn ($m) => trim((string) $m['to']) !== ''));
        if (! $mesajlar) {
            return false;
        }
        BildirimKuyrugu::yaz($salonId, 'form_link', null, $yapanId, [
            'arsiv_id'     => (int) $arsiv->id,
            'user_id'      => (int) $arsiv->user_id,
            'mesajlar'     => $mesajlar,
            'sms_bildirim' => $smsBildirim,
        ]);

        return true;
    }

    /** Ekrandan gelen numara; boş ya da maskeli ise müşterinin kayıtlı numarası. */
    private function musteriTelefonu(int $userId, ?string $girilen): string
    {
        $girilen = trim((string) $girilen);
        if ($girilen !== '' && ! Telefon::maskeliMi($girilen)) {
            return $girilen;
        }

        return (string) DB::table('users')->where('id', $userId)->value('cep_telefon');
    }

    private function portfoyDenetle(int $salonId, int $userId): void
    {
        if (! DB::table('musteri_portfoy')->where('salon_id', $salonId)->where('user_id', $userId)->exists()) {
            throw new GecersizIslemException('Müşteri bu işletmenin portföyünde değil.');
        }
    }

    private function personelDenetle(int $salonId, int $personelId): int
    {
        if (! Personeller::where('salon_id', $salonId)->whereKey($personelId)->exists()) {
            throw new GecersizIslemException('Personel bulunamadı.');
        }

        return $personelId;
    }

    /** Giriş yapan yetkilinin bu salondaki personel kaydı (form_olusturan). */
    private function yapanPersonel(int $salonId, int $yapanId): ?int
    {
        $id = Personeller::where('salon_id', $salonId)->where('yetkili_id', $yapanId)->value('id');

        return $id ? (int) $id : null;
    }

    private function musteriAdi(int $userId): string
    {
        return (string) (DB::table('users')->where('id', $userId)->value('name') ?? '-');
    }

    private function dosyaKaydet(?UploadedFile $dosya): string
    {
        return 'public/formlar/'.DosyaYukleme::kaydet($dosya, (string) config('form.harici_dizin'),
            (array) config('form.harici_uzantilar'), (int) config('form.harici_maks_kb'), 'belge');
    }
}
