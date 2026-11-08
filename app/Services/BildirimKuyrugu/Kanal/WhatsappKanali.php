<?php

namespace App\Services\BildirimKuyrugu\Kanal;

use App\Domain\Whatsapp\WhatsappDurumu;
use App\Services\Whatsapp\Kopru\WhatsmeowKoprusu;
use App\Services\Whatsapp\WhatsappKontorServisi;
use App\Services\Whatsapp\WhatsappOturumServisi;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * WhatsApp gonderimi; eski App\Services\WhatsAppService ile birebir: paylasilan oturum, kara liste, gunluk tavan, mesai,
 * kontor, saglayici secimi, whatsapp_gonderim_loglari. Eski router gibi tum salonlar whatsmeow koprusunden gider. Log satiri
 * mesaji gonderen salona yazilir, salon kontrolleri oturum sahibi salonda yapilir. Hata firlatmaz, sonucu dondurur:
 * ['ok'=>bool, 'error'?=>kod, 'logId'?=>int, 'queued'?=>bool, 'provider'?=>string].
 *
 * Kuyruga alinan mesajin sonucu kopruden webhook ile gelir: webhook eski monolite gider ve SMS yedegini orada tetikler.
 *
 * Eskiden gelen ve KORUNAN hata: kara liste oturum sahibi salonda sorgulanir; paylasilan oturumlu alt salonun kendi kara
 * listesi WhatsApp'ta dikkate alinmaz.
 */
class WhatsappKanali
{
    /** Kontor dusmeyen tipler (eski KONTOR_UCRETSIZ_TIPLER). */
    private const KONTOR_UCRETSIZ_TIPLER = [
        'randevu_hatirlatma_1gun',
        'randevu_hatirlatma',
        'yeni_randevu_bildirim',
        'guncelleme_bildirim',
        'iptal_bildirim',
        'personel_hatirlatma',
        'randevu_onay_bildirim',
        'randevu_bilgi',
        'sifre_sifirlama',
        'ders_hatirlatma',
        'ders_iptal',
        'ders_bekleme_terfi',
        'ders_bildirim',
        'ders_kalan_uyari',
    ];

    /** Gunluk tavandan muaf guvenlik mesajlari (eski DAILY_CAP_MUAF_TIPLER). */
    private const TAVAN_MUAF_TIPLER = ['sifre_sifirlama', 'geldi_dogrulama_kodu', 'cark_kodu'];

    /** Oturumun koptugunu gosteren kopru hatalari. */
    private const OTURUM_KOPUK_ISARETLERI = ['unauthorized', 'loggedout', 'logged-out', 'logged_out', 'session-not-found', 'no-session', 'session-missing', 'not-authenticated'];

    public function __construct(
        private WhatsmeowKoprusu $kopru,
        private WhatsappCloudIstemcisi $cloud,
        private WhatsappOturumServisi $oturum,
        private WhatsappKontorServisi $kontor,
    ) {}

    /** Kanala verilecek salon satiri (tum kolonlar). */
    public function salon(int|string|null $salonId): ?object
    {
        return DB::table('salonlar')->where('id', $salonId)->first();
    }

    /** Mesajin gittigi oturumun salonu; paylasilan salon silinmisse kendisi (eski resolveWaSalon). */
    public function oturumSalonu(object $salon): object
    {
        $sahipId = WhatsappDurumu::oturumSalonId((int) $salon->id, $salon->whatsapp_paylasilan_salon_id ?? null);

        return $sahipId !== (int) $salon->id ? ($this->salon($sahipId) ?? $salon) : $salon;
    }

    /** Eski cagri yerlerinin on kontrolu: aktif + connected. Paylasilan oturum icin oturumSalonu() sonucu verilir. */
    public function bagliMi(object $oturumSalonu): bool
    {
        return ! empty($oturumSalonu->whatsapp_aktif) && ($oturumSalonu->whatsapp_durum ?? null) === 'connected';
    }

    /** Saglayiciya gore kanal acik mi: cloud_api'de kimlik bilgileri, digerinde aktif + connected. Oturum sahibi salon verilir. */
    public function kanalAcikMi(object $oturumSalonu): bool
    {
        return ($oturumSalonu->whatsapp_saglayici ?? 'baileys') === 'cloud_api'
            ? ! empty($oturumSalonu->cloud_api_token) && ! empty($oturumSalonu->cloud_api_phone_number_id)
            : $this->bagliMi($oturumSalonu);
    }

