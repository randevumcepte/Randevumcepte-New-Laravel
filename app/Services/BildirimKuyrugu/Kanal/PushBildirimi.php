<?php

namespace App\Services\BildirimKuyrugu\Kanal;

use App\Support\Sema;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Tek noktadan FCM gonderimi + bildirimler tablosu + token bakimi; eski App\Services\NotificationService ile birebir.
 * Metot eslesmesi: toCustomer→musteriye, toStaff→personele, toOwner→yetkiliye, forTokens→tokenlara, type→tip, title→baslik,
 * body→govde, image→gorsel, popup, randevu, deepLink, extra→ekstra, karaListeMuaf, firebaseFile→firebaseDosyasi, send→gonder.
 *
 *   PushBildirimi::musteriye($userId, $salonId)->tip(BildirimTipleri::APPOINTMENT_APPROVED)->baslik('...')->govde('...')
 *       ->deepLink('appointment_detail', ['randevu_id' => $id])->gonder();
 */
class PushBildirimi
{
    private const TOKEN_TABLOSU = 'bildirim_kimlikleri';

    private const GECERSIZ_TOKEN_KALIPLARI = [
        'UNREGISTERED',
        'NOT_FOUND',
        'INVALID_ARGUMENT',
        'registration-token-not-registered',
        'invalid-registration-token',
        'not a valid FCM registration token',
        'not a valid registration token',
        'SENDER_ID_MISMATCH',
    ];

    private string $kullaniciTipi;

    private ?int $userId = null;

    private ?int $personelId = null;

    private ?int $yetkiliId = null;

    private ?int $salonId = null;

    private ?int $randevuId = null;

    private ?array $hamTokenlar = null;

    private string $tip = BildirimTipleri::SYSTEM_ANNOUNCEMENT;

    private string $baslik = '';

    private string $govde = '';

    private ?string $gorselUrl = null;

    private ?string $deepLinkRota = null;

    private array $deepLinkParametre = [];

    private array $ekstra = [];

    private bool $popup = false;

    private bool $karaListeMuaf = false;

    private ?string $firebaseDosyasi;

    private function __construct(string $kullaniciTipi, ?int $salonId)
    {
        $this->kullaniciTipi = $kullaniciTipi;
        $this->salonId = $salonId;
        $this->firebaseDosyasi = config('firebase_projects.default');
    }

    public static function musteriye(int $userId, ?int $salonId = null): self
    {
        $i = new self('musteri', $salonId);
        $i->userId = $userId;

        return $i;
    }

    public static function personele(int $personelId, ?int $salonId = null): self
    {
        $i = new self('personel', $salonId);
        $i->personelId = $personelId;

        return $i;
    }

    public static function yetkiliye(int $yetkiliId, ?int $salonId = null): self
    {
        $i = new self('yetkili', $salonId);
        $i->yetkiliId = $yetkiliId;

        return $i;
    }

    /** Dogrudan token listesine; kullanici aranmaz, gecersizler elenir. */
    public static function tokenlara(array $tokenlar, ?int $salonId = null): self
    {
        $i = new self('raw', $salonId);
        $i->hamTokenlar = array_values(array_unique(array_filter($tokenlar, fn ($t) => is_string($t) && trim($t) !== '')));

        return $i;
    }

    public function tip(string $tip): self
    {
        $this->tip = $tip;

        return $this;
    }

    public function baslik(string $baslik): self
    {
        $this->baslik = $baslik;

        return $this;
    }

    public function govde(string $govde): self
    {
        $this->govde = $govde;

        return $this;
    }

    public function gorsel(?string $url): self
    {
        $this->gorselUrl = $url;

        return $this;
    }

    public function popup(bool $acik = true): self
    {
        $this->popup = $acik;

        return $this;
    }

    public function randevu(?int $id): self
    {
        $this->randevuId = $id;

        return $this;
    }

    public function firebaseDosyasi(string $dosya): self
    {
        $this->firebaseDosyasi = $dosya;

        return $this;
    }

    /** Kara liste suzgecinden muaf: kara liste bilgisi, sifre teslimi, OTP gibi musterinin kendi aksiyonuyla iliskili push'lar. */
    public function karaListeMuaf(bool $acik = true): self
    {
        $this->karaListeMuaf = $acik;

        return $this;
    }

