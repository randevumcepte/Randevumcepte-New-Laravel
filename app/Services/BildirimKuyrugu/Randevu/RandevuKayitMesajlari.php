<?php

namespace App\Services\BildirimKuyrugu\Randevu;

use App\Models\IsletmeYetkilileri;
use App\Models\Personeller;
use App\Models\Randevular;
use App\Models\User;
use App\Services\BildirimKuyrugu\Kanal\UygulamaIciBildirim;
use App\Services\BildirimKuyrugu\Kanal\WhatsappMesajFormat;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Eski ApiController::randevuKayitBildirimHazirla: bir randevunun musteri + personel mesajlarini hazirlar, uygulama ici
 * bildirimleri yazar. $istek eskideki gibi payload.istek'ten kurulan Request'tir; durum yoksa (int)null === 0 sayilir.
 *
 * Personel metni personele hitap eder ("... randevusu Ayşe tarafından oluşturulmuştur."); eskiden musteri cumlesi kullanilir,
 * cift nokta ve cift bosluk cikardi. Eskiden gelen ve KORUNAN: bypass 18:00'den sonra acilir, log metni 19 der.
 */
class RandevuKayitMesajlari
{
    private const AYAR_ID = 12;

    /** Yoneticileri de SMS alan salon (eskide kaynakta sabit). */
    private const YONETICI_SMS_SALONU = 257;

    public function __construct(
        private RandevuVerisi $veri,
        private UygulamaIciBildirim $bildirim,
    ) {}

    /** @return array<int, array> gonderilecek mesajlar: ['to', 'message', 'wa_message'?] */
    public function hazirla(Request $istek, Randevular $randevu, bool $guncelleme, bool $zamanDegisti, bool $hizmetDegisti, ?bool $eskiRandevu): array
    {
        $mesajlar = [];
        $isletme = $this->veri->salon($randevu->salon_id);
        $musteri = User::where('id', $randevu->user_id)->first();
        $gsm = $musteri->cep_telefon;
        $olusturan = IsletmeYetkilileri::where('id', $istek->olusturan)->first();
        $olusturanResmi = $olusturan?->profil_resim;

        // Musteri kaynakli yeni randevu henuz onaylanmamistir: "talep alindi" tonu; onaylaninca ayrica bildirim gider.
        $kaynak = $istek->randevuKaynak ?: $istek->input('randevuKaynak');
        $musteriKaynakli = ! $guncelleme && (
            in_array($kaynak, ['uygulama', 'web'], true)
            || ! empty($randevu->uygulama)
            || ! empty($randevu->web)
            || ((int) $istek->durum === 0)
        );
        Log::info('[RND-MUSTERI-MSG] kaynak karar', [
            'randevu_id' => $randevu->id, 'kaynak' => $kaynak, 'db_uygulama' => (int) ($randevu->uygulama ?? 0), 'db_web' => (int) ($randevu->web ?? 0),
            'durum' => $istek->durum, 'guncelleme' => $guncelleme, 'musteri_kaynakli' => $musteriKaynakli,
        ]);

        [$cumleyeEk, $ekCumle, $personelFiili] = $this->cumleler($isletme, $musteriKaynakli, $guncelleme);
        $tarihMetni = date('d.m.Y', strtotime($istek->randevu_tarihi));
        $musteriMesaji = $musteriKaynakli
            ? $isletme->salon_adi.' için '.$tarihMetni.' '.$istek->randevu_saati.' tarihli '.$cumleyeEk.$ekCumle
            : $isletme->salon_adi.' tarafından '.$tarihMetni.' '.$istek->randevu_saati.' olarak '.$cumleyeEk.$ekCumle;

        $this->bildirim->ekle($randevu->salon_id, $musteriMesaji, '#', null, $randevu->user_id, $olusturanResmi, $randevu->id);

        $musteriToggle = $this->veri->ayarAcikMi($randevu->salon_id, self::AYAR_ID, 'musteri');
        $kritikBypass = $this->kritikBypass($istek);
        $degisti = $zamanDegisti || $hizmetDegisti || $eskiRandevu == null;

        if (($musteriToggle || $kritikBypass) && $degisti) {
            $mesajlar[] = [
                'to'         => $gsm,
                'message'    => $musteriMesaji, // SMS'e giden kisa metin
                'wa_message' => $this->whatsappMetni($istek, $randevu, $isletme, $musteri, $musteriMesaji, $tarihMetni, $musteriKaynakli, $guncelleme, $eskiRandevu),
            ];
            if (! $musteriToggle && $kritikBypass) {
                Log::info('[RND-CREATE-API] kritik bypass (toggle kapali, 19 sonra ertesi gun <24h kala)', [
                    'salon_id' => $randevu->salon_id, 'randevu_id' => $randevu->id, 'tarih_saat' => $istek->randevu_tarihi.' '.$istek->randevu_saati,
                ]);
            }
        }

        if ($degisti) {
            array_push($mesajlar, ...$this->personelMesajlari($randevu, $olusturan, $olusturanResmi, $personelFiili));
        }

        return $mesajlar;
    }