    /**
     * Eski sendReminder.
     *
     * @param  array{key?:string, params?:array}|null  $sablon  Cloud API: key 1gun|yaklasan|iptal|guncelleme
     */
    public function gonder(object $salon, mixed $telefon, string $mesaj, int|string|null $randevuId = null, int|string|null $userId = null, ?array $sablon = null, bool $acil = false, ?string $gonderimTipi = null): array
    {
        $kaynakSalonId = (int) $salon->id;
        $salon = $this->oturumSalonu($salon);

        $normal = KanalTelefonu::normalize($telefon);
        if (! $normal) {
            Log::warning('[WA] invalid-phone', ['salon_id' => $salon->id, 'telefon' => $telefon]);

            return ['ok' => false, 'error' => 'invalid-phone'];
        }
        if (KaraListeFiltresi::engelliMi($salon->id, $normal, $userId ? (int) $userId : null, $gonderimTipi)) {
            return ['ok' => false, 'error' => 'kara-liste'];
        }
        if (! ($gonderimTipi && in_array($gonderimTipi, self::TAVAN_MUAF_TIPLER, true)) && ! $this->bugunGonderebilir($salon)) {
            return ['ok' => false, 'error' => 'daily-cap-reached'];
        }
        if (! $acil && ! $this->oturum->mesaiIcindeMi()) {
            return ['ok' => false, 'error' => 'outside-business-hours'];
        }
        if (! $this->ucretsizMi($gonderimTipi) && ! $this->kontor->gonderimIcinYeterliMi($salon)) {
            return ['ok' => false, 'error' => 'kontor-yetersiz'];
        }

        return ($salon->whatsapp_saglayici ?? 'baileys') === 'cloud_api'
            ? $this->cloudIle($salon, $normal, $mesaj, $randevuId, $userId, $sablon, $gonderimTipi, $kaynakSalonId)
            : $this->kopruIle($salon, $normal, $mesaj, $randevuId, $userId, $acil, $gonderimTipi, $kaynakSalonId);
    }

    /**
     * Mesajlari tek tek WhatsApp'la dener, gidemeyenleri doner (SMS'e duserler). Bir mesajin istisnasi yalniz o mesaji SMS'e
     * dusurur: WhatsApp'a girmis mesaj ikinci kez SMS olarak gitmez. Alicisi ya da metni bos mesaj aynen doner.
     *
     * @param  callable(array):array  $gonder  tek mesaj; sonuc ['ok' => bool, ...]
     */
    public function gonderilemeyenler(array $mesajlar, callable $gonder, string $etiket): array
    {
        $kalan = [];
        foreach ($mesajlar as $m) {
            if (empty($m['to']) || empty($m['message'])) {
                $kalan[] = $m;

                continue;
            }
            try {
                $sonuc = $gonder($m);
            } catch (\Throwable $e) {
                Log::warning('['.$etiket.'] istisna, mesaj SMS\'e dusuyor', ['err' => $e->getMessage()]);
                $kalan[] = $m;

                continue;
            }
            if (! ($sonuc['ok'] ?? false)) {
                $kalan[] = $m;
            }
        }

        return $kalan;
    }

    /** Eski sendUrgent: sifre/OTP; antiban gecikmesi ve mesai atlanir. */
    public function acilGonder(object $salon, mixed $telefon, string $mesaj, int|string|null $userId = null, string $gonderimTipi = 'sifre_kodu'): array
    {
        return $this->gonder($salon, $telefon, $mesaj, null, $userId, null, true, $gonderimTipi);
    }

    private function kopruIle(object $salon, string $telefon, string $mesaj, mixed $randevuId, mixed $userId, bool $acil, ?string $gonderimTipi, int $kaynakSalonId): array
    {
        $logId = $this->logBekliyor($kaynakSalonId, $userId, $randevuId, $telefon, $mesaj, 'baileys', $gonderimTipi);

        $baslangic = microtime(true);
        $cevap = $this->kopru->gonderimIstegi($salon->id, [
            'to'          => $telefon,
            'message'     => $mesaj,
            'warmupStart' => $this->iso($salon->whatsapp_warmup_baslangic ?? null) ?: $this->iso($salon->whatsapp_baglanti_tarihi ?? null),
            'dailyLimit'  => $this->gunlukLimit($salon),
            'logId'       => $logId,
            'urgent'      => $acil,
        ]);
        Log::info('[WA] http istek', ['salon_id' => $salon->id, 'status' => $cevap->kod, 'sure_ms' => (int) ((microtime(true) - $baslangic) * 1000)]);

        // 202 = kuyruga alindi; sonuc webhook ile gelir.
        if ($cevap->kod === 202) {
            if (! $this->ucretsizMi($gonderimTipi)) {
                $this->kontor->gonderimdenDus($salon, 1, 'whatsapp:'.($gonderimTipi ?: 'mesaj'));
            }

            return ['ok' => true, 'queued' => true, 'logId' => $logId, 'provider' => 'baileys'];
        }

        $hata = ! $cevap->ulasildi() ? 'service-unreachable' : ($cevap->hata() ?: 'unknown');
        $this->logHatali($logId, $hata);
        $this->oturumKopukIsaretle($salon, $hata);

        return ['ok' => false, 'error' => $hata, 'status' => $cevap->kod, 'logId' => $logId, 'provider' => 'baileys'];
    }