    public function deepLink(string $rota, array $parametre = []): self
    {
        $this->deepLinkRota = $rota;
        $this->deepLinkParametre = $parametre;

        return $this;
    }

    public function ekstra(array $veri): self
    {
        $this->ekstra = array_merge($this->ekstra, $veri);

        return $this;
    }

    /**
     * Uygun cihazlara yollar, bildirimler tablosuna yazar, basarisiz token'lari pasiflestirir.
     *
     * @return array{sent:int, failed:int, total:int}
     */
    public function gonder(): array
    {
        $this->firebaseProfiliniCoz();

        if (! $this->karaListeMuaf && $this->kullaniciTipi === 'musteri' && $this->userId && $this->salonId
            && KaraListeFiltresi::engelliMi($this->salonId, null, $this->userId, null)) {
            Log::info('[PUSH] kara liste — gonderim atlandi', ['user_id' => $this->userId, 'salon_id' => $this->salonId, 'type' => $this->tip]);

            return ['sent' => 0, 'failed' => 0, 'total' => 0];
        }

        $satirlar = $this->tokenleriBul();
        $gonderilen = 0;
        $hatali = 0;

        // Web token'i daima web projesinden (default); mobil token once kendi app_bundle'ina, yoksa salon projesine gider.
        $mobilDosya = $this->firebaseDosyasi;
        $webDosya = config('firebase_projects.default', $mobilDosya);
        $bundleHaritasi = (array) config('firebase_bundle_projects', []);

        $deepLink = $this->deepLinkOlustur();
        $veri = array_merge([
            'type'       => $this->tip,
            'deep_link'  => $deepLink,
            'popup'      => $this->popup ? '1' : '0',
            'image'      => $this->gorselUrl ?? '',
            'salon_id'   => (string) ($this->salonId ?? ''),
            'randevu_id' => (string) ($this->randevuId ?? ''),
        ], array_map(fn ($v) => is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE), $this->ekstra));

        $fcm = app(FcmIstemcisi::class);
        foreach ($satirlar as $satir) {
            try {
                $bundle = $satir->app_bundle ?? '';
                $dosya = $satir->platform === 'web'
                    ? $webDosya
                    : ((! empty($bundle) && ! empty($bundleHaritasi[$bundle])) ? $bundleHaritasi[$bundle] : $mobilDosya);

                $fcm->gonder($dosya, $this->mesaj($satir->bildirim_id, $veri));
                $gonderilen++;
                DB::table(self::TOKEN_TABLOSU)->where('id', $satir->id)->update([
                    'son_kullanim_tarihi' => now(),
                    'gonderim_hatalari'   => 0,
                    'updated_at'          => now(),
                ]);
            } catch (FirebaseAnahtariYok $e) {
                throw $e;
            } catch (\Throwable $e) {
                $hatali++;
                Log::warning('NotificationService send fail', ['token_id' => $satir->id, 'err' => $e->getMessage()]);
                $this->tokenHatasiniIsle($satir, $e->getMessage());
            }
        }

        $this->bildirimlereYaz($deepLink);

