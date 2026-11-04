<?php

namespace App\Services\CagriMerkezi;

use App\Domain\CagriMerkezi\CagriMerkeziErisim;
use App\Exceptions\GecersizIslemException;
use App\Services\Anket\AnketGonderServisi;
use App\Support\AktiviteLog;
use Illuminate\Support\Facades\DB;

/**
 * Memnuniyet anketi gönder (tek tık) — eski cagri_anket_gonder / anketHizliGonder.
 *
 * Gönderimin kendisi ortak AnketGonderServisi'nde (varsayılan şablon, gönderim kaydı, outbox);
 * burada yalnız Çağrı Merkezi yetkisi + kayıt erişimi. Müşteri aranacak kaydından çözülür →
 * personel ham numarayı görmeden gönderebilir (KVKK).
 */
class AnketGonderimServisi
{
    public function __construct(
        private CagriMerkeziErisimServisi $erisim,
        private AnketGonderServisi $anket,
    ) {}

    public function gonder(CagriMerkeziErisim $e, int $aranacakId): array
    {
        $this->erisim->yetkiZorunlu($e, 'anket', 'Anket gönderme yetkiniz yok.');
        $k = $this->erisim->kayit($aranacakId, $e);
        $musteri = DB::table('users')->where('id', $k->user_id)->first(['id', 'name', 'cep_telefon']);
        if (! $musteri) {
            throw new GecersizIslemException('Müşterinin telefon numarası kayıtlı değil.');
        }

        $this->anket->musteriyeGonder($e->salonId, $musteri, $e->yetkiliId, $e->personelId);
        AktiviteLog::yaz($e->salonId, $e->yetkiliId, 'cagri_anket_gonder', 'aranacak_musteri', $aranacakId, (string) $musteri->name ?: null,
            'Çağrı merkezinden memnuniyet anketi gönderildi', ['user_id' => (int) $musteri->id]);

        return ['mesaj' => 'Anket gönderim kuyruğuna alındı; birazdan müşteriye WhatsApp veya SMS ile ulaşacak.'];
    }
}
