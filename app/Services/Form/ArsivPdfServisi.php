<?php

namespace App\Services\Form;

use App\Exceptions\BulunamadiException;
use App\Models\Arsiv;
use App\Models\FormTaslaklari;
use App\Models\Personeller;
use App\Models\Salonlar;
use App\Models\User;
use App\Support\Yetki\YetkiSabitleri;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;

/**
 * Arşiv kaydından PDF üretimi — TEK KAYNAK. Eski kaynak: ApiController@arsivPdfUret,
 * sozlesmePdfYukle, htmlOnayKoduEkle ve StoreAdminController@bosFormIndirDinamik.
 * Blade dosyaları resources/views/form-pdf altında eskisiyle aynıdır.
 *
 * Bilinçli fark: `musteri.telefon_gor` yetkisi olmayan kullanıcının PDF'inde telefon maskelidir
 * (eski uç kimliksizdi ve numarayı herkese veriyordu).
 */
class ArsivPdfServisi
{
    /**
     * Sözleşme ve form için ['pdf', 'ad'], harici belge için ['dosya', 'ad'] döner.
     *
     * @return array{ad:string,pdf?:mixed,dosya?:string}
     */
    public function belge(int $arsivId, bool $telGor): array
    {
        $arsiv = Arsiv::whereKey($arsivId)->first();
        if (! $arsiv) {
            throw new BulunamadiException('Kayıt bulunamadı.');
        }
        $taslak = FormTaslaklari::whereKey($arsiv->form_id)->first();
        $ad = $this->dosyaAdi($taslak ? (string) $taslak->form_adi : ($arsiv->is_sozlesme ? 'Hizmet Sozlesmesi' : 'form'));

        if ($arsiv->is_sozlesme) {
            return ['ad' => $ad, 'pdf' => $this->sozlesme($this->baglantilar($arsiv, $telGor))];
        }
        if ((int) $arsiv->form_id !== 0) {
            return ['ad' => $ad, 'pdf' => $this->form($this->baglantilar($arsiv, $telGor), $taslak, (string) ($taslak->form_adi ?? ''))];
        }

        return ['ad' => $ad, 'dosya' => $this->hariciDosya($arsiv)];
    }

    /** Salonun kendi şablonu; yoksa 404. */
    public function bosTaslak(int $salonId, int $formId): FormTaslaklari
    {
        $taslak = FormTaslaklari::where('salon_id', $salonId)->whereKey($formId)->first();
        if (! $taslak) {
            throw new BulunamadiException('Form bulunamadı.');
        }

        return $taslak;
    }

    /** Boş form PDF'i. Kişisel veri içermez. */
    public function bosForm(int $salonId, int $formId): array
    {
        $taslak = $this->bosTaslak($salonId, $formId);

        return [
            'ad'  => $this->dosyaAdi((string) $taslak->form_adi),
            'pdf' => Pdf::loadView('form-pdf.dinamik_form_bos_pdf', [
                'form_adi' => $taslak->form_adi,
                'aciklama' => $taslak->aciklama ?? '',
                'sorular'  => $taslak->sorular_json ? json_decode($taslak->sorular_json, true) : [],
                'isletme'  => $this->isletme($salonId),
            ])->setOption(['defaultFont' => 'DejaVu Sans']),
        ];
    }

    // ---------------------------------------------------------------------

    private function sozlesme(Arsiv $arsiv): mixed
    {
        $hizmetAdi = $arsiv->hizmet_id ? DB::table('salon_sunulan_hizmetler as sh')
            ->leftJoin('hizmetler as h', 'sh.hizmet_id', '=', 'h.id')
            ->where('sh.id', $arsiv->hizmet_id)->value('h.hizmet_adi') : null;
        $paketAdi = $arsiv->paket_id ? DB::table('paketler')->where('id', $arsiv->paket_id)->value('paket_adi') : null;

        return Pdf::loadView('form-pdf.sozlesme_pdf', [
            'arsiv'      => $arsiv,
            'isletme'    => $this->isletme((int) $arsiv->salon_id),
            'hizmet_adi' => $hizmetAdi,
            'paket_adi'  => $paketAdi,
        ])->setOption(['defaultFont' => 'DejaVu Sans']);
    }

