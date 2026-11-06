<?php

namespace App\Services\SistemYonetim\Salon;

use App\Domain\SistemYonetim\UyelikKurallari;
use App\Exceptions\GecersizIslemException;
use App\Models\SistemYoneticisi;
use App\Services\SistemYonetim\Denetim;
use App\Services\SistemYonetim\SistemBildirimServisi;
use App\Support\ZamanliKayit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** Uyelik suresi: gun/tarih uzatma, paketli uzatma + tahsilat kaydi + yetkili bildirimi, lisans ac/kapat. */
class SalonUyelikServisi
{
    public function __construct(private SistemBildirimServisi $bildirim, private SalonIletisim $iletisim) {}

    /** @return array{yeni_bitis:string,mesaj:string} */
    public function sureUzat(object $salon, ?int $gun, ?string $tarih): array
    {
        if ($tarih === null && ! $gun) {
            throw new GecersizIslemException('Uzatma için bir gün seçin ya da tarih girin.');
        }
        $eski = $salon->uyelik_bitis_tarihi;
        $yeni = $tarih ?? UyelikKurallari::gunUzat($eski, $gun, $this->bugun());
        $this->bitisYaz((int) $salon->id, ['uyelik_bitis_tarihi' => $yeni]);

        Denetim::yaz('salon_sure_uzat', 'salon', (int) $salon->id, $salon->salon_adi, $tarih !== null ? "Tarihe ayarlandı: {$yeni}" : "+{$gun} gün", ['eski' => $eski, 'yeni' => $yeni]);

        return ['yeni_bitis' => $yeni, 'mesaj' => 'Demo/üyelik süresi güncellendi. Yeni bitiş tarihi: '.$this->trTarih($yeni)];
    }

    /**
     * Salon guncellemesi ve tahsilat satiri birlikte yazilir; bildirim commit'ten sonra gider.
     *
     * @param  array{paket:string,periyot:string,adet:int,hediye_ay:int,ucret:float,kdv_dahil:bool}  $g
     * @return array{yeni_bitis:string,mesaj:string,bildirim:string}
     */
    public function paketliUzat(object $salon, array $g, SistemYoneticisi $yonetici): array
    {
        $toplamAy = UyelikKurallari::toplamAy($g['periyot'], $g['adet'], $g['hediye_ay']);
        $periyotEtiket = UyelikKurallari::PERIYOTLAR[$g['periyot']];

        [$eski, $yeni] = DB::transaction(function () use ($salon, $g, $toplamAy, $periyotEtiket, $yonetici) {
            $eski = DB::table('salonlar')->where('id', $salon->id)->lockForUpdate()->value('uyelik_bitis_tarihi');
            $yeni = UyelikKurallari::ayUzat($eski, $toplamAy, $this->bugun());
            $this->bitisYaz((int) $salon->id, ['demo_hesabi' => 0, 'uyelik_turu' => UyelikKurallari::PAKETLER[$g['paket']], 'uyelik_bitis_tarihi' => $yeni]);
            ZamanliKayit::ekle('salon_uyelik_odemeleri', [
                'salon_id'   => $salon->id,
                'paket'      => $g['paket'],
                'periyot'    => $g['periyot'],
                'adet'       => $g['adet'],
                'hediye_ay'  => $g['hediye_ay'],
                'toplam_ay'  => $toplamAy,
                'ucret'      => $g['ucret'],
                'kdv_dahil'  => $g['kdv_dahil'] ? 1 : 0,
                'eski_tarih' => UyelikKurallari::gecerliMi($eski) ? substr((string) $eski, 0, 10) : null,
                'yeni_tarih' => $yeni,
                'yapan_id'   => $yonetici->id,
                'yapan_adi'  => $yonetici->name,
                'aciklama'   => $g['paket'].' / '.$periyotEtiket.' x'.$g['adet'].($g['hediye_ay'] ? ' + '.$g['hediye_ay'].' ay hediye' : ''),
            ]);

            return [$eski, $yeni];
        });

        Denetim::yaz('salon_paketli_uzat', 'salon', (int) $salon->id, $salon->salon_adi,
            $g['paket'].' / '.$periyotEtiket.' ('.$toplamAy.' ay) — '.$this->tutar($g['ucret']).' ₺',
            ['eski' => $eski, 'yeni' => $yeni, 'paket' => $g['paket'], 'periyot' => $g['periyot'], 'adet' => $g['adet'], 'hediye_ay' => $g['hediye_ay'], 'ucret' => $g['ucret']]);

        $bildirim = $this->yetkiliyeBildir($salon, $g, $toplamAy, $periyotEtiket, $yeni);

        return [
            'yeni_bitis' => $yeni,
            'bildirim'   => $bildirim,
            'mesaj'      => 'Üyelik uzatıldı ✓  '.$g['paket'].' / '.$periyotEtiket.' — yeni bitiş: '.$this->trTarih($yeni).'. Yetkiliye bilgilendirme: '.$bildirim.'.',
        ];
    }

