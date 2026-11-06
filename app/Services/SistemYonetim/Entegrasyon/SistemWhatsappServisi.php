<?php

namespace App\Services\SistemYonetim\Entegrasyon;

use App\Domain\Whatsapp\BaglantiDurumu;
use App\Exceptions\CakismaException;
use App\Exceptions\GecersizIslemException;
use App\Exceptions\HariciServisException;
use App\Services\SistemYonetim\Denetim;
use App\Services\SistemYonetim\SistemBildirimServisi;
use App\Services\Whatsapp\Kopru\WhatsappKoprusu;
use App\Services\Whatsapp\QrGorselUretici;

/**
 * Salon-bagimsiz sistem WhatsApp oturumu (eski PanelController::sistemWhatsapp*). Oturum yalniz whatsmeow koprusunde;
 * QR PNG sunucuda uretilir (eski ekran dizeyi api.qrserver.com'a gonderiyordu, oturum eslestirme sirridir).
 */
class SistemWhatsappServisi
{
    private const KOPRU_ULASILAMADI = 'WhatsApp servisine ulaşılamıyor. Birazdan tekrar deneyin.';

    private const ESLESTIRME_HATALARI = [
        'already-paired'    => 'Bu oturum zaten bağlı görünüyor. Önce "Oturumu Kapat" yapın.',
        'invalid-phone'     => 'Numara formatı geçersiz. 905XXXXXXXXX şeklinde girin.',
        'pairing-not-ready' => 'WhatsApp bağlantısı henüz hazır değil. Birkaç saniye bekleyip tekrar deneyin.',
    ];

    public function __construct(
        private WhatsappKoprusu $kopru,
        private SistemBildirimServisi $bildirim,
        private QrGorselUretici $qrGorsel,
    ) {}

    public function ayar(): array
    {
        $a = $this->bildirim->ayar();

        return [
            'numara'          => str_replace(',', ', ', $a['numara']),
            'gonderen_numara' => $a['gonderen_numara'],
            'aktif'           => $a['aktif'],
        ];
    }

    public function ayarKaydet(?string $alicilar, bool $aktif, ?string $gonderen): array
    {
        $this->bildirim->ayarYaz($alicilar, $aktif, $gonderen);
        Denetim::yaz('sistem_whatsapp_ayar', 'sistem_whatsapp', null, 'Sistem WhatsApp', 'Bildirim ayarı kaydedildi', ['aktif' => $aktif]);

        return $this->ayar();
    }

    public function durum(): array
    {
        $c = $this->kopru->durum($this->bildirim->oturumId());
        $kod = $c->ulasildi() ? BaglantiDurumu::koprudan($c->govde) : BaglantiDurumu::SERVIS_KAPALI;
        $telefon = trim((string) ($c->govde['phone'] ?? ''));
        $bagli = $kod === BaglantiDurumu::BAGLI;
        [$etiket, $renk] = match (true) {
            $bagli                                                 => ['Bağlı', 'yesil'],
            ! $c->ulasildi()                                       => ['Servise ulaşılamadı', 'kirmizi'],
            $kod === BaglantiDurumu::BAGLANIYOR                    => ['Bağlanıyor / QR bekleniyor…', 'turuncu'],
            ! empty($c->govde['hasQr']) || $kod === 'qr'           => ['QR hazır — okut', 'turuncu'],
            default                                                => ['Bağlı değil', 'turuncu'],
        };

        return [
            'status'      => $kod,
            'etiket'      => $etiket,
            'renk'        => $renk,
            'bagli'       => $bagli,
            'servis_acik' => $c->ulasildi(),
            'phone'       => $telefon !== '' ? $telefon : null,
            'uyari'       => $bagli ? $this->numaraUyarisi($telefon) : null,
        ];
    }

    /** @return array{qr:?string,teshis:?string} qr: PNG data URI; henuz yoksa null (istemci yoklamaya devam eder). */
    public function qr(): array
    {
        $c = $this->kopru->qr($this->bildirim->oturumId());
        $qr = (string) ($c->govde['qr'] ?? '');
        if ($qr === '') {
            return ['qr' => null, 'teshis' => $c->ulasildi() ? 'QR yok · http '.$c->kod.($c->hata() !== '' ? ' · '.$c->hata() : '') : 'QR isteği başarısız (servis).'];
        }

        return ['qr' => str_starts_with($qr, 'data:') ? $qr : $this->qrGorsel->dataUri($qr), 'teshis' => null];
    }

