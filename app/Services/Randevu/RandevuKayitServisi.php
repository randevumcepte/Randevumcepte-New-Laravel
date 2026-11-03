<?php

namespace App\Services\Randevu;

use App\Domain\Randevu\Cakisma;
use App\Domain\Randevu\HizmetZinciri;
use App\Domain\Randevu\SaatKapama;
use App\Exceptions\BulunamadiException;
use App\Exceptions\GecersizIslemException;
use App\Models\Randevular;
use App\Support\AktiviteLog;
use App\Support\BildirimKuyrugu;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Randevu OLUSTUR / GUNCELLE (Faz 3b) — ISLETME tarafi (kaynak = salon).
 *
 * Eski ApiController@randevuekleguncelle'nin isletme akisinin v2 karsiligi.
 * Girdi alanlari Flutter randevu editoruyle AYNI (randevu_id, user_id,
 * randevu_tarihi, randevu_saati, hizmetler[], yardimcipersoneller[], tekrarlayan,
 * tekrar_sayisi, tekrar_sikligi, notlar, durum, cakisanrandevuekle) → ekran gecisi
 * drop-in. Donus yuk-tasiyan anahtarlari korur: cakismavar / cakisanunsurlar.
 *
 * Akis: dogrula → CAKISMA (eski 3 kontrol, salon ayarina bagli) → PLAN
 * (saat zinciri, oda otomatik atama, seans havuzu / bekleyen telafi / yeni seans,
 * adisyon fiyat senkronu, yardimci personel) → [yaz] tek transaction + outbox
 * (bildirim: randevu_kayit, Google: google_takvim) + aktivite logu.
 *
 * KAPSAM DISI (monolitte kalir): musteri uygulamasi / online randevu (kaynak
 * uygulama/web: sert cakisma, hizmet sirasi optimizasyonu), sesli asistan.
 *
 * Eski davranistan BILINCLI farklar:
 *   - Guncellemede tekrar YOK SAYILIR (eski kod ayni randevuyu her tarihte ustune
 *     yazip son tarihte birakiyordu).
 *   - Guncellemede artik bu randevuda karsiligi kalmayan (hizmet cikarildi) ve
 *     henuz isaretlenmemis (geldi NULL) seanslar SERBEST BIRAKILIR (eski kod randevuya
 *     bagli birakip seansi tuketilmis gosteriyordu). Geldi/gelmedi/telafi seanslara dokunulmaz.
 *   - Sure ZORUNLU (eski kod bos sureyi yerine gore 0/30/60 sayiyordu).
 *   - Musteri portfoyu, hizmet ve kaynaklar ayni salona ait olmali.
 *   - Eski "baslangic saati ayni" cakisma kontrolu kaynaksiz satirda tum salonu
 *     eslestiriyordu; kaynak yoksa atlanir. Guncellenen randevu kendisiyle cakismaz.
 */
class RandevuKayitServisi
{
    private const KAYNAK_TABLO = ['personel_id' => ['salon_personelleri', 'personel'], 'cihaz_id' => ['cihazlar', 'cihaz'], 'oda_id' => ['odalar', 'oda']];

    public function kaydet(int $salonId, array $g, ?int $yetkiliId, bool $yaz): array
    {
        $girdi = $this->dogrula($salonId, $g);
        $eski = $girdi['eski'];

        // ── CAKISMA (yazmadan once; DRY-RUN'da da) ──
        $cakisma = $this->cakismaKontrol($salonId, $girdi);
        if ($cakisma) {
            return ['cakismavar' => '1', 'cakisanunsurlar' => $cakisma['mesaj'], 'sert' => $cakisma['sert'], 'yazildi' => false];
        }

        if (! $yaz) {
            $plan = $this->planla($salonId, $girdi, []);

            return ['cakismavar' => '0', 'cakisanunsurlar' => 'DRY-RUN', 'yazildi' => false, 'plan' => $plan, 'bildirimler' => ['randevu_kayit', 'google_takvim']];
        }

        $sonuc = DB::transaction(function () use ($salonId, $girdi, $yetkiliId, $eski) {
            if ($eski) {
                // Es zamanli iki duzenleme ayni randevuyu bozmasin.
                DB::table('randevular')->where('id', $eski->id)->lockForUpdate()->first();
            }
            $plan = $this->planla($salonId, $girdi, []); // kilit altinda taze plan

            return $this->uygula($salonId, $girdi, $plan, $yetkiliId);
        });

        AktiviteLog::yaz($salonId, $yetkiliId, $eski ? 'randevu_guncelle' : 'randevu_ekle', 'randevu', $sonuc['randevu_idler'][0] ?? null,
            $girdi['tarihler'][0].' '.$girdi['saat'], $eski ? 'Randevu güncellendi' : 'Yeni randevu oluşturuldu',
            ['randevu_idler' => $sonuc['randevu_idler']]);

        return ['cakismavar' => '0', 'cakisanunsurlar' => 'Başarılı', 'yazildi' => true] + $sonuc;
    }

    // ═════════════════════════════ DOGRULAMA ═════════════════════════════