    /** Demo salonu lisansliya cevirir. @return array{yeni_bitis:string,mesaj:string} */
    public function lisansAktif(object $salon, int $gun): array
    {
        $yeni = UyelikKurallari::gunUzat($salon->uyelik_bitis_tarihi, $gun, $this->bugun());
        $this->bitisYaz((int) $salon->id, ['demo_hesabi' => 0, 'uyelik_turu' => UyelikKurallari::LISANS_KADEMESI, 'uyelik_bitis_tarihi' => $yeni]);

        Denetim::yaz('salon_lisans_aktif', 'salon', (int) $salon->id, $salon->salon_adi, "+{$gun} gün lisans", [
            'eski' => ['demo_hesabi' => $salon->demo_hesabi, 'uyelik_turu' => $salon->uyelik_turu, 'uyelik_bitis_tarihi' => $salon->uyelik_bitis_tarihi],
            'yeni_bitis' => $yeni,
        ]);

        return ['yeni_bitis' => $yeni, 'mesaj' => 'Lisans aktif edildi ✓  Bitiş tarihi: '.$this->trTarih($yeni)];
    }

    /** Bitis dune cekilir: salon lisans bitti ekranina duser. */
    public function lisansKapat(object $salon): array
    {
        $yeni = now()->subDay()->toDateString();
        $this->bitisYaz((int) $salon->id, ['uyelik_bitis_tarihi' => $yeni]);

        Denetim::yaz('salon_lisans_kapat', 'salon', (int) $salon->id, $salon->salon_adi, 'Lisans kapatıldı/kısıtlandı', ['eski_bitis' => $salon->uyelik_bitis_tarihi, 'yeni_bitis' => $yeni]);

        return ['yeni_bitis' => $yeni, 'mesaj' => 'Lisans kapatıldı — salon artık "lisans süreniz bitmiştir" ekranını görecek. Tekrar açmak için süreyi uzatın.'];
    }

    private function yetkiliyeBildir(object $salon, array $g, int $toplamAy, string $periyotEtiket, string $yeni): string
    {
        $telefon = $this->iletisim->bildirimTelefonu($salon);
        if ($telefon === '') {
            return 'telefon yok';
        }
        $mesaj = "🎉 Üyeliğiniz uzatıldı\n"
            .($salon->salon_adi ? $salon->salon_adi."\n" : '')
            ."Paket: {$g['paket']} ({$periyotEtiket})\n"
            ."Süre: {$toplamAy} ay".($g['hediye_ay'] ? " ({$g['hediye_ay']} ay hediye dahil)" : '')."\n"
            .'Tutar: '.($g['kdv_dahil'] ? 'KDV dahil ' : '').$this->tutar($g['ucret'])." ₺\n"
            .'Yeni bitiş tarihi: '.$this->trTarih($yeni)."\n"
            .'Bizi tercih ettiğiniz için teşekkürler. 🙏';

        try {
            return $this->bildirim->gonderTekil($telefon, $mesaj)['ok'] ? 'gönderildi' : 'gönderilemedi';
        } catch (\Throwable $e) {
            Log::warning('[PaketliUzat] bildirim hata', ['salon_id' => $salon->id, 'hata' => $e->getMessage()]);

            return 'hata';
        }
    }

    private function bitisYaz(int $salonId, array $alanlar): void
    {
        DB::table('salonlar')->where('id', $salonId)->update($alanlar + ['updated_at' => now()]);
    }

    private function bugun(): string
    {
        return now()->toDateString();
    }

    private function trTarih(string $ymd): string
    {
        return date('d.m.Y', strtotime($ymd));
    }

    private function tutar(float $ucret): string
    {
        return number_format($ucret, 2, ',', '.');
    }
}