    private function form(Arsiv $arsiv, ?FormTaslaklari $taslak, string $baslik): mixed
    {
        $isletme = $this->isletme((int) $arsiv->salon_id);
        if ($taslak && $taslak->is_dinamik) {
            return Pdf::loadView('form-pdf.dinamik_form_pdf', [
                'arsiv'    => $arsiv,
                'isletme'  => $isletme,
                'sorular'  => $taslak->sorular_json ? json_decode($taslak->sorular_json, true) : [],
                'cevaplar' => $this->cevaplar($arsiv->cevaplar_json),
                'form_adi' => $baslik,
                'aciklama' => $taslak->aciklama ?? '',
            ])->setOption(['defaultFont' => 'DejaVu Sans']);
        }

        $gorunum = 'form-pdf.'.(string) ($taslak->taslak ?? '');
        if (! $taslak?->taslak || ! View::exists($gorunum)) {
            throw new BulunamadiException('Form bulunamadı.');
        }
        $html = view($gorunum, ['title' => date('Y-m-d-H-i-s'), 'arsiv' => $arsiv, 'isletme' => $isletme])->render();

        return Pdf::loadHTML($this->onayKoduEkle($html, $arsiv))->setOption(['defaultFont' => 'sans-serif']);
    }

    /** Blade'lerin okuduğu ilişkiler; model tablosunda `$with` yoktur (veri minimizasyonu). */
    private function baglantilar(Arsiv $arsiv, bool $telGor): Arsiv
    {
        $musteri = $arsiv->user_id ? User::whereKey($arsiv->user_id)->first() : null;
        if ($musteri && ! $telGor) {
            $musteri->cep_telefon = YetkiSabitleri::telefonMaskele($musteri->cep_telefon);
        }
        $arsiv->setRelation('musteri', $musteri);
        $arsiv->setRelation('personel', $arsiv->personel_id ? Personeller::whereKey($arsiv->personel_id)->first() : null);
        $arsiv->setRelation('hizmet', $arsiv->hizmet_id ? DB::table('hizmetler')->where('id', $arsiv->hizmet_id)->first() : null);

        return $arsiv;
    }

    private function isletme(int $salonId): ?Salonlar
    {
        $isletme = Salonlar::whereKey($salonId)->first();
        $isletme?->setRelation('il', $isletme->il_id ? DB::table('il')->where('id', $isletme->il_id)->first() : null);

        return $isletme;
    }

    /** @return array<int|string,mixed> soru indeksi => cevap */
    private function cevaplar(?string $json): array
    {
        $cevaplar = [];
        foreach (($json ? json_decode($json, true) : null) ?: [] as $satir) {
            $cevaplar[$satir['indeks']] = $satir['cevap'];
        }

        return $cevaplar;
    }

    /** Yüklenen dosya yalnız harici belge dizininden servis edilir; yol parçaları atılır. */
    private function hariciDosya(Arsiv $arsiv): string
    {
        $ad = basename((string) $arsiv->uzanti);
        $yol = rtrim((string) config('form.harici_dizin'), '/').'/'.$ad;
        if ($ad === '' || ! is_file($yol)) {
            throw new BulunamadiException('Dosya bulunamadı.');
        }

        return $yol;
    }

    /** İndirme başlığı `/` ve `\` kabul etmez; form adı serbest metindir. */
    private function dosyaAdi(string $baslik): string
    {
        return str_replace(['/', '\\', '%'], '-', $baslik);
    }

    private function onayKoduEkle(string $html, Arsiv $arsiv): string
    {
        $alt = $this->onayKoduAltligi($arsiv);

        return stripos($html, '</body>') !== false ? str_ireplace('</body>', $alt.'</body>', $html) : $html.$alt;
    }

    private function onayKoduAltligi(Arsiv $arsiv): string
    {
        $kod = $arsiv->dogrulama_kodu ?? '-';
        $tarih = $arsiv->created_at ? date('d.m.Y H:i', strtotime($arsiv->created_at)) : '';
        $imzaZaman = $arsiv->imza_zaman ? date('d.m.Y H:i:s', strtotime($arsiv->imza_zaman)) : '';
        $ip = $arsiv->imza_ip ?? '';
        $kvkk = $arsiv->kvkk_onay ? '<span style="color:#28a745;"><b>&#10003; KVKK Onayi</b></span>' : '';
        $ekler = [];
        if ($imzaZaman) {
            $ekler[] = 'Imza: '.$imzaZaman;
        }
        if ($ip) {
            $ekler[] = 'IP: '.e($ip);
        }
        if ($kvkk) {
            $ekler[] = $kvkk;
        }
        $ekHtml = $ekler ? '<div style="font-size:9px; color:#666; margin-top:3px;">'.implode(' &nbsp;|&nbsp; ', $ekler).'</div>' : '';

        return '<div style="margin-top:8px; padding:6px 10px; background:#fff8dc; border:1px solid #d4a017; text-align:center; font-size:11px; font-family: DejaVu Sans, sans-serif;">
            <b>SMS ONAY KODU:</b> <span style="letter-spacing:4px; font-weight:bold; font-size:13px;">'.e($kod).'</span>
            '.($tarih ? ' &nbsp;|&nbsp; Tarih: '.$tarih : '').'
            '.$ekHtml.'
        </div>';
    }
}
