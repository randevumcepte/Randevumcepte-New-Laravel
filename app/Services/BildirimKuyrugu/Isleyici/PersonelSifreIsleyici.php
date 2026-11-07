<?php

namespace App\Services\BildirimKuyrugu\Isleyici;

use App\Models\Personeller;
use App\Services\BildirimKuyrugu\Kanal\SifreWhatsappGonderici;
use App\Services\BildirimKuyrugu\Kanal\SmsKanali;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Personel giris sifresini uret + kaydet + WhatsApp (olmazsa SMS) ile gonder; eski ApiController::personelSifreUretVeGonder.
 * Korunan hesapta (config oturum.korunan_hesaplar.isletme) sifre sabit kalir, mesaj gitmez. Yetkili satiri yoksa eskide 422
 * donulurdu: outbox'ta normal donus, kayit durum=1 olur.
 *
 * Eskiden gelen ve KORUNAN hata: acik sifre salon_personelleri.sifre kolonuna yazilir. Yeniden denemede sifre yeniden uretilir.
 */
class PersonelSifreIsleyici extends PersonelTabanliIsleyici
{
    private const KORUNAN_HESAP_SIFRESI = '1234';

    public function __construct(private SifreWhatsappGonderici $sifreWhatsapp, private SmsKanali $sms) {}

    protected function personelIsle(object $kayit, array $payload, Personeller $personel): void
    {
        if (! $personel->yetkili_id) {
            throw new RuntimeException('Personelin giris hesabi yok: '.$personel->id);
        }

        $yetkili = DB::table('isletmeyetkilileri')->where('id', $personel->yetkili_id)->first();

        if ($yetkili && in_array((int) $yetkili->id, (array) config('oturum.korunan_hesaplar.isletme'), true)) {
            Log::info('Demo işletme yetkilisi personel şifre sıfırlama engellendi', ['yetkili_id' => $yetkili->id, 'personel_id' => $personel->id]);
            $this->sifreYaz($personel, (int) $yetkili->id, self::KORUNAN_HESAP_SIFRESI);

            return;
        }
        if (! $yetkili) {
            return;
        }

        $kod = substr(str_shuffle('ABCDEFGHJKLMNOPQRSTUVWXYZ1234567890'), 0, 6);
        $this->sifreYaz($personel, (int) $yetkili->id, $kod);

        $mesajlar = [[
            'to'      => $yetkili->gsm1,
            'message' => ! empty($payload['yeni_hesap'])
                ? 'Sayın '.$yetkili->name.'. Randevu sistemi şifreniz : '.$kod
                : 'Sayın '.$yetkili->name.'. Randevumcepte yeni şifreniz : '.$kod.' olarak güncellenmiştir.',
        ]];

        if (! $this->sifreWhatsapp->gonder($personel->salon_id, $mesajlar, $yetkili->id)) {
            $this->sms->gonder2($mesajlar, false, 1, false, $personel->salon_id, true);
        }

        $this->aktiviteYaz($personel);
    }

    /** Sifre once kaydedilir, sonra gonderilir (eski sira). Eski model kaydi updated_at'i da gunceller. */
    private function sifreYaz(Personeller $personel, int $yetkiliId, string $sifre): void
    {
        DB::table('isletmeyetkilileri')->where('id', $yetkiliId)->update(['password' => Hash::make($sifre), 'updated_at' => now()]);
        $personel->sifre = $sifre;
        $personel->save();
    }

    /**
     * Eski Audit::logApi'nin bu akistaki sonucu: istekte kimlik yok, panelin Aktivite Gecmisi'nde 'Mobil' gorunur.
     * AktiviteLog::yaz 'Sistem' ve kaynak=v2 yazdigi icin kullanilmadi. Log yazimi ana akisi kirmaz.
     */
    private function aktiviteYaz(Personeller $personel): void
    {
        try {
            DB::table('salon_aktivite_log')->insert([
                'salon_id'     => $personel->salon_id,
                'user_id'      => null,
                'user_type'    => 'sistem',
                'user_name'    => 'Mobil',
                'user_rol'     => 'Mobil',
                'action'       => 'personel_sifre_gonder',
                'target_type'  => 'personel',
                'target_id'    => $personel->id,
                'target_label' => $personel->personel_adi ? mb_substr((string) $personel->personel_adi, 0, 200) : null,
                'aciklama'     => 'Personele yeni sifre SMS gonderildi',
                'meta'         => json_encode(['kaynak' => 'mobil'], JSON_UNESCAPED_UNICODE),
                'ip'           => null,
                'user_agent'   => '',
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Audit::logApi basarisiz', ['action' => 'personel_sifre_gonder', 'salon_id' => $personel->salon_id, 'hata' => $e->getMessage()]);
        }
    }
}