    private function cloudIle(object $salon, string $telefon, string $mesaj, mixed $randevuId, mixed $userId, ?array $sablon, ?string $gonderimTipi, int $kaynakSalonId): array
    {
        $logId = $this->logBekliyor($kaynakSalonId, $userId, $randevuId, $telefon, $mesaj, 'cloud_api', $gonderimTipi);

        $anahtar = $sablon['key'] ?? null;
        $sablonAdi = $anahtar && in_array($anahtar, ['1gun', 'yaklasan', 'iptal', 'guncelleme'], true) ? ($salon->{'cloud_api_template_'.$anahtar} ?? null) : null;
        if (! $sablonAdi) {
            $this->logHatali($logId, 'cloud-template-not-configured');

            return ['ok' => false, 'error' => 'cloud-template-not-configured', 'logId' => $logId, 'provider' => 'cloud_api'];
        }

        $cevap = $this->cloud->sablonGonder($salon, $telefon, $sablonAdi, $sablon['params'] ?? []);
        if ($cevap['ok']) {
            DB::table('whatsapp_gonderim_loglari')->where('id', $logId)->update([
                'durum' => 1, 'mesaj_id' => $cevap['messageId'] ?? null, 'gonderim_tarihi' => now(), 'updated_at' => now(),
            ]);
            if (! $this->ucretsizMi($gonderimTipi)) {
                $this->kontor->gonderimdenDus($salon, 1, 'whatsapp:'.($gonderimTipi ?: 'mesaj'));
            }

            return ['ok' => true, 'queued' => false, 'logId' => $logId, 'messageId' => $cevap['messageId'], 'provider' => 'cloud_api'];
        }

        $hata = $cevap['error'] ?? 'unknown';
        $this->logHatali($logId, $hata);

        return ['ok' => false, 'error' => $hata, 'status' => $cevap['status'] ?? 0, 'logId' => $logId, 'provider' => 'cloud_api'];
    }

    /** Eski canSendToday. */
    private function bugunGonderebilir(object $salon): bool
    {
        if (! $this->kanalAcikMi($salon)) {
            return false;
        }
        if (($salon->whatsapp_saglayici ?? 'baileys') === 'cloud_api') {
            return true;
        }
        $limit = $this->gunlukLimit($salon);

        return $limit > 0 && $this->oturum->bugunGonderilen((int) $salon->id) < $limit;
    }

    private function gunlukLimit(object $salon): int
    {
        return (int) ($salon->whatsapp_gunluk_limit ?: config('whatsapp.default_daily_limit', 200));
    }

    private function ucretsizMi(?string $gonderimTipi): bool
    {
        return in_array($gonderimTipi, self::KONTOR_UCRETSIZ_TIPLER, true);
    }

    private function iso(?string $zaman): ?string
    {
        return $zaman ? Carbon::parse($zaman)->toIso8601String() : null;
    }

    /**
     * Kopru "yetkisiz/oturum yok" ya da Meta 463 donduyse salonu disconnected yapar; yoksa panel bagli gorunur, her mesajda
     * bosuna WhatsApp denenir. Nesne de guncellenir: ayni dongudeki sonraki mesajlar eskideki gibi kanali kapali gorur.
     */
    private function oturumKopukIsaretle(object $salon, string $hata): void
    {
        $kucuk = strtolower($hata);
        $koptu = str_contains($kucuk, '463') || str_contains($kucuk, 'error in ack');
        foreach (self::OTURUM_KOPUK_ISARETLERI as $isaret) {
            $koptu = $koptu || str_contains($kucuk, $isaret);
        }
        if (! $koptu || in_array((string) ($salon->whatsapp_durum ?? ''), ['disconnected', 'banned-or-loggedout', 'auto-paused-ban-risk'], true)) {
            return;
        }

        $salon->whatsapp_durum = 'disconnected';
        $salon->whatsapp_son_hata = substr('Bridge: '.$hata, 0, 120);
        DB::table('salonlar')->where('id', $salon->id)->update([
            'whatsapp_durum' => $salon->whatsapp_durum, 'whatsapp_son_hata' => $salon->whatsapp_son_hata, 'updated_at' => now(),
        ]);
        Log::warning('[WA] oturum yetkisiz tespit edildi, salon disconnected isaretlendi', ['salon_id' => $salon->id, 'error' => $hata]);
    }

    private function logBekliyor(int $salonId, mixed $userId, mixed $randevuId, string $telefon, string $mesaj, string $saglayici, ?string $gonderimTipi): int
    {
        $satir = [
            'salon_id' => $salonId, 'user_id' => $userId, 'randevu_id' => $randevuId, 'telefon' => $telefon, 'mesaj' => $mesaj,
            'durum' => 0, 'created_at' => now(), 'updated_at' => now(),
        ];
        if (Schema::hasColumn('whatsapp_gonderim_loglari', 'provider')) {
            $satir['provider'] = $saglayici;
        }
        if ($gonderimTipi !== null && Schema::hasColumn('whatsapp_gonderim_loglari', 'gonderim_tipi')) {
            $satir['gonderim_tipi'] = $gonderimTipi;
        }

        return (int) DB::table('whatsapp_gonderim_loglari')->insertGetId($satir);
    }

    private function logHatali(int $logId, string $hata): void
    {
        DB::table('whatsapp_gonderim_loglari')->where('id', $logId)->update(['durum' => 2, 'hata' => substr($hata, 0, 150), 'updated_at' => now()]);
    }
}
