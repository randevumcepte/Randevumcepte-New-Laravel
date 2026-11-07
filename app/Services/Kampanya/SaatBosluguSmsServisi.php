<?php

namespace App\Services\Kampanya;

use App\Domain\Kampanya\SaatBosluguKampanyasi;
use App\Exceptions\BulunamadiException;
use App\Exceptions\GecersizIslemException;
use App\Exceptions\HariciServisException;
use App\Services\Sms\Gonderim\VoiceTelekomGonderici;
use App\Services\Sms\Rapor\SaglayiciHatasi;
use App\Support\AktiviteLog;
use Illuminate\Support\Facades\DB;

/**
 * "Müşterilere SMS Gönder". Eski saatBosluguKampanyaBildirimGonder ile aynı alıcı kuralı, aynı metin
 * ve aynı sağlayıcı (salon hesabı, yoksa ortak VoiceTelekom hesabı). Gönderim eşzamanlıdır: ekran
 * gerçek sayıyı ve sağlayıcı hatasını bugünkü gibi gösterir. Telefonlar cevaba çıkmaz.
 */
class SaatBosluguSmsServisi
{
    private const ZAMAN_ASIMI_SN = 55;

    private const VARSAYILAN_GONDEREN = 'BILGI';

    private const PAKET_BASLIGI = 'Kampanya';

    /** Eski kod `isletme_adi` okur; `salonlar`da bu kolon olmadığı için müşteriye hep "Salon" gider. Metin korunur. */
    private const METIN_ONEKI = 'Salon';

    public function __construct(private VoiceTelekomGonderici $gonderici) {}

    public function gonder(int $salonId, int $kampanyaId, int $enFazla, int $yetkiliId): array
    {
        $k = DB::table('kampanyalar')->where('id', $kampanyaId)->where('salon_id', $salonId)->where('onayli', 1)
            ->first(['id', 'kampanya_baslik', 'kampanya_aciklama', 'kampanya_detay'])
            ?? throw new BulunamadiException('Aktif kampanya bulunamadı.');
        if (! SaatBosluguKampanyasi::etiketliMi($k->kampanya_detay)) {
            throw new GecersizIslemException('Bu kampanya için bildirim gönderilemez.');
        }

        $musteriler = DB::table('musteri_portfoy')->where('salon_id', $salonId)->where('aktif', 1)
            ->where(fn ($w) => $w->whereNull('kara_liste')->orWhere('kara_liste', '!=', 1))
            ->pluck('user_id')->all();
        if (! $musteriler) {
            throw new GecersizIslemException('Bildirilecek müşteri bulunamadı.');
        }
        $numaralar = DB::table('users')->whereIn('id', $musteriler)->whereNotNull('cep_telefon')->where('cep_telefon', '!=', '')
            ->limit($enFazla)->pluck('cep_telefon')->filter()->unique()->values()->all();
        if (! $numaralar) {
            throw new GecersizIslemException('Geçerli telefon bulunamadı.');
        }

        $salon = DB::table('salonlar')->where('id', $salonId)->first(['id', 'sms_user_name', 'sms_secret', 'sms_baslik']);
        $metin = SaatBosluguKampanyasi::smsMetni(self::METIN_ONEKI, (string) $k->kampanya_baslik, (string) $k->kampanya_aciklama);

        try {
            $paket = $this->gonderici->gonder($this->hesap($salon), $numaralar, $metin, self::PAKET_BASLIGI, self::ZAMAN_ASIMI_SN);
        } catch (SaglayiciHatasi $e) {
            throw new HariciServisException('SMS gönderimi başarısız: '.$e->getMessage());
        }

        $adet = count($numaralar);
        AktiviteLog::yaz($salonId, $yetkiliId, 'kampanya_sms_gonder', 'kampanya', $kampanyaId, (string) $k->kampanya_baslik, $adet.' müşteriye SMS gönderildi.', ['alici_sayisi' => $adet]);

        return [
            'status'          => 'success',
            'gonderildi'      => $adet,
            'toplam_musteri'  => count($musteriler),
            'kampanya_baslik' => $k->kampanya_baslik,
            'paket_id'        => $paket,
            'mesaj'           => $adet.' müşteriye SMS gönderildi.',
        ];
    }

    /** Salonun kendi hesabı yoksa eski kodla aynı şekilde ortak hesap ve BILGI başlığı kullanılır. */
    private function hesap(?object $salon): object
    {
        return (object) [
            'sms_user_name' => $salon?->sms_user_name ?: (string) config('sms.voicetelekom.ortak_kullanici'),
            'sms_secret'    => $salon?->sms_secret ?: (string) config('sms.voicetelekom.ortak_sifre'),
            'sms_baslik'    => $salon?->sms_baslik ?: self::VARSAYILAN_GONDEREN,
        ];
    }
}