    /** @return array{0:string, 1:string, 2:string} [musteri cumlesine eklenen, ek cumle, personel metnindeki fiil] */
    private function cumleler(object $isletme, bool $musteriKaynakli, bool $guncelleme): array
    {
        if ($musteriKaynakli) {
            $isyeriKucuk = WhatsappMesajFormat::klinikMi($isletme) ? 'klinik' : 'salon';

            return ['randevu talebiniz alınmıştır.', ' Talebiniz '.$isyeriKucuk.' tarafından onaylandığında ayrıca bilgilendirileceksiniz. Detaylı bilgi için bize ulaşın. 0'.$isletme->telefon_1, 'talep edilmiştir'];
        }
        $ekCumle = ' Randevunuza 15 dk önce gelmenizi rica ederiz. Detaylı bilgi için bize ulaşın. 0'.$isletme->telefon_1;

        return $guncelleme
            ? ['randevunuz güncellenmiştir.', $ekCumle, 'güncellenmiştir']
            : ['randevunuz oluşturulmuştur.', $ekCumle, 'oluşturulmuştur'];
    }

    /** Ertesi gune 18:00'den sonra olusan, 24 saatten az kalan randevuda ayar kapali olsa da musteriye gider. */
    private function kritikBypass(Request $istek): bool
    {
        $simdi = time();
        $randevuZamani = strtotime($istek->randevu_tarihi.' '.$istek->randevu_saati);

        return date('Y-m-d', strtotime($istek->randevu_tarihi)) === date('Y-m-d', strtotime('+1 day'))
            && (int) date('G', $simdi) >= 18 // 1-gun-once penceresi 15:00-18:00; 18 sonrasi olusanlar bypass
            && ($randevuZamani - $simdi > 0)
            && (($randevuZamani - $simdi) / 3600 < 24);
    }

    /** Zengin bicim yalniz yeni olusturmada; guncellemede SMS metni aynen gider. */
    private function whatsappMetni(Request $istek, Randevular $randevu, object $isletme, User $musteri, string $musteriMesaji, string $tarihMetni, bool $musteriKaynakli, bool $guncelleme, ?bool $eskiRandevu): string
    {
        if ($eskiRandevu != null || $guncelleme) {
            return $musteriMesaji;
        }

        $hizmetAdlari = collect();
        foreach ($randevu->hizmetler as $hizmet) {
            $ad = $this->veri->hizmetAdiVarsa($hizmet);
            if ($ad) {
                $hizmetAdlari->push($ad);
            }
        }
        $hizmetlerMetni = $hizmetAdlari->unique()->values()->implode(', ');

        $metin = $musteriKaynakli
            ? WhatsappMesajFormat::randevuTalebiAlindi($isletme, $musteri->name ?? '', $tarihMetni, $istek->randevu_saati, $hizmetlerMetni)
            : WhatsappMesajFormat::randevuOlusturuldu($isletme, $musteri->name ?? '', $tarihMetni, $istek->randevu_saati, $hizmetlerMetni);

        // Uygulamasi olmayana WA'da indirme daveti + link.
        return WhatsappMesajFormat::uygulamaDavetiEk($metin, $isletme, $musteri->id ?? null);
    }

    private function personelMesajlari(Randevular $randevu, ?IsletmeYetkilileri $olusturan, ?string $olusturanResmi, string $fiil): array
    {
        $mesajlar = [];
        foreach ($randevu->hizmetler as $hizmet) {
            $kim = $olusturan ? (string) $olusturan->name : ($randevu->uygulama == true ? 'kendisi' : '');
            $tarafindan = trim($kim) !== '' ? ' '.trim($kim).' tarafından' : '';
            $mesaj = $randevu->users->name.' isimli müşterinin '.date('d.m.Y', strtotime($randevu->tarih)).' - '.date('H:i', strtotime($hizmet->saat)).' '.$this->veri->hizmetAdi($hizmet).' randevusu'.$tarafindan.' '.$fiil.'.';

            if ($this->veri->ayarAcikMi($randevu->salon_id, self::AYAR_ID, 'personel')) {
                $yetkiliIdler = Personeller::where('id', $hizmet->personel_id)->orWhere(function ($q) use ($randevu) {
                    if ($randevu->salon_id == self::YONETICI_SMS_SALONU) {
                        $q->where('salon_id', $randevu->salon_id)->where('role_id', '<', 5);
                    }
                })->pluck('yetkili_id')->toArray();
                foreach ($yetkiliIdler as $yetkiliId) {
                    $mesajlar[] = ['to' => $this->veri->yetkiliAlani($yetkiliId, 'gsm1'), 'message' => $mesaj];
                }
            }

            $this->bildirim->ekle($randevu->salon_id, $mesaj, '#', $hizmet->personel_id, null, $olusturanResmi, $randevu->id);
        }

        return $mesajlar;
    }
}
