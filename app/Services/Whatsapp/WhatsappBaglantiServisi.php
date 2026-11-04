<?php

namespace App\Services\Whatsapp;

use App\Domain\Whatsapp\BaglantiDurumu;
use App\Exceptions\BulunamadiException;
use App\Exceptions\CakismaException;
use App\Exceptions\GecersizIslemException;
use App\Exceptions\HariciServisException;
use App\Services\Whatsapp\Kopru\KopruCevabi;
use App\Services\Whatsapp\Kopru\WhatsappKoprusu;
use App\Support\AktiviteLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;

/**
 * WhatsApp oturum yönetimi — eski StoreAdminController::whatsapp{Baslat,PairPhone,Durum,QR,Cikis}.
 * Web ve Flutter aynı akışı bu servisten alır; eski ekranda istemcide duran kurallar (başlatmadan
 * kod istenmez, "henüz hazır değil"de bir kez daha denenir) burada.
 *
 * Paylaşılan oturum (whatsapp_paylasilan_salon_id): durum üst salondan okunur ama alt salon
 * bağla / QR / çıkış YAPAMAZ — üst salonun oturumunu kapatırdı (409).
 */
class WhatsappBaglantiServisi
{
    private const MIN_TELEFON_HANE = 11;

    /** Köprünün eski oturum durumunu temizlemesi için bekleme. */
    private const CIKIS_BEKLEME_MS = 400;

    /** pairing-not-ready: WhatsApp el sıkışması ilk saniyelerde bitmemiş olabilir. */
    private const ESLESTIRME_BEKLEME_SN = 4;

    private const HATA_MESAJLARI = [
        'already-paired'    => 'Bu salon zaten bağlı görünüyor. Önce "Oturumu Kapat" yapın.',
        'invalid-phone'     => 'Numara formatı geçersiz. 905XXXXXXXXX şeklinde girin.',
        'pairing-not-ready' => 'WhatsApp bağlantısı henüz hazır değil. Birkaç saniye bekleyip tekrar deneyin.',
    ];

    public function __construct(
        private WhatsappKoprusu $kopru,
        private WhatsappOturumServisi $oturum,
        private QrGorselUretici $qrGorsel,
    ) {}

    /** Köprüdeki gerçek durum; köprü cevap verdiyse salonlar'a yansıtılır (eski panel de oradan okur). */
    public function durum(int $salonId): array
    {
        $o = $this->oturumBul($salonId);
        $oturumId = (int) $o['oturum']->id;
        $c = $this->kopru->durum($oturumId);
        $kod = $c->ulasildi() ? BaglantiDurumu::koprudan($c->govde) : BaglantiDurumu::SERVIS_KAPALI;

        if ($c->basarili()) {
            $this->durumuYansit($salonId, $o['oturum'], $kod, $c);
        }
        $telefon = trim((string) ($c->govde['phone'] ?? ''));

        return BaglantiDurumu::tanim($kod) + [
            'status'      => $kod,
            'phone'       => $telefon !== '' ? $telefon : null,
            // connected + numara birlikte "gerçekten bağlı": numarasız connected'ta bağla akışı açık kalır.
            'bagli'       => $kod === BaglantiDurumu::BAGLI && $telefon !== '',
            'lastError'   => ($c->govde['lastError'] ?? '') ?: null,
            'connectedAt' => $o['oturum']->whatsapp_baglanti_tarihi,
            'paylasilan'  => $o['paylasilan'],
        ];
    }

    /** @return array{qr:?string} qr: PNG data URI; henüz üretilmediyse null (istemci yoklamaya devam eder). */
    public function qr(int $salonId): array
    {
        $qr = (string) ($this->kopru->qr($this->kendiOturumu($salonId))->govde['qr'] ?? '');

        return ['qr' => $qr === '' ? null : $this->qrGorsel->dataUri($qr)];
    }

    public function baslat(int $salonId, int $yetkiliId): array
    {
        $oturumId = $this->kendiOturumu($salonId);
        $c = $this->oturumAc($oturumId);
        if (! $c->basarili()) {
            throw new HariciServisException('WhatsApp servisine ulaşılamıyor. Birazdan tekrar deneyin.');
        }
        DB::table('salonlar')->where('id', $oturumId)->update([
            'whatsapp_aktif'       => 1,
            'whatsapp_durum'       => BaglantiDurumu::koprudan($c->govde + ['status' => BaglantiDurumu::BAGLANIYOR]),
            'whatsapp_bridge_tipi' => 'whatsmeow',   // eski panelle aynı: bağlantılar yeni köprüden
        ]);
        AktiviteLog::yaz($salonId, $yetkiliId, 'whatsapp_baglanti_baslat', 'whatsapp', $oturumId, 'WhatsApp oturumu',
            'WhatsApp bağlantı oturumu başlatıldı (QR aşaması)');

        return $this->durum($salonId);
    }

