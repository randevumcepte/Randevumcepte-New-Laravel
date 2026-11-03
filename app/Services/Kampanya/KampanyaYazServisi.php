<?php

namespace App\Services\Kampanya;

use App\Domain\Kampanya\HedefKitle;
use App\Domain\Kampanya\Indirim;
use App\Domain\Kampanya\KalemSecimi;
use App\Domain\Kampanya\Kanal;
use App\Domain\Kampanya\SablonKimligi;
use App\Exceptions\GecersizIslemException;
use App\Services\Asistan\AsistanAyarServisi;
use App\Support\AktiviteLog;
use App\Support\BildirimKuyrugu;
use Illuminate\Support\Facades\DB;

/**
 * Kampanya YAZMA — ekle / düzenle / pasif yap. Eski web StoreAdminController@kampanyaekleduzenle +
 * v1 ApiController@kampanyaekleduzenle + kampanyasil/kampanyapasifet'in TEK karşılığı.
 *
 * Gönderim YOK: kayıt yalnız planlar (kanal bayrağı + *_tarih_saat); eski cron'lar (arama / SMS)
 * gönderir. Yan etki: yeni kampanyada personele push → outbox 'kampanya_olustur' (eski
 * v2:bildirim-isle mevcut bildirim kodunu çalıştırır).
 *
 * Eskiden bilinçli farklar:
 *   - Sunucu doğrulaması (eski web hiç yapmıyordu); kayıt salon kapsamında açılır.
 *   - Katılımcı listesi yalnız hedef kitle DEĞİŞTİYSE (ya da hedef_yenile=1) yeniden kurulur —
 *     arama/kupon ilerlemesi korunur. Kural istemci bayrağına bağlı değil, değer karşılaştırması.
 *   - Kanal değişince eski kanalın gönderim bayrağı kapanır (eski: açık kalıp iki kanaldan gidiyordu).
 *   - Kalem değişince diğer *_id'ler NULL'lanır (eski web: kupon eski kaleme düşüyordu).
 *   - Arama kampanyasında E-Asistan "Kampanya Araması" (ayar_id=8) kapalıysa açılır (eski yalnız mobil).
 *   - İndirim kodu değişirse henüz kullanılmamış katılımcıların kodu da güncellenir.
 */
class KampanyaYazServisi
{
    private const PARCA = 1000;

    public function __construct(
        private KampanyaSorgusu $sorgu,
        private HedefKitleServisi $kitle,
        private KampanyaMesajServisi $mesaj,
        private AsistanAyarServisi $asistan,
    ) {}

