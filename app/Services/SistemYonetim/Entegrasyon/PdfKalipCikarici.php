<?php

namespace App\Services\SistemYonetim\Entegrasyon;

use App\Exceptions\GecersizIslemException;
use App\Exceptions\HariciServisException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * PDF → soru-cevap kaliplari (eski PatronAsistanServisi::pdftenKalipCikar). PDF dogrudan Claude'a document olarak
 * gider, sunucuda parser gerekmez. Stateless: sonuc istemciye doner, kullanici duzenleyip kaydet ucuna gonderir.
 */
class PdfKalipCikarici
{
    private const MAKS_BAYT = 20 * 1024 * 1024;

    private const MAKS_TOKEN = 3000;

    private const ZAMAN_ASIMI_SN = 90;

    private const API_SURUMU = '2023-06-01';

    private const SISTEM = 'Sen bir salon isletme asistani icin EGITIM verisi hazirliyorsun. '
        .'Verilen PDF belgesinden, kullanicinin asistana sorabilecegi net SORU-CEVAP ciftleri cikar. '
        .'KURALLAR: '
        .'1) SADECE gecerli JSON dizi dondur; ONUNDE/ARKASINDA hicbir aciklama yazma. '
        .'2) Her oge tam olarak: {"tetikleyiciler":"...","cevap":"...","kategori":"..."} . '
        .'3) tetikleyiciler CUMLE DEGIL, virgulle ayrilmis KISA anahtar kelime kaliplari olsun (2-5 tane). Ornek: "wifi sifresi, internet sifresi, kablosuz sifre". '
        .'4) cevap kisa, net, dogrudan bilgi (en fazla 2-3 cumle). '
        .'5) Belgede OLMAYAN bilgiyi UYDURMA; bilgi yoksa o ogeyi hic ekleme. '
        .'6) En fazla 40 oge.';

    public function __construct(private AiKrediServisi $aiKredi) {}

    /** @return list<array{tetikleyiciler:string,cevap:string,kategori:string}> */
    public function cikar(?UploadedFile $dosya): array
    {
        $this->dogrula($dosya);
        $anahtar = (string) config('sistem-asistan.anahtar');
        if ($anahtar === '') {
            throw new HariciServisException('Anthropic API anahtarı tanımlı değil.');
        }
        $model = (string) config('sistem-asistan.model');

        $cevap = $this->istek($anahtar, $model, base64_encode((string) file_get_contents($dosya->getRealPath())));
        $this->aiKredi->kullanimYaz(null, 'pdf_egitim', (int) ($cevap['usage']['input_tokens'] ?? 0), (int) ($cevap['usage']['output_tokens'] ?? 0), $model);

        $metin = '';
        foreach ($cevap['content'] ?? [] as $blok) {
            if (($blok['type'] ?? '') === 'text') {
                $metin .= $blok['text'] ?? '';
            }
        }
        $maddeler = $this->temizle($this->jsonDizi($metin));
        if (! $maddeler) {
            throw new GecersizIslemException('Belgeden soru-cevap çıkarılamadı.');
        }

        return $maddeler;
    }

    /** Uzanti/tip + dosya imzasi; eski ekranin uzanti-tip kontrolu istemciden geliyordu, icerige de bakilir. */
    private function dogrula(?UploadedFile $dosya): void
    {
        if (! $dosya) {
            throw new GecersizIslemException('PDF seçilmedi.');
        }
        if (! $dosya->isValid()) {
            throw new GecersizIslemException('PDF yüklenemedi (sunucu dosya sınırı aşılmış olabilir).');
        }
        if ($dosya->getSize() > self::MAKS_BAYT) {
            throw new GecersizIslemException('PDF çok büyük (en fazla 20 MB).');
        }
        $tipUygun = strtolower($dosya->getClientOriginalExtension()) === 'pdf' || $dosya->getClientMimeType() === 'application/pdf';
        if (! $tipUygun || ! str_starts_with((string) file_get_contents($dosya->getRealPath(), false, null, 0, 5), '%PDF-')) {
            throw new GecersizIslemException('Lütfen bir PDF dosyası yükleyin.');
        }
    }

    private function istek(string $anahtar, string $model, string $base64): array
    {
        try {
            $r = Http::timeout(self::ZAMAN_ASIMI_SN)
                ->withHeaders(['x-api-key' => $anahtar, 'anthropic-version' => self::API_SURUMU])
                ->acceptJson()
                ->post((string) config('sistem-asistan.url'), [
                    'model'      => $model,
                    'max_tokens' => self::MAKS_TOKEN,
                    'system'     => self::SISTEM,
                    'messages'   => [[
                        'role'    => 'user',
                        'content' => [
                            ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => $base64]],
                            ['type' => 'text', 'text' => 'Bu belgeden soru-cevap kaliplarini cikar ve SADECE JSON dizi dondur.'],
                        ],
                    ]],
                ]);
        } catch (ConnectionException $e) {
            Log::warning('[asistan pdf] AI servisine ulasilamadi', ['hata' => $e->getMessage()]);

            throw new HariciServisException('PDF işlenemedi: AI servisine ulaşılamadı.');
        }
        if (! $r->successful()) {
            Log::warning('[asistan pdf] AI hatasi', ['kod' => $r->status()]);

            throw new HariciServisException('PDF işlenemedi: AI hatası (http '.$r->status().').');
        }
        $govde = $r->json();
        if (! is_array($govde) || empty($govde['content'])) {
            throw new HariciServisException('PDF işlenemedi: AI boş yanıt döndü.');
        }

        return $govde;
    }

    /** AI metninden ilk JSON dizi (kod blogu/aciklama olsa da). */
    private function jsonDizi(string $metin): array
    {
        $m = (string) preg_replace(['/^```[a-z]*\s*/i', '/\s*```$/'], '', trim($metin));
        $d = json_decode($m, true);
        if (is_array($d)) {
            return $d;
        }
        $bas = strpos($m, '[');
        $son = strrpos($m, ']');
        if ($bas === false || $son === false || $son <= $bas) {
            return [];
        }
        $d = json_decode(substr($m, $bas, $son - $bas + 1), true);

        return is_array($d) ? $d : [];
    }

    private function temizle(array $liste): array
    {
        $temiz = [];
        foreach ($liste as $o) {
            $t = is_array($o) ? trim((string) ($o['tetikleyiciler'] ?? '')) : '';
            $c = is_array($o) ? trim((string) ($o['cevap'] ?? '')) : '';
            if ($t !== '' && $c !== '') {
                $temiz[] = [
                    'tetikleyiciler' => mb_substr($t, 0, 500),
                    'cevap'          => mb_substr($c, 0, 1500),
                    'kategori'       => mb_substr(trim((string) ($o['kategori'] ?? '')), 0, 40) ?: 'pdf',
                ];
            }
        }

        return $temiz;
    }
}
