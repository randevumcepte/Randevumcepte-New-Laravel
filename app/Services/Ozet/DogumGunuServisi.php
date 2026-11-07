<?php

namespace App\Services\Ozet;

use App\Exceptions\BulunamadiException;
use App\Exceptions\GecersizIslemException;
use App\Services\Whatsapp\WhatsappOturumServisi;
use App\Support\BildirimKuyrugu;
use App\Support\Yetki\YetkiSabitleri;
use App\Support\ZamanliKayit;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Eski StoreAdminController _dogumGunu* cekirdegi. Gonderim outbox'tan gider: paylasilan oturum dahil WhatsApp baglaysa
 * whatsapp_mesaj, degilse sms. Sonuc ve kayit satiri eski cevapla ayni; gunluk kayit tekrar gonderimi engeller.
 */
class DogumGunuServisi
{
    private const LOG = 'dogum_gunu_mesaj_loglari';

    private const LISTE_SINIRI = 20;

    private const ATLANDI = 'atlandi';

    private const KILIT_SN = 30;

    public function __construct(private WhatsappOturumServisi $oturum) {}

    public function bugun(int $salonId, bool $telefonGor): array
    {
        $gonderilen = DB::table(self::LOG)->where('salon_id', $salonId)->whereDate('gonderim_tarihi', date('Y-m-d'))->pluck('user_id')->all();

        $liste = DB::table('users')
            ->join('musteri_portfoy', 'musteri_portfoy.user_id', '=', 'users.id')
            ->where('musteri_portfoy.salon_id', $salonId)
            ->where('musteri_portfoy.aktif', 1)
            ->whereNotNull('users.dogum_tarihi')
            ->whereRaw("DATE_FORMAT(users.dogum_tarihi, '%m-%d') = ?", [date('m-d')])
            ->limit(self::LISTE_SINIRI)
            ->get(['users.id', 'users.name', 'users.cep_telefon', 'users.dogum_tarihi'])
            ->map(fn ($r) => [
                'id'           => $r->id,
                'name'         => $r->name,
                'cep_telefon'  => $telefonGor || ! $r->cep_telefon ? $r->cep_telefon : YetkiSabitleri::telefonMaskele((string) $r->cep_telefon),
                'dogum_tarihi' => $r->dogum_tarihi,
                'gonderildi'   => in_array($r->id, $gonderilen),
            ]);

        return ['liste' => $liste, 'tarih' => date('Y-m-d')];
    }

    public function gonder(int $salonId, int $musteriId, int $yetkiliId): array
    {
        $musteri = DB::table('users')->where('id', $musteriId)->first(['id', 'name', 'cep_telefon']);
        if (! $musteri || ! $musteri->cep_telefon) {
            throw new BulunamadiException('Müşteri bulunamadı.');
        }
        if (! DB::table('musteri_portfoy')->where('user_id', $musteriId)->where('salon_id', $salonId)->where('aktif', 1)->exists()) {
            throw new GecersizIslemException('Bu müşteri işletmenizin portföyünde değil.');
        }

        // Cift tiklama: iki istek ayni anda log kontrolunu gecemez.
        $kilit = 'dogum.gunu.'.$salonId.'.'.$musteriId;
        if (! Cache::add($kilit, 1, self::KILIT_SN)) {
            return $this->zatenGonderildi();
        }
        try {
            if ($this->bugunGonderildi($salonId, $musteriId)) {
                return $this->zatenGonderildi();
            }

            return DB::transaction(fn () => $this->kuyrugaYaz($salonId, $musteri, $yetkiliId));
        } finally {
            Cache::forget($kilit);
        }
    }

    public function atla(int $salonId, int $musteriId): void
    {
        $var = DB::table(self::LOG)->where('salon_id', $salonId)->where('user_id', $musteriId)->whereDate('gonderim_tarihi', date('Y-m-d'))->exists();
        if (! $var) {
            $this->logYaz($salonId, $musteriId, self::ATLANDI, null, 'kullanici-hayir');
        }
    }

    private function kuyrugaYaz(int $salonId, object $musteri, int $yetkiliId): array
    {
        $salonAdi = (string) DB::table('salonlar')->where('id', $salonId)->value('salon_adi');
        $mesaj = 'Sayın '.$musteri->name.', '.$salonAdi.' olarak doğum gününüzü kutlar; sağlıklı, mutlu ve başarı dolu bir yıl dileriz. 🎂';

        $o = $this->oturum->coz($salonId);
        if ($o && $o['durum']->bagli) {
            BildirimKuyrugu::yaz($salonId, 'whatsapp_mesaj', null, $yetkiliId, ['user_id' => (int) $musteri->id, 'mesaj' => $mesaj, 'gonderim_tipi' => 'dogum_gunu']);
            $kanal = 'whatsapp';
        } else {
            BildirimKuyrugu::sms($salonId, $yetkiliId, [['to' => $musteri->cep_telefon, 'message' => $mesaj, 'user_id' => (int) $musteri->id]]);
            $kanal = 'sms';
        }
        $this->logYaz($salonId, (int) $musteri->id, $kanal, mb_substr($mesaj, 0, 500), $kanal === 'whatsapp' ? 'queued' : '');

        return ['ok' => true, 'kanal' => $kanal, 'mesaj' => $kanal === 'whatsapp' ? 'WhatsApp üzerinden gönderildi.' : 'SMS olarak gönderildi.'];
    }

    private function bugunGonderildi(int $salonId, int $musteriId): bool
    {
        return DB::table(self::LOG)->where('salon_id', $salonId)->where('user_id', $musteriId)
            ->where('kanal', '<>', self::ATLANDI)->whereDate('gonderim_tarihi', date('Y-m-d'))->exists();
    }

    private function zatenGonderildi(): array
    {
        return ['ok' => false, 'error' => 'zaten-gonderildi', 'mesaj' => 'Bu müşteriye bugün zaten doğum günü mesajı gönderildi.'];
    }

    private function logYaz(int $salonId, int $userId, string $kanal, ?string $mesaj, string $detay): void
    {
        ZamanliKayit::ekle(self::LOG, [
            'salon_id'        => $salonId,
            'user_id'         => $userId,
            'kanal'           => $kanal,
            'mesaj'           => $mesaj,
            'detay'           => $detay,
            'gonderim_tarihi' => now(),
        ]);
    }
}