    /** @return array{code:string} Telefondaki "Telefon numarasıyla bağla" ekranına girilecek kod. */
    public function eslestir(int $salonId, string $telefon): array
    {
        $oturumId = $this->kendiOturumu($salonId);
        $rakam = preg_replace('/\D/', '', $telefon);
        if (strlen($rakam) < self::MIN_TELEFON_HANE) {
            throw new GecersizIslemException('Numarayı başında 90 olacak şekilde girin (ör: 905321234567).');
        }

        // Köprüde oturum yoksa kod üretilemez; başlatma hatası eşleştirme cevabında zaten görünür.
        $this->oturumAc($oturumId);
        $c = $this->kopru->eslestir($oturumId, $rakam);
        if ($c->hata() === 'pairing-not-ready') {
            Sleep::for(self::ESLESTIRME_BEKLEME_SN)->seconds();
            $c = $this->kopru->eslestir($oturumId, $rakam);
        }
        if (! $c->ulasildi()) {
            throw new HariciServisException('WhatsApp servisine ulaşılamıyor. Birazdan tekrar deneyin.');
        }
        if (! $c->basarili() || empty($c->govde['code'])) {
            $mesaj = self::HATA_MESAJLARI[$c->hata()] ?? 'Kod üretilemedi.';
            throw $c->hata() === 'invalid-phone' ? new GecersizIslemException($mesaj) : new CakismaException($mesaj);
        }

        return ['code' => (string) $c->govde['code']];
    }

    public function cikis(int $salonId, int $yetkiliId): void
    {
        $oturumId = $this->kendiOturumu($salonId);
        $numara = DB::table('salonlar')->where('id', $salonId)->value('whatsapp_numara');
        $this->kopru->cikis($oturumId);   // köprüde oturum yoksa da salon kaydı temizlenir (eskiyle aynı)
        DB::table('salonlar')->where('id', $salonId)->update([
            'whatsapp_aktif'            => 0,
            'whatsapp_durum'            => BaglantiDurumu::CIKIS_YAPILDI,
            'whatsapp_numara'           => null,
            'whatsapp_baglanti_tarihi'  => null,
            'whatsapp_warmup_baslangic' => null,
        ]);
        AktiviteLog::yaz($salonId, $yetkiliId, 'whatsapp_baglanti_kapat', 'whatsapp', $salonId, $numara ?: 'WhatsApp oturumu',
            'WhatsApp oturumu sonlandırıldı', ['eski_numara' => $numara]);
    }

    /**
     * Akıllı başlat: gerçekten bağlı değilse önbellekteki eski kimlik silinir, taze QR üretilir
     * (aksi halde köprü eski kimlikle bağlanmayı deneyip QR üretmiyor, "Bağlı" görünüp numara boş kalıyordu).
     */
    private function oturumAc(int $oturumId): KopruCevabi
    {
        $d = $this->kopru->durum($oturumId);
        if (BaglantiDurumu::koprudan($d->govde) !== BaglantiDurumu::BAGLI || empty($d->govde['phone'])) {
            $this->kopru->cikis($oturumId);
            Sleep::for(self::CIKIS_BEKLEME_MS)->milliseconds();
        }

        return $this->kopru->baslat($oturumId);
    }

    private function durumuYansit(int $salonId, object $oturum, string $kod, KopruCevabi $c): void
    {
        $g = ['whatsapp_durum' => $kod];
        if ($kod === BaglantiDurumu::BAGLI) {
            if (! $oturum->whatsapp_baglanti_tarihi) {
                $g['whatsapp_baglanti_tarihi'] = $g['whatsapp_warmup_baslangic'] = now();
            }
            if (! empty($c->govde['phone'])) {
                $g['whatsapp_numara'] = $c->govde['phone'];
            }
        }
        DB::table('salonlar')->where('id', $oturum->id)->update($g);
        if ((int) $oturum->id !== $salonId) {
            // Alt salonun panelde/rozette "bağlı" görünmesi için durum yansıtılır.
            DB::table('salonlar')->where('id', $salonId)->update(['whatsapp_durum' => $kod]);
        }
    }

    /** Salonun kendi oturumu; paylaşılan oturumda 409 (üst salondan yönetilir). */
    private function kendiOturumu(int $salonId): int
    {
        $o = $this->oturumBul($salonId);
        if ($o['paylasilan']) {
            throw new CakismaException("Bu işletme {$o['paylasilan']['salon_adi']} işletmesinin WhatsApp oturumunu paylaşıyor. Bu işlem üst işletmeden yapılmalı.");
        }

        return $salonId;
    }

    private function oturumBul(int $salonId): array
    {
        return $this->oturum->coz($salonId) ?? throw new BulunamadiException('Salon bulunamadı');
    }
}
