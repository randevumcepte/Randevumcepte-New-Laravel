<?php

namespace App\Services\CagriMerkezi;

use App\Domain\CagriMerkezi\CagriMerkeziErisim;
use App\Domain\CagriMerkezi\SonucKaydiPlani;
use App\Domain\Santral\CdrSiniflandirici;
use App\Exceptions\GecersizIslemException;
use App\Services\Santral\SantralAmiIstemcisi;
use App\Support\AktiviteLog;
use Illuminate\Support\Facades\DB;

/**
 * KVKK uyumlu ARAMA BAŞLATMA — eski arama_baslat + arama_baslat_numara. Numara sunucuda
 * çözülür, istemciye (web/Flutter) hiç gitmez; santral önce kullanıcının dahilisini çaldırır.
 *
 * Eskiden farklar: dahili/trunk yoksa santrale gidilmeden anlaşılır 422; aranacak kaydı
 * erişim denetimli (personel yalnız kendi listesi); her arama başlatma log'a düşer.
 * WebRTC softphone modu v2'de yok (web v2 kabuğunda softphone yok, Flutter Bria kullanıyor).
 */
class AramaBaslatmaServisi
{
    public function __construct(
        private CagriMerkeziErisimServisi $erisim,
        private SantralAmiIstemcisi $ami,
    ) {}

    /** Kuyruktaki / takvimdeki aranacak müşteriyi ara. */
    public function kayittan(CagriMerkeziErisim $e, int $aranacakId): array
    {
        $k = $this->erisim->kayit($aranacakId, $e);
        $tel = CdrSiniflandirici::son10((string) DB::table('users')->where('id', $k->user_id)->value('cep_telefon'));
        if ($tel === '') {
            throw new GecersizIslemException('Müşterinin telefon numarası yok.');
        }
        $sonuc = $this->ara($e, $tel);
        if ($sonuc['basarili']) {
            // Bekleyen arama randevusu "arandı" olur (hatırlatma susar); zaman KORUNUR (geç/zamanında raporu).
            DB::table('aranacak_musteriler')->where('id', $aranacakId)
                ->update(SonucKaydiPlani::aramaBasladi($k->durum !== null ? (int) $k->durum : null, now()->format('Y-m-d H:i:s')) + ['updated_at' => now()]);
            AktiviteLog::yaz($e->salonId, $e->yetkiliId, 'cagri_arama_baslat', 'aranacak_musteri', $aranacakId,
                (string) DB::table('users')->where('id', $k->user_id)->value('name') ?: null, 'Çağrı merkezinden arama başlatıldı', ['user_id' => (int) $k->user_id]);
        }

        return $sonuc + ['arama_id' => (int) $k->arama_id];
    }

    /**
     * Müşteri kartı / çağrı kaydı "Ara": user_id verilirse numara SUNUCUDAN (portföydeki aktif
     * müşteri) alınır — maskeli personel numarayı görmeden arayabilir. Aksi halde ham numara.
     */
    public function numaradan(CagriMerkeziErisim $e, ?int $userId, ?string $numara): array
    {
        $tel = '';
        if ($userId && DB::table('musteri_portfoy')->where('salon_id', $e->salonId)->where('user_id', $userId)->where('aktif', 1)->exists()) {
            $tel = CdrSiniflandirici::son10((string) DB::table('users')->where('id', $userId)->value('cep_telefon'));
        }
        if ($tel === '') {
            $tel = CdrSiniflandirici::son10((string) $numara);
        }
        if ($tel === '') {
            throw new GecersizIslemException('Telefon numarası geçersiz.');
        }
        $sonuc = $this->ara($e, $tel);
        if ($sonuc['basarili']) {
            AktiviteLog::yaz($e->salonId, $e->yetkiliId, 'cagri_arama_baslat', $userId ? 'musteri' : null, $userId,
                null, 'Numaradan arama başlatıldı', $userId ? ['user_id' => $userId] : []);
        }

        return $sonuc;
    }

    /** @return array{basarili:bool, mesaj:string} */
    private function ara(CagriMerkeziErisim $e, string $tel10): array
    {
        $dahili = $e->personelId
            ? (string) preg_replace('/\D/', '', (string) DB::table('salon_personelleri')->where('id', $e->personelId)->value('dahili_no'))
            : '';
        if ($dahili === '') {
            throw new GecersizIslemException('Arama için size bir dahili numara atanmamış. (Personel ayarlarından dahili tanımlayın.)');
        }
        $trunk = (string) preg_replace('/\D/', '', (string) DB::table('sabit_numaralar')->where('salon_id', $e->salonId)->value('numara'));
        if ($trunk === '') {
            throw new GecersizIslemException('İşletme için santral (trunk) numarası tanımlı değil.');
        }

        return $this->ami->originate($dahili, $trunk, $tel10);
    }
}