    /**
     * @param  array<string,mixed>  $v  KampanyaKaydetIstegi::validated()
     * @return array{id:int, katilimci_sayisi:int, katilimcilar_yenilendi:bool}
     */
    public function kaydet(int $salonId, ?int $id, array $v, int $yetkiliId, ?int $kisitPersonel): array
    {
        $sonuc = DB::transaction(function () use ($salonId, $id, $v, $kisitPersonel) {
            $eski = $id ? $this->sorgu->kayit($id, $salonId, true) : null;
            $kanal = Kanal::from((int) ($v['gorev_turu'] ?? $eski?->gorev_turu));

            $hedef = array_key_exists('hedef_filtre', $v) || array_key_exists('hedef_grup', $v) || array_key_exists('hedef_cinsiyet', $v) || ! $eski
                ? HedefKitle::olustur($v['hedef_filtre'] ?? '', $v['hedef_grup'] ?? '', $v['hedef_cinsiyet'] ?? '')
                : HedefKitle::kayittan($eski);
            $yenile = ! $eski || ! empty($v['hedef_yenile']) || ! $hedef->esitMi(HedefKitle::kayittan($eski));

            $kalem = array_key_exists('hizmet_urun_paket', $v) ? KalemSecimi::coz($v['hizmet_urun_paket']) : ($eski ? KalemSecimi::kayittan($eski) : null);
            $kalemAdi = $this->mesaj->kalemAdi($salonId, $kalem);
            $indirim = $kanal->indirimli() ? $this->indirim($v, $eski) : null;

            $sablon = SablonKimligi::coz($v['sablon_id'] ?? null);
            $sablonBilgi = $sablon ? $this->mesaj->sablon($salonId, $sablon, $kanal) : null;
            $ham = trim((string) ($v['mesaj'] ?? '')) ?: ($sablonBilgi['metin'] ?? '');
            $metin = $ham !== '' ? $this->mesaj->kayitMetni($salonId, $ham, $indirim, $kalemAdi) : (string) ($eski?->mesaj ?? '');
            if ($metin === '') {
                throw new GecersizIslemException('Mesaj metni zorunlu (şablon seçin ya da metin yazın)');
            }

            [$bas, $bit, $zaman] = $this->zaman($v, $eski);
            $kod = trim((string) ($v['indirim_kodu'] ?? '')) ?: ($eski?->indirim_kodu ?: substr(str_shuffle('1234567890'), 0, 4));

            $kolonlar = [
                'salon_id'     => $salonId,
                'gorev_turu'   => $kanal->value,
                'paket_isim'   => mb_substr(trim((string) ($v['ad'] ?? '')) ?: ($sablonBilgi['ad'] ?? null) ?: $kalemAdi ?: ($eski?->paket_isim ?: 'Kampanya'), 0, 255),
                'mesaj'        => $metin,
                'indirim_kodu' => $kod,
                'indirim_turu' => $indirim?->metin(),
                'baslangic_tarihi' => $bas, 'bitis_tarihi' => $bit,
                'asistan_tarih_saat' => $zaman, 'sms_tarih_saat' => $zaman, 'bildirim_tarih_saat' => $zaman,
                'arama_ile_gonderim' => 0, 'sms_ile_gonderim' => 0, 'bildirim_ile_gonderim' => 0,
                'aktifmi'      => 1,
                'updated_at'   => now(),
            ] + KalemSecimi::kolonlar($kalem);
            $kolonlar[$kanal->bayrakKolonu()] = 1;
            if (($sablonBilgi['randevu_olustur'] ?? null) !== null) {
                $kolonlar['randevu_olustur'] = $sablonBilgi['randevu_olustur'] ? 1 : 0;
            }
            if ($yenile) {
                $kolonlar += $hedef->kolonlar() + ['musteri_turu' => $this->kitle->etiket($salonId, $hedef)];
            }

            $idler = $yenile ? $this->kitle->idler($salonId, $hedef, $kisitPersonel) : null;
            if ($idler === []) {
                throw new GecersizIslemException('Seçilen hedef kitlede telefonu kayıtlı müşteri yok');
            }

            if ($eski) {
                DB::table('kampanya_yonetimi')->where('id', $eski->id)->update($kolonlar);
                $kid = (int) $eski->id;
                if (! $yenile && $kod !== (string) $eski->indirim_kodu) {
                    DB::table('kampanya_katilimcilari')->where('kampanya_id', $kid)
                        ->where(fn ($w) => $w->whereNull('indirim_kodu_kullanildi')->orWhere('indirim_kodu_kullanildi', '<>', 1))
                        ->update(['indirim_kodu' => $kod, 'updated_at' => now()]);
                }
            } else {
                $kid = (int) DB::table('kampanya_yonetimi')->insertGetId($kolonlar + ['created_at' => now()]);
            }

            if ($idler !== null) {
                $this->katilimcilariKur($kid, $idler, $kod);
            }

            return [
                'id' => $kid, 'yeni' => ! $eski, 'ad' => $kolonlar['paket_isim'], 'kanal' => $kanal,
                'katilimci_sayisi' => $idler !== null ? count($idler) : (int) DB::table('kampanya_katilimcilari')->where('kampanya_id', $kid)->count(),
                'katilimcilar_yenilendi' => $idler !== null,
            ];
        });

        if ($sonuc['kanal'] === Kanal::Arama && ! $this->sorgu->aramaAyariAcik($salonId)) {
            $this->asistan->kaydet($salonId, ['kampanya' => true], null, $yetkiliId);
        }
        if ($sonuc['yeni']) {
            BildirimKuyrugu::yaz($salonId, 'kampanya_olustur', null, $yetkiliId, ['kampanya_id' => $sonuc['id']]);
        }
        AktiviteLog::yaz($salonId, $yetkiliId, $sonuc['yeni'] ? 'kampanya_olustur' : 'kampanya_guncelle', 'kampanya', $sonuc['id'], $sonuc['ad'],
            ($sonuc['yeni'] ? 'Yeni reklam kampanyası oluşturuldu' : 'Reklam kampanyası güncellendi').' ('.$sonuc['kanal']->etiket().', '.$sonuc['katilimci_sayisi'].' katılımcı)',
            ['gorev_turu' => $sonuc['kanal']->value, 'katilimci_sayisi' => $sonuc['katilimci_sayisi'], 'katilimcilar_yenilendi' => $sonuc['katilimcilar_yenilendi']]);

        return ['id' => $sonuc['id'], 'katilimci_sayisi' => $sonuc['katilimci_sayisi'], 'katilimcilar_yenilendi' => $sonuc['katilimcilar_yenilendi']];
    }

