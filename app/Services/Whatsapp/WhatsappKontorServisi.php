<?php

namespace App\Services\Whatsapp;

use App\Domain\Whatsapp\KontorDurumu;
use App\Domain\Whatsapp\KontorPaketleri;
use App\Domain\Whatsapp\KontorTalepMesaji;
use App\Exceptions\BulunamadiException;
use App\Exceptions\CakismaException;
use App\Exceptions\GecersizIslemException;
use App\Support\AktiviteLog;
use App\Support\BildirimKuyrugu;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kontör bakiyesi + paket kataloğu + satın alma talebi. Bakiye, mesajların gerçekte düştüğü
 * oturum sahibi salondan okunur (paylaşılan oturumda üst salon — WhatsappOturumServisi).
 *
 * Talep sistem sahibine bildirim olarak gider; v2 yan etki üretmez: outbox 'sistem_bildirim' →
 * eski monolitin SistemBildirim::gonder'i (sistem WhatsApp oturumu + SMS yedek). Eski ekrandaki
 * "bildirim yavaş, 12 sn sonra iyimser kapat" hilesi gerekmez; istek beklemez.
 */
class WhatsappKontorServisi
{
    public function __construct(private WhatsappOturumServisi $oturum) {}

    public function durum(int $salonId): array
    {
        $o = $this->oturum->coz($salonId) ?? throw new BulunamadiException('Salon bulunamadı');

        return KontorDurumu::olustur($o['durum'], $o['oturum']->whatsapp_deneme_bitis) + [
            'paketler'   => KontorPaketleri::liste(),
            'paylasilan' => $o['paylasilan'],
        ];
    }

    /**
     * Elle kontör (eski KontorServisi::yukle): pozitif yükler, negatif düşer. Bakiye + hareket tek işlemde.
     * Kolon koşullu: migration'ı koşmamış kurulumda yazılmaz.
     *
     * @return int yeni bakiye
     */
    public function yukle(int $salonId, int $adet, string $aciklama): int
    {
        if ($adet === 0) {
            throw new GecersizIslemException('Geçersiz kontör miktarı.');
        }
        if (! Schema::hasColumn('salonlar', 'whatsapp_kontor')) {
            throw new CakismaException('Kontör kolonu bu kurulumda henüz yok.');
        }

        return DB::transaction(function () use ($salonId, $adet, $aciklama) {
            DB::table('salonlar')->where('id', $salonId)->increment('whatsapp_kontor', $adet);
            $bakiye = (int) DB::table('salonlar')->where('id', $salonId)->value('whatsapp_kontor');
            DB::table('whatsapp_kontor_hareketleri')->insert([
                'salon_id'       => $salonId,
                'tip'            => $adet > 0 ? 'yukleme' : 'harcama',
                'adet'           => $adet,
                'bakiye_sonrasi' => $bakiye,
                'aciklama'       => mb_substr($aciklama, 0, 160),
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);

            return $bakiye;
        });
    }

    public function talep(int $salonId, int $yetkiliId, string $paketKodu, ?string $iletisim): void
    {
        $paket = KontorPaketleri::bul($paketKodu) ?? throw new GecersizIslemException('Geçersiz paket.');
        // select *: yetkili_* kolonları her kurulumda yok; yoklukta satır atlanır (500 olmaz).
        $salon = DB::table('salonlar')->where('id', $salonId)->first();

        DB::transaction(function () use ($salonId, $yetkiliId, $paket, $salon, $iletisim) {
            BildirimKuyrugu::yaz($salonId, 'sistem_bildirim', null, $yetkiliId, [
                'mesaj' => KontorTalepMesaji::olustur($salon, $paket, $iletisim, date('d.m.Y H:i')),
            ]);
            AktiviteLog::yaz($salonId, $yetkiliId, 'whatsapp_kontor_talep', 'whatsapp', $salonId, $paket['ad'],
                'WhatsApp kontör talebi: '.$paket['ad'].' ('.$paket['fiyat_metin'].')', ['paket' => $paket['kod']]);
        });
    }
}
