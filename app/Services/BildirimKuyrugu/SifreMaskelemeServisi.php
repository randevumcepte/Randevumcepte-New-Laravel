<?php

namespace App\Services\BildirimKuyrugu;

use App\Domain\Bildirim\SifreMaskesi;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mesaj kayitlarindaki acik sifreleri gizler (KVKK). Iki uygulamanin yazdigi kayitlara da uygulanir.
 *
 * WhatsApp kaydi sonuclanmadan dokunulmaz: eski monolitin webhook'u gec dusen mesaji bu satirdaki metinden SMS olarak yeniden
 * gonderir; erken maskelenirse musteriye "*****" gider. SMS iletim raporu yazilirken zaten maskelenir (SmsKanali).
 */
class SifreMaskelemeServisi
{
    private const WA_SONUC_BEKLEME_DK = 10;

    private const YAKIN_GUN = 2;

    /** Son gunlerin kayitlari (saatlik). @return int maskelenen satir */
    public function yakin(): int
    {
        $sinir = now()->subDays(self::YAKIN_GUN);

        return $this->tablo('whatsapp_gonderim_loglari', 'mesaj', fn ($q) => $q->where('created_at', '>=', $sinir)->where('durum', '<>', 0)
            ->where('updated_at', '<', now()->subMinutes(self::WA_SONUC_BEKLEME_DK)))
            + $this->tablo('sms_iletim_raporlari', 'aciklama', fn ($q) => $q->where('created_at', '>=', $sinir));
    }

    /**
     * Tum gecmis (bir kez, elle). Sonuclanmamis WhatsApp kaydi atlanir. salon_personelleri.sifre bosaltilir: kolonu okuyan kod
     * yoktur, giris isletmeyetkilileri.password ile yapilir. @return array<string,int>
     */
    public function gecmis(): array
    {
        $personel = Schema::hasColumn('salon_personelleri', 'sifre')
            ? DB::table('salon_personelleri')->whereNotNull('sifre')->update(['sifre' => null])
            : 0;

        return [
            'whatsapp_gonderim_loglari' => $this->tablo('whatsapp_gonderim_loglari', 'mesaj', fn ($q) => $q->where(fn ($w) => $w->where('durum', '<>', 0)
                ->orWhere('created_at', '<', now()->subDay()))),
            'sms_iletim_raporlari'      => $this->tablo('sms_iletim_raporlari', 'aciklama', fn ($q) => $q),
            'salon_personelleri.sifre'  => $personel,
        ];
    }

    private function tablo(string $tablo, string $kolon, callable $kosul): int
    {
        if (! Schema::hasTable($tablo)) {
            return 0;
        }
        $sayi = 0;
        $kosul(DB::table($tablo)->select('id', $kolon)->where($kolon, 'like', '%ifre%'))
            ->chunkById(500, function ($satirlar) use ($tablo, $kolon, &$sayi) {
                foreach ($satirlar as $s) {
                    $yeni = SifreMaskesi::uygula($s->{$kolon});
                    if ($yeni !== $s->{$kolon}) {
                        DB::table($tablo)->where('id', $s->id)->update([$kolon => $yeni]);
                        $sayi++;
                    }
                }
            });

        return $sayi;
    }
}
