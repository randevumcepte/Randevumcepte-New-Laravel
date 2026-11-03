<?php

namespace App\Services\Kampanya;

use App\Domain\Kampanya\KatilimDurumu;
use App\Domain\Kampanya\Kanal;
use App\Exceptions\BulunamadiException;
use App\Exceptions\CakismaException;
use App\Exceptions\GecersizIslemException;
use App\Services\CagriMerkezi\ListeOlusturmaServisi;
use App\Services\Musteri\MusteriOkumaServisi;
use App\Support\AktiviteLog;
use App\Support\KvkkMaske;
use App\Support\ListeIstegi;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Kampanya KATILIMCILARI — rapor listesi (durum sekmeleri + sayaçlar) ve satır işlemleri.
 * Eski kampanyadetay / kampanyakatilimciekle / kampanyakatilimcisil /
 * kampanya-ulasilamayan-tekrar-ara / kampanya-katilimci-personele-ata + v1 kampanyaDetay karşılığı.
 *
 * Eskiden düzeltilenler: kapsam/yetki denetimi yoktu (IDOR); telefon araması çalışmıyordu;
 * katılımcısız kampanyaya kişi eklenemiyordu (kod ilk katılımcıdan kopyalanıyordu → artık kampanyadan);
 * mobil yalnız 4 sekmeyi biliyordu, sayaç yoktu.
 */
class KatilimciServisi
{
    public const SIRALAMA = ['ad_soyad' => 'u.name', 'id' => 'kk.id', 'durum' => 'kk.durum_asistan', 'kullanim' => 'kk.indirim_kodu_kullanim_tarihi'];

    public function __construct(
        private MusteriOkumaServisi $musteri,
        private ListeOlusturmaServisi $liste,
    ) {}

    /**
     * @param  array{page:int, per_page:int, ara:?string, sirala:?string, yon:string}  $liste
     * @param  array{telefon_gor:bool, yazabilir:bool}  $ctx
     */
    public function listele(object $kampanya, KatilimDurumu $durum, array $liste, array $ctx): array
    {
        $kanal = Kanal::degerden($kampanya->gorev_turu) ?? Kanal::Sms;
        $q = $this->ara($this->temel((int) $kampanya->id), $liste['ara'] ?? null);

        $sayaclar = [];
        $s = (array) (clone $q)->selectRaw(implode(', ', array_map(
            fn (KatilimDurumu $d) => ($d->sqlKosulu() ? 'SUM('.$d->sqlKosulu().')' : 'COUNT(*)').' as '.$d->kod(), KatilimDurumu::cases()
        )))->first();
        foreach (KatilimDurumu::cases() as $d) {
            $sayaclar[$d->kod()] = (int) ($s[$d->kod()] ?? 0);
        }

        if ($k = $durum->sqlKosulu()) {
            $q->whereRaw($k);
        }
        $toplam = (clone $q)->count();
        $kolon = self::SIRALAMA[$liste['sirala'] ?? ''] ?? 'u.name';
        $satirlar = $q->select([
            'kk.id', 'kk.user_id', 'u.name', 'u.cep_telefon', 'kk.durum_asistan', 'kk.kilitli', 'kk.tekrar_aranacak', 'kk.tekrar_arandi',
            'kk.tekrar_arama_tarih_saat', 'kk.indirim_kodu', 'kk.indirim_kodu_kullanildi', 'kk.indirim_kodu_kullanim_tarihi',
            'kk.indirim_kodu_adisyon_id', 'kk.indirim_kodu_tutar',
        ])->orderBy($kolon, $liste['yon'] ?? 'asc')->orderBy('kk.id')->forPage($liste['page'], $liste['per_page'])->get();

        return ['data' => $satirlar->map(fn ($r) => $this->satir($r, $kanal, $ctx))->all()]
            + ListeIstegi::meta($liste['page'], $liste['per_page'], $toplam)
            + ['sayaclar' => $sayaclar];
    }

