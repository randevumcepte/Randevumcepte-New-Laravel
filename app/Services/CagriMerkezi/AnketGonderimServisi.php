<?php

namespace App\Services\CagriMerkezi;

use App\Domain\CagriMerkezi\CagriMerkeziErisim;
use App\Exceptions\GecersizIslemException;
use App\Support\AktiviteLog;
use App\Support\BildirimKuyrugu;
use Illuminate\Support\Facades\DB;

/**
 * Memnuniyet anketi gönder (tek tık) — eski cagri_anket_gonder / anketHizliGonder.
 *
 * v2 yan etki üretmez: outbox'a 'anket_gonder' yazar; eski monolitin `v2:bildirim-isle` komutu
 * MEVCUT anket kodunu çalıştırır (salonun varsayılan şablonu — yoksa oluşturulur —, gönderim
 * kaydı, WhatsApp-first + SMS yedek). Müşteri aranacak kaydından çözülür → personel ham
 * numarayı görmeden gönderebilir (KVKK).
 */
class AnketGonderimServisi
{
    public function __construct(private CagriMerkeziErisimServisi $erisim) {}

    public function gonder(CagriMerkeziErisim $e, int $aranacakId): array
    {
        $this->erisim->yetkiZorunlu($e, 'anket', 'Anket gönderme yetkiniz yok.');
        $k = $this->erisim->kayit($aranacakId, $e);
        $musteri = DB::table('users')->where('id', $k->user_id)->first(['id', 'name', 'cep_telefon']);
        if (! $musteri || trim((string) $musteri->cep_telefon) === '') {
            throw new GecersizIslemException('Müşterinin telefon numarası kayıtlı değil.');
        }

        BildirimKuyrugu::yaz($e->salonId, 'anket_gonder', null, $e->yetkiliId, [
            'user_id' => (int) $musteri->id, 'personel_id' => $e->personelId, 'kanal' => 'manuel', 'kaynak' => 'cagri_merkezi',
        ]);
        AktiviteLog::yaz($e->salonId, $e->yetkiliId, 'cagri_anket_gonder', 'aranacak_musteri', $aranacakId, (string) $musteri->name ?: null,
            'Çağrı merkezinden memnuniyet anketi gönderildi', ['user_id' => (int) $musteri->id]);

        return ['mesaj' => 'Anket gönderim kuyruğuna alındı; birazdan müşteriye WhatsApp veya SMS ile ulaşacak.'];
    }
}