    private function dogrula(int $salonId, array $g): array
    {
        $eski = null;
        $randevuId = (int) ($g['randevu_id'] ?? 0);
        if ($randevuId) {
            $eski = DB::table('randevular')->where('id', $randevuId)->first();
            if (! $eski || (int) $eski->salon_id !== $salonId) {
                throw new BulunamadiException('Randevu bulunamadı');
            }
            if ((int) $eski->user_id === Randevular::KAPALI_SAAT_USER_ID) {
                throw new GecersizIslemException('Kapalı saat bloğu randevu olarak düzenlenemez.');
            }
            if ((int) $eski->durum >= Randevular::IPTAL_EDILDI) {
                throw new GecersizIslemException('İptal edilmiş randevu düzenlenemez.');
            }
        }

        $userId = (int) ($g['user_id'] ?? 0);
        if (! $userId || ! DB::table('musteri_portfoy')->where('salon_id', $salonId)->where('user_id', $userId)->exists()) {
            throw new GecersizIslemException('Müşteri seçilmedi veya bu işletmenin portföyünde değil.');
        }

        $hizmetler = array_values((array) ($g['hizmetler'] ?? []));
        if (! $hizmetler) {
            throw new GecersizIslemException('En az bir hizmet seçin.');
        }
        $bos = fn ($v) => $v === null || $v === '' || $v === 'null';
        foreach ($hizmetler as $i => &$h) {
            $h = [
                'hizmet_id'     => (int) ($h['hizmet_id'] ?? 0),
                'personel_id'   => $bos($h['personel_id'] ?? null) ? null : (int) $h['personel_id'],
                'oda_id'        => $bos($h['oda_id'] ?? null) ? null : (int) $h['oda_id'],
                'cihaz_id'      => $bos($h['cihaz_id'] ?? null) ? null : (int) $h['cihaz_id'],
                'sure_dk'       => $h['sure_dk'] ?? null,
                'fiyat'         => $bos($h['fiyat'] ?? null) ? 0 : (float) $h['fiyat'],
                'birlestir'     => (string) ($h['birlestir'] ?? '') === '1' || ($h['birlestir'] ?? null) === true ? '1' : '',
                'dusum_miktari' => $bos($h['dusum_miktari'] ?? null) ? null : max(1, (int) $h['dusum_miktari']),
                // Paket randevusu: secilen satis (tercih). Gecerliyse seans ONDAN dusulur.
                'adisyon_paket_id'  => $bos($h['adisyon_paket_id'] ?? null) ? null : (int) $h['adisyon_paket_id'],
                'adisyon_hizmet_id' => $bos($h['adisyon_hizmet_id'] ?? null) ? null : (int) $h['adisyon_hizmet_id'],
            ];
            if (! $h['hizmet_id'] || ! DB::table('salon_sunulan_hizmetler')->where('salon_id', $salonId)->where('hizmet_id', $h['hizmet_id'])->exists()) {
                throw new GecersizIslemException('Hizmet '.($i + 1).' bu işletmede tanımlı değil.');
            }
            RandevuOrtak::kaynaklarSalonaAit($salonId, array_intersect_key($h, self::KAYNAK_TABLO));
        }
        unset($h);

        $yardimcilar = [];
        foreach ((array) ($g['yardimcipersoneller'] ?? []) as $y) {
            $pid = (int) ($y['yardimcipersonel']['id'] ?? 0);
            if (! $pid) {
                continue;
            }
            RandevuOrtak::kaynaklarSalonaAit($salonId, ['personel_id' => $pid]);
            $yardimcilar[] = ['hizmet_id' => (int) ($y['randevuhizmetid'] ?? 0), 'index' => (int) ($y['index'] ?? -1), 'personel_id' => $pid];
        }

        // Isletme panelinden olusturulan randevu ONAYLIDIR; duzenlemede mevcut durum korunur.
        // (Flutter editoru durum gonderir; gonderilmezse bu varsayilanlar.)
        $durum = isset($g['durum']) && $g['durum'] !== '' ? (int) $g['durum'] : ($eski ? (int) $eski->durum : Randevular::ONAYLANDI);
        if (! in_array($durum, [Randevular::BEKLENIYOR, Randevular::ONAYLANDI], true)) {
            throw new GecersizIslemException('Durum yalnızca 0 (bekliyor) ya da 1 (onaylı) olabilir.');
        }

        try {
            $saat = substr(SaatKapama::saat((string) ($g['randevu_saati'] ?? '')), 0, 5);
            $tekrar = ! $eski && filter_var($g['tekrarlayan'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $tarihler = SaatKapama::tarihler((string) ($g['randevu_tarihi'] ?? ''),
                $tekrar ? (int) ($g['tekrar_sayisi'] ?? 1) : 1, $tekrar ? ($g['tekrar_sikligi'] ?? null) : null);
            $satirlar = HizmetZinciri::satirlar($saat, $hizmetler);
            $bitis = HizmetZinciri::toplamBitis($saat, $hizmetler);
        } catch (InvalidArgumentException $e) {
            throw new GecersizIslemException($e->getMessage());
        }
        foreach ($satirlar as $i => $s) {
            $hizmetler[$i]['sure_dk'] = $s['sure_dk'];
        }

        // Guncelleme: zaman / hizmet degisti mi (bildirim + cakisma kosulu icin)
        $zamanDegisti = false;
        $hizmetDegisti = false;
        if ($eski) {
            $zamanDegisti = date('Y-m-d', strtotime($eski->tarih)) !== $tarihler[0]
                || substr((string) $eski->saat, 0, 5) !== $saat;
            $eskiHizmetler = DB::table('randevu_hizmetler')->where('randevu_id', $eski->id)->pluck('hizmet_id')->map(fn ($x) => (int) $x)->all();
            $yeniHizmetler = array_column($hizmetler, 'hizmet_id');
            $hizmetDegisti = (bool) (array_diff($yeniHizmetler, $eskiHizmetler) || array_diff($eskiHizmetler, $yeniHizmetler));
        }

        return [
            'eski' => $eski, 'user_id' => $userId, 'hizmetler' => $hizmetler, 'satirlar' => $satirlar,
            'yardimcilar' => $yardimcilar, 'tarihler' => $tarihler, 'saat' => $saat, 'bitis' => $bitis,
            'durum' => $durum, 'notlar' => trim((string) ($g['notlar'] ?? '')) ?: null,
            'zaman_degisti' => $zamanDegisti, 'hizmet_degisti' => $hizmetDegisti,
            'override' => (string) ($g['cakisanrandevuekle'] ?? '') === '1',
        ];
    }

    // ═════════════════════════════ CAKISMA ═════════════════════════════

    /**
     * Eski isletme akisi ile ayni sira ve kosullar (hepsi salon 'cakisma_uyarisi_aktif'
     * ayarina bagli; ayar KAPALI ise hicbir kontrol yok):
     *   1) ODA (SERT): oda secilmemis satirda otomatik oda adaylarinin HEPSI doluysa —
     *      "yine de olustur" ile asilamaz.
     *   2) KAYNAK (yumusak): secili personel/oda/cihaz ONAYLI randevuda dolu.
     *   3) ESKI KONTROL (yumusak): ayni baslangicta cakisan randevu + personel/cihaz/
     *      salon calisma & mola saati disi. Yalniz yeni randevu ya da zaman degistiyse.
     * Yumusaklar: durum=1 ve cakisanrandevuekle!=1 iken.
     *
     * @return array{mesaj:string,sert:bool}|null
     */
    private function cakismaKontrol(int $salonId, array $gi): ?array
    {
        if (empty(DB::table('salonlar')->where('id', $salonId)->value('cakisma_uyarisi_aktif'))) {
            return null;
        }
        $haric = $gi['eski']->id ?? null;
        $yumusak = ! $gi['override'] && $gi['durum'] !== Randevular::BEKLENIYOR;

        $eskiKontrol = '';
        if ($yumusak && (! $gi['eski'] || $gi['zaman_degisti'])) {
            $eskiKontrol = $this->eskiCakismaKontrolu($salonId, $gi, $haric);
        }

        $oda = $this->odaDoluMu($salonId, $gi, $haric);
        if ($oda !== '') {
            return ['mesaj' => $oda, 'sert' => true];
        }

        if ($yumusak) {
            $kaynak = $this->kaynakDoluMu($salonId, $gi, $haric);
            if ($kaynak !== '') {
                return ['mesaj' => $kaynak, 'sert' => false];
            }
        }

        return $eskiKontrol !== '' ? ['mesaj' => $eskiKontrol, 'sert' => false] : null;
    }

    /** Eski _kaynak_cakisma_tekil_api: ilk dolu kaynakta mesaj. */
    private function kaynakDoluMu(int $salonId, array $gi, ?int $haric): string
    {
        foreach ($gi['tarihler'] as $tarih) {
            foreach ($gi['hizmetler'] as $i => $h) {
                $s = $gi['satirlar'][$i];
                foreach (['personel_id' => 'personel', 'oda_id' => 'oda', 'cihaz_id' => 'cihaz'] as $kolon => $tip) {
                    if (! $h[$kolon]) {
                        continue;
                    }
                    $dolu = DB::table('randevular as r')->join('randevu_hizmetler as rh', 'r.id', '=', 'rh.randevu_id')
                        ->where('r.tarih', $tarih)->where('r.durum', 1)->where('r.salon_id', $salonId)
                        ->where('rh.'.$kolon, $h[$kolon])
                        ->when($haric, fn ($q) => $q->where('r.id', '!=', $haric))
                        ->get(['rh.saat', 'rh.saat_bitis', 'rh.hizmet_id', 'rh.personel_id']);
                    foreach ($dolu as $d) {
                        if (! Cakisma::ortusur($s['saat'], $s['saat_bitis'], (string) $d->saat, (string) $d->saat_bitis)) {
                            continue;
                        }
                        $ad = $this->kaynakAdi($tip, $h[$kolon]);
                        $islem = $d->hizmet_id ? (DB::table('hizmetler')->where('id', $d->hizmet_id)->value('hizmet_adi') ?: 'işlem') : 'işlem';
                        $saat = substr((string) $d->saat, 0, 5);
                        $kim = $d->personel_id ? $this->kaynakAdi('personel', $d->personel_id).' personel ' : '';

                        return match ($tip) {
                            'personel' => $ad.' personel '.$islem.' işleminde ('.$saat.') — dolu.',
                            'cihaz'    => $ad.' cihazı dolu — '.$kim.$islem.' işleminde ('.$saat.').',
                            default    => $ad.' odası dolu — '.$kim.$islem.' işleminde ('.$saat.').',
                        };
                    }
                }
            }
        }

        return '';
    }

    /** Eski odaMusaitlikCakismasi: oda secilmemis satirda tum aday odalar dolu mu. */
    private function odaDoluMu(int $salonId, array $gi, ?int $haric): string
    {
        $mesaj = '';
        foreach ($gi['tarihler'] as $tarih) {
            foreach ($gi['hizmetler'] as $i => $h) {
                if ($h['oda_id']) {
                    continue;
                }
                $adaylar = $this->odaAdaylari($salonId, $h);
                if (! $adaylar) {
                    continue;
                }
                $s = $gi['satirlar'][$i];
                if ($this->bosOda($adaylar, $tarih, $s['saat'], $s['saat_bitis'], $haric) === null) {
                    $adlar = DB::table('odalar')->whereIn('id', $adaylar)->pluck('oda_adi')->implode(', ');
                    $mesaj .= '\n'.date('d.m.Y', strtotime($tarih)).' '.$s['saat'].' : "'.$adlar.'" odası bu saatte dolu.';
                }
            }
        }

        return $mesaj;
    }

    /** Eski cakisan_randevu_kontrol (isletme): ayni baslangic + calisma/mola saatleri. */
    private function eskiCakismaKontrolu(int $salonId, array $gi, ?int $haric): string
    {
        $m = '';
        foreach ($gi['tarihler'] as $tarih) {
            $gun = (int) date('N', strtotime($tarih));
            $tStr = date('d.m.Y', strtotime($tarih));
            foreach ($gi['hizmetler'] as $i => $h) {
                $bas = $gi['satirlar'][$i]['saat'];

                if ($h['personel_id'] || $h['cihaz_id'] || $h['oda_id']) {
                    $ayni = DB::table('randevular as r')->join('randevu_hizmetler as rh', 'rh.randevu_id', '=', 'r.id')
                        ->join('hizmetler as hz', 'rh.hizmet_id', '=', 'hz.id')
                        ->leftJoin('salon_personelleri as sp', 'rh.personel_id', '=', 'sp.id')
                        ->where('r.tarih', $tarih)->where('rh.saat', $bas)->where('r.durum', 1)->where('r.salon_id', $salonId)
                        ->when($haric, fn ($q) => $q->where('r.id', '!=', $haric))
                        ->where(function ($q) use ($h) {
                            foreach (['personel_id', 'cihaz_id', 'oda_id'] as $k) {
                                if ($h[$k]) {
                                    $q->orWhere('rh.'.$k, $h[$k]);
                                }
                            }
                        })
                        ->get(['sp.personel_adi', 'hz.hizmet_adi', 'r.saat', 'r.saat_bitis']);
                    foreach ($ayni as $a) {
                        if ($a->saat && $a->saat_bitis && Cakisma::araliktaMi((string) $a->saat, (string) $a->saat_bitis, $bas)) {
                            $m .= '\n'.$tStr.' '.$bas.' : '.$a->personel_adi.' '.$a->hizmet_adi.' randevusu.';
                        }
                    }
                }

                foreach ([['personel', $h['personel_id'], 'salon_personelleri', 'personel_adi', 'isimli personelin'],
                          ['cihaz', $h['cihaz_id'], 'cihazlar', 'cihaz_adi', 'isimli cihazın']] as [$tip, $id, $tablo, $adKolon, $ek]) {
                    if (! $id) {
                        continue;
                    }
                    $ad = fn () => DB::table($tablo)->where('id', $id)->value($adKolon);
                    $m .= $this->saatDisiMesaj($tip.'_calisma_saatleri', $tip.'_id', $id, $gun, $bas, 'calisiyor', false,
                        fn () => '\n'.$tStr.' tarihinde '.$ad().' '.$ek.' çalışma saatinin dışına denk geliyor.');
                    $m .= $this->saatDisiMesaj($tip.'_mola_saatleri', $tip.'_id', $id, $gun, $bas, 'mola_var', true,
                        fn () => '\n'.$tStr.' tarihinde '.$ad().' '.$ek.' mola saatine denk geliyor.');
                }
                $m .= $this->saatDisiMesaj('salon_calisma_saatleri', 'salon_id', $salonId, $gun, $bas, 'calisiyor', false,
                    fn () => '\n'.$tStr.' tarihinde işletmenizin çalışma saatlerinin dışına denk geliyor. ');
                $m .= $this->saatDisiMesaj('salon_mola_saatleri', 'salon_id', $salonId, $gun, $bas, 'mola_var', true,
                    fn () => '\n'.$tStr.' tarihinde işletmenizin mola saatine denk geliyor.');
            }
        }

        return $m;
    }

    /**
     * Calisma (icindeyse sorun yok) / mola (icindeyse sorun) kontrolu.
     * $icindeyseSorun=false → calisma saati: aralik DISINDAYSA mesaj.
     */
    private function saatDisiMesaj(string $tablo, string $kolon, int $id, int $gun, string $saat, string $aktifKolon, bool $icindeyseSorun, callable $mesaj): string
    {
        $a = DB::table($tablo)->where($kolon, $id)->where($aktifKolon, 1)->where('haftanin_gunu', $gun)
            ->first(['baslangic_saati', 'bitis_saati']);
        if (! $a || (string) $a->baslangic_saati === '' || (string) $a->bitis_saati === '') {
            return '';
        }
        $icinde = Cakisma::araliktaMi((string) $a->baslangic_saati, (string) $a->bitis_saati, $saat);

        return $icinde === $icindeyseSorun ? $mesaj() : '';
    }

    private function kaynakAdi(string $tip, int $id): string
    {
        return match ($tip) {
            'cihaz' => DB::table('cihazlar')->where('id', $id)->value('cihaz_adi') ?: 'Cihaz',
            'oda'   => DB::table('odalar')->where('id', $id)->value('oda_adi') ?: 'Oda',
            default => DB::table('salon_personelleri')->where('id', $id)->value('personel_adi') ?: 'Personel',
        };
    }

    // ═════════════════════════════ ODA ═════════════════════════════

    /** Oda cakisma kontrolu icin adaylar (eski odaMusaitlikCakismasi): personelin odasi; yoksa hizmet odalari. */
    private function odaAdaylari(int $salonId, array $h): array
    {
        $p = $h['personel_id'] ? $this->personelOdasi($salonId, $h['personel_id']) : null;

        return $p ? [$p] : $this->hizmetOdalari($salonId, $h['hizmet_id']);
    }

    /** Hizmeti sunan aktif odalar (oda_sunulan_hizmetler), takvim sirasina gore. */
    private function hizmetOdalari(int $salonId, int $hizmetId): array
    {
        $svc = DB::table('oda_sunulan_hizmetler')->where('salon_id', $salonId)->where('hizmet_id', $hizmetId)->pluck('oda_id')->all();
        if (! $svc) {
            return [];
        }

        return DB::table('odalar')->whereIn('id', $svc)->where('salon_id', $salonId)->where('aktifmi', 1)
            ->orderBy('takvim_sirasi')->pluck('id')->map(fn ($x) => (int) $x)->all();
    }

    private function bosOda(array $adaylar, string $tarih, string $bas, string $bit, ?int $haric): ?int
    {
        foreach ($adaylar as $oda) {
            $dolu = DB::table('randevu_hizmetler as rh')->join('randevular as r', 'rh.randevu_id', '=', 'r.id')
                ->where('rh.oda_id', $oda)->where('r.tarih', $tarih)->where('r.durum', '<', 2)
                ->when($haric, fn ($q) => $q->where('r.id', '!=', $haric))
                ->where('rh.saat', '<', $bit)->where('rh.saat_bitis', '>', $bas)->exists();
            if (! $dolu) {
                return $oda;
            }
        }

        return null;
    }

    // ═════════════════════════════ PLAN ═════════════════════════════

    /**
     * Ne yazilacak (yazmaz). Seans adaylari tarihler arasinda bellekte rezerve
     * edilir ki tekrarli randevuda ayni kalan seans iki kez secilmesin.
     */
    private function planla(int $salonId, array $gi, array $rezerve): array
    {
        $eski = $gi['eski'];
        $haric = $eski->id ?? null;
        $havuz = $eski ? DB::table('adisyon_paket_seanslar')->where('randevu_id', $eski->id)->orderBy('id')->get()->groupBy('hizmet_id')->map->values()->all() : [];
        $tuketilen = [];
        $ahHavuz = $eski ? DB::table('adisyon_hizmetler')->where('randevu_id', $eski->id)->orderBy('id')->get(['id', 'hizmet_id', 'fiyat'])->groupBy('hizmet_id')->map->values()->all() : [];
        $ahTuk = [];
        $telafiKullanilan = [];

        $gunler = [];
        foreach ($gi['tarihler'] as $tarih) {
            $kalemler = [];
            foreach ($gi['hizmetler'] as $i => $h) {
                $s = $gi['satirlar'][$i];
                // Oda otomatik atama (eski kural): secilmemisse personelin odasi (dolu olsa
                // bile); yoksa hizmeti sunan aktif odalardan BOS olan, hepsi doluysa ilki.
                $oda = $h['oda_id'];
                $odaOto = false;
                if (! $oda) {
                    $oda = $h['personel_id'] ? $this->personelOdasi($salonId, $h['personel_id']) : null;
                    if (! $oda && ($adaylar = $this->hizmetOdalari($salonId, $h['hizmet_id']))) {
                        $oda = $this->bosOda($adaylar, $tarih, $s['saat'], $s['saat_bitis'], $haric) ?? $adaylar[0];
                    }
                    $odaOto = (bool) $oda;
                }

                // Seans: havuz (guncelleme) → bekleyen telafi → yeni seans adayi
                $hz = $h['hizmet_id'];
                $seans = null;
                $k = $tuketilen[$hz] ?? 0;
                if (isset($havuz[$hz][$k])) {
                    $seans = ['islem' => 'havuz_guncelle', 'aps_id' => (int) $havuz[$hz][$k]->id];
                    $tuketilen[$hz] = $k + 1;
                } else {
                    $tuketilen[$hz] = $k + 1;
                    $telafi = $this->bekleyenTelafi($salonId, $gi['user_id'], $hz, $tarih, $telafiKullanilan);
                    if ($telafi) {
                        $telafiKullanilan[] = $telafi;
                        $seans = ['islem' => 'telafi_bagla', 'aps_id' => $telafi];
                    } else {
                        $seans = $this->tercihEdilenSeans($salonId, $gi['user_id'], $h, $rezerve)
                            ?? $this->yeniSeansAdayi($salonId, $gi['user_id'], $hz, $rezerve);
                    }
                }

                $ahFiyat = null;
                $ak = $ahTuk[$hz] ?? 0;
                if (isset($ahHavuz[$hz][$ak])) {
                    $ahFiyat = ['adisyon_hizmet_id' => (int) $ahHavuz[$hz][$ak]->id, 'eski' => $ahHavuz[$hz][$ak]->fiyat, 'yeni' => $h['fiyat']];
                    $ahTuk[$hz] = $ak + 1;
                }

                $yardimci = array_values(array_map(fn ($y) => $y['personel_id'],
                    array_filter($gi['yardimcilar'], fn ($y) => $y['hizmet_id'] === $hz && $y['index'] === $i)));

                $kalemler[] = [
                    'hizmet_id' => $hz, 'personel_id' => $h['personel_id'], 'cihaz_id' => $h['cihaz_id'],
                    'oda_id' => $oda, 'oda_otomatik' => $odaOto, 'sure_dk' => $s['sure_dk'], 'fiyat' => $h['fiyat'],
                    'dusum_miktari' => $h['dusum_miktari'], 'saat' => $s['saat'], 'saat_bitis' => $s['saat_bitis'],
                    'seans' => $seans, 'adisyon_fiyat' => $ahFiyat, 'yardimci_personel' => $yardimci,
                ];
            }
            $gunler[] = ['tarih' => $tarih, 'kalemler' => $kalemler];
        }

        // Guncelleme: karsiligi kalmayan + isaretlenmemis seanslar serbest
        $serbest = [];
        foreach ($havuz as $hz => $liste) {
            foreach ($liste as $idx => $aps) {
                if ($idx >= ($tuketilen[$hz] ?? 0) && $aps->geldi === null) {
                    $serbest[] = (int) $aps->id;
                }
            }
        }

        return [
            'randevu' => [
                'id' => $eski->id ?? null, 'user_id' => $gi['user_id'], 'saat' => $gi['saat'], 'saat_bitis' => $gi['bitis'],
                'durum' => $gi['durum'], 'notlar' => $gi['notlar'],
            ],
            'gunler' => $gunler,
            'seans_serbest' => $serbest,
            'zaman_degisti' => $gi['zaman_degisti'],
            'hizmet_degisti' => $gi['hizmet_degisti'],
        ];
    }

    private function personelOdasi(int $salonId, int $personelId): ?int
    {
        $o = DB::table('odalar')->where('salon_id', $salonId)->where('personel_id', $personelId)->where('aktifmi', 1)->value('id');

        return $o ? (int) $o : null;
    }

    /** Eski DersSeansServisi::bekleyenTelafi (once tek hizmet satisi, sonra paket). */
    private function bekleyenTelafi(int $salonId, int $userId, int $hizmetId, string $tarih, array $haric): ?int
    {
        foreach ([['adisyon_hizmetler', 'aps.adisyon_hizmet_id'], ['adisyon_paketler', 'aps.adisyon_paket_id']] as [$tablo, $fk]) {
            $id = DB::table('adisyon_paket_seanslar as aps')->join($tablo.' as x', $fk, '=', 'x.id')
                ->join('adisyonlar as a', 'x.adisyon_id', '=', 'a.id')
                ->where('aps.geldi', 2)->where('aps.hizmet_id', $hizmetId)
                ->where('a.user_id', $userId)->where('a.salon_id', $salonId)
                ->whereDate('aps.seans_tarih', '<', $tarih)
                ->when($haric, fn ($q) => $q->whereNotIn('aps.id', $haric))
                ->orderBy('aps.seans_tarih')->value('aps.id');
            if ($id) {
                return (int) $id;
            }
        }

        return null;
    }

    /**
     * Paket randevusu formunda SECILEN satis (adisyon_paket_id / adisyon_hizmet_id)
     * gecerliyse seansi ONDAN dus. Gecerli = ayni musteri + salon, bu hizmeti iceriyor,
     * seans_sayisi > 0, otomatik_randevu_olusturuldu != 1 ve kalan seans var.
     * Gecersizse null → eski otomatik aday secimi (en eski satis).
     *
     * Bilincli fark: eski sistem (web + mobil) bu alanlari YOK SAYIP ayni hizmeti
     * iceren en eski satisi seciyordu; kullanici A paketini secse B'den dusebiliyordu.
     */
    private function tercihEdilenSeans(int $salonId, int $userId, array $h, array &$rezerve): ?array
    {
        $hz = $h['hizmet_id'];
        if ($h['adisyon_paket_id']) {
            $q = DB::table('adisyon_paketler as ap')->join('adisyonlar as a', 'ap.adisyon_id', '=', 'a.id')
                ->join('paket_hizmetler as ph', 'ph.paket_id', '=', 'ap.paket_id')
                ->where('ap.id', $h['adisyon_paket_id'])->where('ph.hizmet_id', $hz)
                ->where('a.user_id', $userId)->where('a.salon_id', $salonId)->where('ap.seans_sayisi', '>', 0);
            $ap = RandevuOrtak::otomatikRandevuDegil($q, 'ap')->first(['ap.id', 'ap.seans_sayisi', 'ap.kullanilan_seans', 'ap.kullanilmayan_seans']);
            $anahtar = 'AP:'.($ap->id ?? 0).':'.$hz;
            if ($ap && DB::table('adisyon_paket_seanslar')->where('adisyon_paket_id', $ap->id)->where('hizmet_id', $hz)->count() + ($rezerve[$anahtar] ?? 0) < (int) $ap->seans_sayisi) {
                $rezerve[$anahtar] = ($rezerve[$anahtar] ?? 0) + 1;

                return ['islem' => 'yeni_seans', 'adisyon_paket_id' => (int) $ap->id, 'tercih' => true,
                    'seans_no' => (int) $ap->kullanilan_seans + (int) $ap->kullanilmayan_seans + 1];
            }
        }
        if ($h['adisyon_hizmet_id']) {
            $q = DB::table('adisyon_hizmetler as ah')->join('adisyonlar as a', 'ah.adisyon_id', '=', 'a.id')
                ->where('ah.id', $h['adisyon_hizmet_id'])->where('ah.hizmet_id', $hz)
                ->where('a.user_id', $userId)->where('a.salon_id', $salonId)->where('ah.seans_sayisi', '>', 0);
            $ah = RandevuOrtak::otomatikRandevuDegil($q, 'ah')->first(['ah.id', 'ah.seans_sayisi', 'ah.kullanilan_seans', 'ah.kullanilmayan_seans']);
            $anahtar = 'AH:'.($ah->id ?? 0);
            if ($ah && DB::table('adisyon_paket_seanslar')->where('adisyon_hizmet_id', $ah->id)->count() + ($rezerve[$anahtar] ?? 0) < (int) $ah->seans_sayisi) {
                $rezerve[$anahtar] = ($rezerve[$anahtar] ?? 0) + 1;

                return ['islem' => 'yeni_seans', 'adisyon_hizmet_id' => (int) $ah->id, 'tercih' => true,
                    'seans_no' => (int) $ah->kullanilan_seans + (int) $ah->kullanilmayan_seans + 1];
            }
        }

        return null;
    }

    /**
     * Eski yeni-seans aday secimi: kalan seansi olan tek hizmet satisi ve paket;
     * ikisi de varsa adisyon tarihi ERKEN olan (esitse paket). Bulunamazsa null.
     * $rezerve: [ 'AH:id' => n, 'AP:id:hizmet' => n ] — ayni istek icinde ayrilan.
     */
    private function yeniSeansAdayi(int $salonId, int $userId, int $hizmetId, array &$rezerve): ?array
    {
        $hizmet = null;
        $q = DB::table('adisyon_hizmetler as ah')->join('adisyonlar as a', 'ah.adisyon_id', '=', 'a.id')
            ->where('ah.hizmet_id', $hizmetId)->where('a.user_id', $userId)->where('a.salon_id', $salonId)->where('ah.seans_sayisi', '>', 0);
        foreach (RandevuOrtak::otomatikRandevuDegil($q, 'ah')->orderBy('a.tarih')->get(['ah.id', 'ah.seans_sayisi', 'ah.kullanilan_seans', 'ah.kullanilmayan_seans', 'a.tarih']) as $x) {
            $kul = DB::table('adisyon_paket_seanslar')->where('adisyon_hizmet_id', $x->id)->count() + ($rezerve['AH:'.$x->id] ?? 0);
            if ($kul < (int) $x->seans_sayisi) {
                $hizmet = $x;
                break;
            }
        }

        $paket = null;
        $q = DB::table('adisyon_paketler as ap')->join('adisyonlar as a', 'ap.adisyon_id', '=', 'a.id')
            ->join('paket_hizmetler as ph', 'ph.paket_id', '=', 'ap.paket_id')
            ->where('ph.hizmet_id', $hizmetId)->where('a.user_id', $userId)->where('a.salon_id', $salonId)->where('ap.seans_sayisi', '>', 0);
        foreach (RandevuOrtak::otomatikRandevuDegil($q, 'ap')->orderBy('a.tarih')->distinct()->get(['ap.id', 'ap.seans_sayisi', 'ap.kullanilan_seans', 'ap.kullanilmayan_seans', 'a.tarih']) as $x) {
            $kul = DB::table('adisyon_paket_seanslar')->where('adisyon_paket_id', $x->id)->where('hizmet_id', $hizmetId)->count()
                + ($rezerve['AP:'.$x->id.':'.$hizmetId] ?? 0);
            if ($kul < (int) $x->seans_sayisi) {
                $paket = $x;
                break;
            }
        }

        $hizmetSec = $hizmet && (! $paket || date('Y-m-d', strtotime($hizmet->tarih)) < date('Y-m-d', strtotime($paket->tarih)));
        if ($hizmetSec) {
            $rezerve['AH:'.$hizmet->id] = ($rezerve['AH:'.$hizmet->id] ?? 0) + 1;

            return ['islem' => 'yeni_seans', 'adisyon_hizmet_id' => (int) $hizmet->id,
                'seans_no' => (int) $hizmet->kullanilan_seans + (int) $hizmet->kullanilmayan_seans + 1];
        }
        if ($paket) {
            $rezerve['AP:'.$paket->id.':'.$hizmetId] = ($rezerve['AP:'.$paket->id.':'.$hizmetId] ?? 0) + 1;

            return ['islem' => 'yeni_seans', 'adisyon_paket_id' => (int) $paket->id,
                'seans_no' => (int) $paket->kullanilan_seans + (int) $paket->kullanilmayan_seans + 1];
        }

        return null; // paket/seans randevusu degil
    }

    // ═════════════════════════════ YAZMA ═════════════════════════════

    private function uygula(int $salonId, array $gi, array $plan, ?int $yetkiliId): array
    {
        $eski = $gi['eski'];
        $randevuIdler = [];
        $yeniRh = [];
        $silinenRh = [];

        foreach ($plan['gunler'] as $gun) {
            $alanlar = [
                'user_id' => $gi['user_id'], 'salon_id' => $salonId, 'tarih' => $gun['tarih'], 'saat' => $gi['saat'],
                'saat_bitis' => $gi['bitis'], 'personel_notu' => $gi['notlar'], 'durum' => $gi['durum'], 'updated_at' => now(),
            ];
            if ($eski) {
                $randevuId = (int) $eski->id;
                DB::table('randevular')->where('id', $randevuId)->update($alanlar);
                $silinenRh = DB::table('randevu_hizmetler')->where('randevu_id', $randevuId)->pluck('id')->map(fn ($x) => (int) $x)->all();
                DB::table('randevu_hizmetler')->where('randevu_id', $randevuId)->delete();
                if ($eski->on_gorusme_id) {
                    $og = ['hatirlatma_tarihi' => $gun['tarih'], 'on_gorusme_saati' => $gi['saat'], 'updated_at' => now()];
                    if ($gun['kalemler'][0]['personel_id'] ?? null) {
                        $og['personel_id'] = $gun['kalemler'][0]['personel_id'];
                    }
                    DB::table('on_gorusmeler')->where('id', $eski->on_gorusme_id)->update($og);
                }
            } else {
                $randevuId = DB::table('randevular')->insertGetId($alanlar + [
                    'salon' => 1, 'olusturan_personel_id' => $yetkiliId, 'created_at' => now(),
                ]);
            }
            $randevuIdler[] = $randevuId;

            foreach ($gun['kalemler'] as $k) {
                $rh = [
                    'randevu_id' => $randevuId, 'hizmet_id' => $k['hizmet_id'], 'cihaz_id' => $k['cihaz_id'],
                    'personel_id' => $k['personel_id'], 'oda_id' => $k['oda_id'], 'sure_dk' => $k['sure_dk'],
                    'fiyat' => $k['fiyat'], 'saat' => $k['saat'], 'saat_bitis' => $k['saat_bitis'],
                    'created_at' => now(), 'updated_at' => now(),
                ] + ($k['dusum_miktari'] !== null ? ['dusum_miktari' => $k['dusum_miktari']] : []);
                $yeniRh[] = DB::table('randevu_hizmetler')->insertGetId($rh);

                foreach ($k['yardimci_personel'] as $pid) {
                    $yeniRh[] = DB::table('randevu_hizmetler')->insertGetId(['personel_id' => $pid, 'yardimci_personel' => 1] + $rh);
                }

                if ($k['adisyon_fiyat']) {
                    DB::table('adisyon_hizmetler')->where('id', $k['adisyon_fiyat']['adisyon_hizmet_id'])->update(['fiyat' => $k['fiyat']]);
                }

                $s = $k['seans'];
                $apsAlan = ['seans_tarih' => $gun['tarih'], 'seans_saat' => $k['saat'], 'personel_id' => $k['personel_id'],
                    'cihaz_id' => $k['cihaz_id'], 'oda_id' => $k['oda_id']];
                if ($s && $s['islem'] === 'havuz_guncelle') {
                    DB::table('adisyon_paket_seanslar')->where('id', $s['aps_id'])->update($apsAlan);
                } elseif ($s && $s['islem'] === 'telafi_bagla') {
                    // Bekleyen telafi bu randevuya baglanir; geldi=2 KALIR (yeni seans dusmez).
                    DB::table('adisyon_paket_seanslar')->where('id', $s['aps_id'])->update($apsAlan + ['randevu_id' => $randevuId]);
                } elseif ($s && $s['islem'] === 'yeni_seans') {
                    DB::table('adisyon_paket_seanslar')->insert($apsAlan + [
                        'randevu_id' => $randevuId, 'hizmet_id' => $k['hizmet_id'], 'seans_no' => $s['seans_no'],
                        'adisyon_hizmet_id' => $s['adisyon_hizmet_id'] ?? null, 'adisyon_paket_id' => $s['adisyon_paket_id'] ?? null,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
        }

        if ($plan['seans_serbest']) {
            DB::table('adisyon_paket_seanslar')->whereIn('id', $plan['seans_serbest'])->whereNull('geldi')->delete();
        }

        // Yan etkiler (outbox): bildirimler (monolit randevuKayitBildirim*) + Google Takvim.
        BildirimKuyrugu::yaz($salonId, 'randevu_kayit', $randevuIdler[0], $yetkiliId, [
            'randevu_idler'  => $randevuIdler,
            'guncelleme'     => (bool) $eski,
            'zaman_degisti'  => $gi['zaman_degisti'],
            'hizmet_degisti' => $gi['hizmet_degisti'],
            'istek'          => [
                'randevu_tarihi' => $gi['tarihler'][0], 'randevu_saati' => $gi['saat'], 'randevuKaynak' => 'salon',
                'durum' => $gi['durum'], 'olusturan' => $yetkiliId,
            ],
        ]);
        BildirimKuyrugu::googleTakvim($salonId, $randevuIdler[0], $yeniRh, $silinenRh);

        return ['randevu_idler' => $randevuIdler, 'plan' => $plan, 'bildirimler' => ['randevu_kayit', 'google_takvim']];
    }
}