    /** Portföydeki müşteriyi kampanyaya ekler (zaten varsa 409). */
    public function ekle(object $kampanya, int $userId, int $yetkiliId, ?int $kisitPersonel): array
    {
        $m = $this->musteri->portfoyZorunlu((int) $kampanya->salon_id, $userId, $kisitPersonel);
        $id = DB::transaction(function () use ($kampanya, $userId) {
            if (DB::table('kampanya_katilimcilari')->where('kampanya_id', $kampanya->id)->where('user_id', $userId)->lockForUpdate()->exists()) {
                throw new CakismaException('Bu müşteri zaten kampanyada');
            }

            return (int) DB::table('kampanya_katilimcilari')->insertGetId([
                'kampanya_id' => $kampanya->id, 'user_id' => $userId, 'indirim_kodu' => $kampanya->indirim_kodu,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });
        $ad = (string) DB::table('users')->where('id', $m->user_id)->value('name');
        AktiviteLog::yaz((int) $kampanya->salon_id, $yetkiliId, 'kampanya_katilimci_ekle', 'kampanya', (int) $kampanya->id, (string) $kampanya->paket_isim,
            $ad.' kampanyaya eklendi', ['user_id' => $userId]);

        return ['id' => $id];
    }

    /** Katılımcıyı çıkarır (yalnız bu kampanyanın satırı; kupon kullanılmışsa 409 — tahsilat izi korunur). */
    public function sil(object $kampanya, int $katilimciId, int $yetkiliId): void
    {
        $r = DB::table('kampanya_katilimcilari as kk')->leftJoin('users as u', 'u.id', '=', 'kk.user_id')
            ->where('kk.id', $katilimciId)->where('kk.kampanya_id', $kampanya->id)
            ->first(['kk.id', 'kk.indirim_kodu_kullanildi', 'u.name']) ?? throw new BulunamadiException('Katılımcı bulunamadı');
        if ((int) $r->indirim_kodu_kullanildi === 1) {
            throw new CakismaException('İndirim kodunu kullanmış katılımcı çıkarılamaz');
        }
        DB::table('kampanya_katilimcilari')->where('id', $katilimciId)->delete();
        AktiviteLog::yaz((int) $kampanya->salon_id, $yetkiliId, 'kampanya_katilimci_sil', 'kampanya', (int) $kampanya->id, (string) $kampanya->paket_isim,
            trim((string) $r->name).' kampanyadan çıkarıldı');
    }

    /**
     * Ulaşılamayanları yeniden sıraya al (eski "Tekrar Ara" BİREBİR): tekrar/kilit alanları sıfırlanır,
     * arama cron'u onları ilk-arama dalında yeniden arar. Yalnız arama kanalı.
     */
    public function tekrarAra(object $kampanya, int $yetkiliId): int
    {
        if ((int) $kampanya->gorev_turu !== Kanal::Arama->value) {
            throw new GecersizIslemException('Tekrar arama yalnız Santral Arama kampanyalarında kullanılabilir');
        }
        $adet = DB::table('kampanya_katilimcilari as kk')->where('kk.kampanya_id', $kampanya->id)->whereRaw(KatilimDurumu::Ulasilamadi->sqlKosulu())
            ->update(['tekrar_arandi' => null, 'tekrar_aranacak' => null, 'tekrar_arama_tarih_saat' => null, 'kilitli' => 0, 'kilitli_zaman' => null, 'updated_at' => now()]);
        AktiviteLog::yaz((int) $kampanya->salon_id, $yetkiliId, 'kampanya_tekrar_ara', 'kampanya', (int) $kampanya->id, (string) $kampanya->paket_isim,
            $adet.' ulaşılamayan katılımcı yeniden aranmak üzere sıraya alındı', ['adet' => $adet]);

        return $adet;
    }

    /**
     * Seçili sekmedeki katılımcılardan personele arama listesi (Çağrı Merkezi) oluşturur — liste
     * yazımı Çağrı Merkezi ile ORTAK (ListeOlusturmaServisi::listeYaz).
     */
    public function personeleAta(object $kampanya, int $personelId, KatilimDurumu $durum, int $yetkiliId): array
    {
        $q = $this->temel((int) $kampanya->id);
        if ($k = $durum->sqlKosulu()) {
            $q->whereRaw($k);
        }
        $ids = $q->orderBy('u.name')->pluck('kk.user_id')->map(fn ($i) => (int) $i)->unique()->values()->all();
        if (! $ids) {
            throw new GecersizIslemException('Bu sekmede atanacak katılımcı yok');
        }
        $baslik = mb_substr(trim((string) $kampanya->paket_isim) ?: 'Kampanya', 0, 110).' — '.$durum->ad().' ('.now()->format('d.m.Y H:i').')';
        $l = $this->liste->listeYaz((int) $kampanya->salon_id, $yetkiliId, $baslik, $personelId, null,
            ['kaynak' => 'kampanya', 'kampanya_id' => (int) $kampanya->id, 'tur' => $durum->value, 'etiket' => $durum->ad()], $ids);

        $mesaj = count($ids).' katılımcı "'.$baslik.'" listesiyle '.$l['personel_adi'].' adlı personele atandı.';
        AktiviteLog::yaz((int) $kampanya->salon_id, $yetkiliId, 'kampanya_personele_ata', 'arama_listesi', $l['id'], $baslik, $mesaj,
            ['kampanya_id' => (int) $kampanya->id, 'personel_id' => $personelId, 'adet' => count($ids)]);

        return ['arama_listesi_id' => $l['id'], 'adet' => count($ids), 'mesaj' => $mesaj];
    }

    // ───────────────────────────── iç adımlar ─────────────────────────────

    private function temel(int $kampanyaId): Builder
    {
        return DB::table('kampanya_katilimcilari as kk')->leftJoin('users as u', 'u.id', '=', 'kk.user_id')->where('kk.kampanya_id', $kampanyaId);
    }

    private function ara(Builder $q, ?string $ara): Builder
    {
        if (! ($like = ListeIstegi::like($ara))) {
            return $q;
        }
        $rakam = preg_replace('/\D/', '', (string) $ara);

        return $q->where(function ($w) use ($like, $rakam) {
            $w->where('u.name', 'like', $like)->orWhere('kk.indirim_kodu', 'like', $like);
            if (strlen($rakam) >= 3) {
                $w->orWhere('u.cep_telefon', 'like', '%'.$rakam.'%');
            }
        });
    }

    private function satir(object $r, Kanal $kanal, array $ctx): array
    {
        $d = KatilimDurumu::satir($r, $kanal);
        $kullanildi = (int) $r->indirim_kodu_kullanildi === 1;

        return [
            'id'                          => (int) $r->id,
            'user_id'                     => (int) $r->user_id,
            'ad_soyad'                    => trim((string) $r->name),
            'telefon'                     => $ctx['telefon_gor'] ? (string) $r->cep_telefon : KvkkMaske::telefon($r->cep_telefon),
            'durum'                       => $d['etiket'],     // Flutter v1: metin
            'durum_kodu'                  => $d['kod'],
            'durum_renk'                  => $d['renk'],
            'indirim_kodu'                => (string) $r->indirim_kodu,
            'indirim_kodu_kullanildi'     => $kullanildi ? 1 : 0,
            'indirim_kodu_kullanim_tarihi'=> $r->indirim_kodu_kullanim_tarihi ? date('Y-m-d H:i', strtotime($r->indirim_kodu_kullanim_tarihi)) : null,
            'indirim_kodu_adisyon_id'     => $r->indirim_kodu_adisyon_id ? (int) $r->indirim_kodu_adisyon_id : null,
            'indirim_kodu_tutar'          => $r->indirim_kodu_tutar !== null ? (float) $r->indirim_kodu_tutar : null,
            'islemler'                    => ['sil' => $ctx['yazabilir'] && ! $kullanildi],
        ];
    }
}