        return ['sent' => $gonderilen, 'failed' => $hatali, 'total' => count($satirlar)];
    }

    /** White-label marka izolasyonu: salonun app_bundle'i varsa yalniz o uygulamanin cihazlari. */
    public static function markaSuz(Builder $sorgu, int|string|null $salonId): Builder
    {
        if (empty($salonId)) {
            return $sorgu;
        }
        $bundle = DB::table('salonlar')->where('id', $salonId)->value('app_bundle');

        return empty($bundle) ? $sorgu : $sorgu->where('app_bundle', $bundle);
    }

    /** FCM hata metni token'in gecersiz oldugunu mu soyluyor? (eski NotificationService ve BildirimController ortak kaliplari) */
    public static function gecersizTokenHatasiMi(string $hata): bool
    {
        foreach (self::GECERSIZ_TOKEN_KALIPLARI as $kalip) {
            if (stripos($hata, $kalip) !== false) {
                return true;
            }
        }

        return false;
    }

    private function tokenHatasiniIsle(object $satir, string $hata): void
    {
        if (self::gecersizTokenHatasiMi($hata)) {
            DB::table(self::TOKEN_TABLOSU)->where('id', $satir->id)->delete();

            return;
        }
        $hataSayisi = ($satir->gonderim_hatalari ?? 0) + 1;
        DB::table(self::TOKEN_TABLOSU)->where('id', $satir->id)->update([
            'gonderim_hatalari' => $hataSayisi,
            'aktif'             => $hataSayisi < 5 ? 1 : 0,
            'updated_at'        => now(),
        ]);
    }

    /** Salonun firebase_profile'i varsa onun dosyasi; bos ya da tanimsizsa varsayilan kalir. */
    private function firebaseProfiliniCoz(): void
    {
        if (! $this->salonId) {
            return;
        }
        try {
            $profil = DB::table('salonlar')->where('id', $this->salonId)->value('firebase_profile');
            if (empty($profil)) {
                return;
            }
            $yol = config("firebase_projects.{$profil}");
            if (empty($yol)) {
                Log::warning('NotificationService: bilinmeyen firebase_profile', ['salon_id' => $this->salonId, 'profile' => $profil]);

                return;
            }
            $this->firebaseDosyasi = $yol;
        } catch (\Throwable $e) {
            Log::warning('NotificationService: firebase_profile resolve hata', ['salon_id' => $this->salonId, 'err' => $e->getMessage()]);
        }
    }

    private function tokenleriBul(): \Illuminate\Support\Collection
    {
        if ($this->kullaniciTipi === 'raw') {
            if (empty($this->hamTokenlar)) {
                return collect();
            }

            return self::markaSuz(DB::table(self::TOKEN_TABLOSU)->whereIn('bildirim_id', $this->hamTokenlar)->where('aktif', 1)
                ->whereNotNull('bildirim_id')->where('bildirim_id', '!=', ''), $this->salonId)->get();
        }

        $q = DB::table(self::TOKEN_TABLOSU)->where('aktif', 1);
        if ($this->kullaniciTipi === 'musteri') {
            $q->where('user_id', $this->userId);
        } elseif ($this->kullaniciTipi === 'personel') {
            $q->where('isletme_yetkili_id', $this->personelId);
        } else {
            $q->where('isletme_yetkili_id', $this->yetkiliId);
        }

        return self::markaSuz($q, $this->salonId)->whereNotNull('bildirim_id')->where('bildirim_id', '!=', '')->get();
    }

    private function deepLinkOlustur(): ?string
    {
        if (! $this->deepLinkRota) {
            return null;
        }
        $parametre = http_build_query($this->deepLinkParametre);

        return $parametre ? "{$this->deepLinkRota}?{$parametre}" : $this->deepLinkRota;
    }

    /** Deep link rotasini web adresine cevirir (FCM webpush tiklamasi). */
    private function tiklamaAdresi(): string
    {
        $kok = rtrim((string) config('panel.eski_panel_url'), '/');
        $sube = isset($this->deepLinkParametre['salon_id']) ? '?sube='.$this->deepLinkParametre['salon_id'] : '';

        return match ($this->deepLinkRota) {
            'appointment_detail' => $kok.'/isletmeyonetim/randevular'.$sube,
            'wheel'              => $kok.'/isletmeyonetim/cark'.$sube,
            'campaign_detail'    => $kok.'/isletmeyonetim/kampanyalar'.$sube,
            'payment_received'   => $kok.'/isletmeyonetim/tahsilatlar'.$sube,
            'survey'             => $kok.'/isletmeyonetim/anketler'.$sube,
            default              => $kok.'/isletmeyonetim'.$sube,
        };
    }

    private function mesaj(string $token, array $veri): array
    {
        $yuksek = BildirimTipleri::isHighPriority($this->tip);

        $apns = [
            'headers' => ['apns-priority' => $yuksek ? '10' : '5'],
            'payload' => [
                'aps' => array_filter([
                    // iOS: ios/Runner/ring.caf bundle'da. Uzantili yazilir.
                    'sound'             => 'ring.caf',
                    'mutable-content'   => $this->gorselUrl ? 1 : null,
                    'content-available' => 1,
                ], fn ($v) => $v !== null),
            ],
        ];
        if ($this->gorselUrl) {
            $apns['fcm_options'] = ['image' => $this->gorselUrl];
        }

        $webpush = [
            'headers'      => ['Urgency' => $yuksek ? 'high' : 'normal'],
            'notification' => array_filter([
                'title' => $this->baslik,
                'body'  => $this->govde,
                'icon'  => $this->gorselUrl ?: '/public/img/logo.png',
                'image' => $this->gorselUrl,
                'tag'   => $this->tip,
            ]),
        ];
        if ($this->deepLinkOlustur()) {
            $webpush['fcm_options'] = ['link' => $this->tiklamaAdresi()];
        }

        return [
            'token'        => $token,
            'notification' => array_filter(['title' => $this->baslik, 'body' => $this->govde, 'image' => $this->gorselUrl]),
            'data'         => $veri,
            'android'      => [
                'priority'     => $yuksek ? 'HIGH' : 'NORMAL',
                'notification' => array_filter([
                    'channel_id' => $this->androidKanali(),
                    'image'      => $this->gorselUrl,
                    // Android: res/raw/ring.mp3 -> uzantisiz 'ring'. Android 8+ kanal sesini kullanir.
                    'sound'      => 'ring',
                ]),
            ],
            'apns'    => $apns,
            'webpush' => $webpush,
        ];
    }

    private function androidKanali(): string
    {
        if (BildirimTipleri::isPopup($this->tip)) {
            return 'rmc_promo_v2';
        }

        return BildirimTipleri::isHighPriority($this->tip) ? 'rmc_important_v2' : 'rmc_default_v2';
    }

    /** Ham token modunda her aliciya ayri satir; hic alici yoksa hedefsiz tek satir (eski davranis). */
    private function bildirimlereYaz(?string $deepLink): void
    {
        if ($this->kullaniciTipi !== 'raw') {
            $this->bildirimSatiri($deepLink, $this->userId, $this->personelId);

            return;
        }
        if (empty($this->hamTokenlar)) {
            return;
        }
        try {
            $satirlar = self::markaSuz(DB::table(self::TOKEN_TABLOSU)->whereIn('bildirim_id', $this->hamTokenlar)->where('aktif', 1), $this->salonId)
                ->get(['user_id', 'isletme_yetkili_id']);
            $kullanicilar = [];
            $personeller = [];
            foreach ($satirlar as $r) {
                if (! empty($r->user_id)) {
                    $kullanicilar[(int) $r->user_id] = true;
                } elseif (! empty($r->isletme_yetkili_id)) {
                    $personeller[(int) $r->isletme_yetkili_id] = true;
                }
            }
            if (! $kullanicilar && ! $personeller) {
                $this->bildirimSatiri($deepLink, null, null);

                return;
            }
            foreach (array_keys($kullanicilar) as $id) {
                $this->bildirimSatiri($deepLink, $id, null);
            }
            foreach (array_keys($personeller) as $id) {
                $this->bildirimSatiri($deepLink, null, $id);
            }
        } catch (\Throwable $e) {
            Log::warning('Bildirim log (raw) yazilamadi: '.$e->getMessage());
        }
    }

    /** Eski semalarda eksik kolon tum kaydi dusurmesin diye her alan kolon varsa yazilir. */
    private function bildirimSatiri(?string $deepLink, ?int $userId, ?int $personelId): void
    {
        try {
            $alanlar = [
                'salon_id'    => $this->salonId,
                'user_id'     => $userId,
                'personel_id' => $personelId,
                'aciklama'    => $this->govde,
                'url'         => $this->gorselUrl,
                'img_src'     => $this->gorselUrl,
                'tarih_saat'  => date('Y-m-d H:i:s'),
                'okundu'      => 0,
                'butonlar'    => json_encode([], JSON_UNESCAPED_UNICODE),
                'randevu_id'  => $this->randevuId,
                'baslik'      => $this->baslik,
                'tip'         => $this->tip,
                'deep_link'   => $deepLink,
                'image_url'   => $this->gorselUrl,
                'popup'       => $this->popup ? 1 : 0,
                'extra_data'  => json_encode($this->ekstra, JSON_UNESCAPED_UNICODE),
            ];
            $simdi = now();
            DB::table('bildirimler')->insert(array_filter($alanlar, fn ($kolon) => Sema::kolonVar('bildirimler', $kolon), ARRAY_FILTER_USE_KEY)
                + ['created_at' => $simdi, 'updated_at' => $simdi]);
        } catch (\Throwable $e) {
            Log::warning('Bildirim log yazılamadı: '.$e->getMessage());
        }
    }
}