    public function baslat(): void
    {
        if (! $this->kopru->baslat($this->bildirim->oturumId())->ulasildi()) {
            throw new HariciServisException(self::KOPRU_ULASILAMADI);
        }
        Denetim::yaz('sistem_whatsapp_baslat', 'sistem_whatsapp', null, 'Sistem WhatsApp', 'Oturum başlatıldı');
    }

    /** Telefon numarasiyla eslestirme: kopru aktif oturum ister, baslatma burada. */
    public function eslestir(string $telefon): array
    {
        $oturum = $this->bildirim->oturumId();
        $rakam = (string) preg_replace('/\D/', '', $telefon);
        $this->kopru->baslat($oturum);
        $c = $this->kopru->eslestir($oturum, $rakam);
        if (! $c->ulasildi()) {
            throw new HariciServisException(self::KOPRU_ULASILAMADI);
        }
        $kod = strtoupper((string) preg_replace('/\s+/', '', (string) ($c->govde['code'] ?? '')));
        if (! $c->basarili() || $kod === '') {
            throw new CakismaException(self::ESLESTIRME_HATALARI[$c->hata()] ?? 'Kod alınamadı.');
        }
        Denetim::yaz('sistem_whatsapp_eslestir', 'sistem_whatsapp', null, 'Sistem WhatsApp', 'Telefonla eşleştirme kodu üretildi');

        return ['code' => $kod, 'gosterim' => strlen($kod) === 8 ? substr($kod, 0, 4).'-'.substr($kod, 4) : $kod];
    }

    /** Oturum kapanir ve yeni oturum kimligine gecilir: kopru eski kimligi saklasa da yeni baglantida yeni QR cikar. */
    public function cikis(): void
    {
        $this->kopru->cikis($this->bildirim->oturumId());
        $this->bildirim->yeniOturumId();
        Denetim::yaz('sistem_whatsapp_cikis', 'sistem_whatsapp', null, 'Sistem WhatsApp', 'Oturum sıfırlandı');
    }

    /** @return string Tek satir sonuc ozeti (alici basina WA/SMS). */
    public function testGonder(): string
    {
        $c = $this->kopru->durum($this->bildirim->oturumId());
        $telefon = (string) ($c->govde['phone'] ?? '');
        $durum = $c->ulasildi() ? ($c->govde['status'] ?? 'bilinmiyor') : 'servise-ulasilamiyor';
        if ($telefon !== '' && in_array(SistemBildirimServisi::normalize($telefon), $this->bildirim->alicilar(), true)) {
            throw new GecersizIslemException("Bağlı GÖNDEREN ({$telefon}) ile ALICI numaralarından biri aynı! WhatsApp kendine mesaj göndermez. Gönderen için ALICI listesinde OLMAYAN farklı bir numara bağla.");
        }

        $r = $this->bildirim->gonder('✅ Test: Sistem bildirimi. '.date('d.m.Y H:i'));
        if (empty($r['ok'])) {
            throw new GecersizIslemException('Gönderilmedi — önce numara girip "Bildirimler açık" işaretleyip Kaydet.');
        }
        $satirlar = [];
        foreach ($r['ozet'] ?? [] as $numara => $o) {
            $satirlar[] = preg_replace('/^90/', '', (string) $numara).': WA '.($o['wa'] ? '✓' : '✗').' | SMS '.($o['sms'] ? '✓' : '✗');
        }
        Denetim::yaz('sistem_whatsapp_test', 'sistem_whatsapp', null, 'Sistem WhatsApp', 'Test mesajı gönderildi');

        return 'Gönderen oturum: '.$durum.($telefon !== '' ? " ({$telefon})" : ' — NUMARA YOK/bağlı değil').' → '.implode('  ·  ', $satirlar);
    }

    /** Bagli numara aliciyla ayniysa ya da yazilan gonderenden farkliysa ekranda uyari. */
    private function numaraUyarisi(string $telefon): ?string
    {
        if ($telefon === '') {
            return null;
        }
        $norm = SistemBildirimServisi::normalize($telefon);
        if (in_array($norm, $this->bildirim->alicilar(), true)) {
            return 'Bağlanan (gönderen) numara ile ALICI numaran aynı. WhatsApp kendine mesaj göndermez — farklı bir numara bağla.';
        }
        $gonderen = SistemBildirimServisi::normalize($this->bildirim->ayar()['gonderen_numara']);
        if ($gonderen !== '' && $gonderen !== $norm) {
            return "Bağlanan numara ({$telefon}) yazdığın GÖNDEREN numaradan ({$gonderen}) farklı. Doğru telefonla bağla, ya da GÖNDEREN alanını güncelle.";
        }

        return null;
    }
}