    /** Pasif yap (eski "sil" = aktifmi=0; katılımcılar ve kupon geçmişi korunur). */
    public function pasifYap(int $salonId, int $id, int $yetkiliId): void
    {
        $k = $this->sorgu->kayit($id, $salonId);
        DB::table('kampanya_yonetimi')->where('id', $id)->update(['aktifmi' => 0, 'updated_at' => now()]);
        AktiviteLog::yaz($salonId, $yetkiliId, 'kampanya_sil', 'kampanya', $id, (string) $k->paket_isim, 'Reklam kampanyası silindi (pasif yapıldı)');
    }

    // ───────────────────────────── iç adımlar ─────────────────────────────

    /** İstekte indirim alanı yoksa kayıttaki indirim korunur (kısmi PATCH). */
    private function indirim(array $v, ?object $eski): Indirim
    {
        if (! array_key_exists('indirim_turu', $v) && $eski && ($i = Indirim::metinden($eski->indirim_turu))) {
            return $i;
        }
        return Indirim::girdiden($v);
    }

    /** @return array{0:string,1:string,2:string} [başlangıç, bitiş, gönderim zamanı Y-m-d H:i:s] */
    private function zaman(array $v, ?object $eski): array
    {
        $eskiZaman = $eski?->asistan_tarih_saat ? strtotime($eski->asistan_tarih_saat) : null;
        $bas = $v['baslangic_tarihi'] ?? ($eski?->baslangic_tarihi ? substr($eski->baslangic_tarihi, 0, 10) : now()->toDateString());
        $bit = $v['bitis_tarihi'] ?? ($eski?->bitis_tarihi ? substr($eski->bitis_tarihi, 0, 10) : null);
        $saat = $v['gonderim_saati'] ?? ($eskiZaman ? date('H:i', $eskiZaman) : now()->format('H:i'));
        if (! $bit) {
            throw new GecersizIslemException('Bitiş (geçerlilik) tarihi zorunlu');
        }
        if ($bit < $bas) {
            throw new GecersizIslemException('Bitiş tarihi başlangıç tarihinden önce olamaz');
        }

        return [$bas, $bit, $bas.' '.$saat.':00'];
    }

    /** @param int[] $idler */
    private function katilimcilariKur(int $kampanyaId, array $idler, string $kod): void
    {
        DB::table('kampanya_katilimcilari')->where('kampanya_id', $kampanyaId)->delete();
        $simdi = now();
        foreach (array_chunk($idler, self::PARCA) as $parca) {
            DB::table('kampanya_katilimcilari')->insert(array_map(fn ($uid) => [
                'kampanya_id' => $kampanyaId, 'user_id' => $uid, 'indirim_kodu' => $kod, 'created_at' => $simdi, 'updated_at' => $simdi,
            ], $parca));
        }
    }
}
