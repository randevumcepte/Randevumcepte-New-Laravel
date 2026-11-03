<?php

namespace App\Services\Form;

use App\Domain\Form\SabitFormlar;
use App\Domain\Form\SablonSorulari;
use App\Domain\Form\SozlesmeMetni;
use App\Exceptions\BulunamadiException;
use App\Exceptions\GecersizIslemException;
use App\Models\Arsiv;
use App\Models\FormTaslaklari;
use App\Support\AktiviteLog;
use App\Support\EskiPanelLinki;
use Illuminate\Support\Facades\DB;

/**
 * Form ŞABLONLARI (formtaslaklari) + salon varsayılan sözleşme metni — TEK KAYNAK
 * (web v2 + Flutter form_sablonlari / form_sablon_duzenle / sozlesme_olustur).
 *
 * Eski kaynak: StoreAdminController@formSablonlari* ve ApiController@formSablonlari*,
 * sozlesmeVarsayilan*. Farklar (bilinçli):
 *   - Her istekte ALTER TABLE çalıştıran kolon oluşturucu YOK (kolonlar canlıda mevcut).
 *   - sorular_json sunucuda doğrulanır; `zorunlu` tipten türetilir (SablonSorulari).
 *   - Kayıt/güncelleme/silme/sıralama salon kapsamında (form başka salonunsa 404).
 */
class FormSablonServisi
{
    /** Salonun dinamik şablonları (oluşturucu listesi). Sıra: sira asc, id desc (eskiyle aynı). */
    public function liste(int $salonId): array
    {
        return $this->salonSorgusu($salonId)->where('is_dinamik', 1)->get()
            ->map(fn (FormTaslaklari $f) => $this->donustur($f))->all();
    }

    /**
     * Form gönderim modalı seçenekleri — salonun TÜM şablonları (dinamik + eski sabit),
     * eski arsiv-form-ekle-modal sorgusuyla aynı sıra. Ek alan kuralları satırda hazır.
     */
    public function secenekler(int $salonId): array
    {
        return $this->salonSorgusu($salonId)->get(['id', 'form_adi', 'is_dinamik', 'is_sozlesme_tipi'])
            ->map(fn ($f) => [
                'id'          => (int) $f->id,
                'form_adi'    => $f->form_adi,
                'is_dinamik'  => (bool) $f->is_dinamik,
                'ek_alanlar'  => SabitFormlar::ekAlanlar((int) $f->id),
            ])->all();
    }

    public function getir(int $salonId, int $id): array
    {
        return $this->donustur($this->bul($salonId, $id));
    }

    /**
     * Yeni şablon ($id null) ya da güncelleme.
     *
     * @param array{form_adi:?string,aciklama:?string,is_sozlesme:bool,sorular:mixed} $g
     */
    public function kaydet(int $salonId, ?int $id, array $g, int $yapanId): array
    {
        $ad = trim((string) ($g['form_adi'] ?? ''));
        if ($ad === '') {
            throw new GecersizIslemException('Form adı zorunludur.');
        }
        try {
            $sorular = SablonSorulari::normalize($g['sorular'] ?? null);
        } catch (\InvalidArgumentException $e) {
            throw new GecersizIslemException($e->getMessage());
        }

        $form = $id !== null ? $this->bul($salonId, $id) : new FormTaslaklari(['salon_id' => $salonId, 'is_dinamik' => 1]);
        $form->fill([
            'form_adi'         => mb_substr($ad, 0, 200),
            'aciklama'         => trim((string) ($g['aciklama'] ?? '')) ?: null,
            'is_sozlesme_tipi' => ! empty($g['is_sozlesme']) ? 1 : 0,
            'sorular_json'     => json_encode($sorular, JSON_UNESCAPED_UNICODE),
        ])->save();

        AktiviteLog::yaz($salonId, $yapanId, $id !== null ? 'form_sablon_guncelle' : 'form_sablon_ekle', 'form_sablon',
            (int) $form->id, $form->form_adi, $id !== null ? 'Form şablonu güncellendi' : 'Yeni sözleşme/form şablonu oluşturuldu');

        return $this->donustur($form);
    }

    /** Kullanımdaysa (arşivde kaydı varsa) silinmez — eskiyle aynı kural/mesaj. */
    public function sil(int $salonId, int $id, int $yapanId): void
    {
        $form = $this->bul($salonId, $id);
        $kullanim = Arsiv::where('form_id', $form->id)->count();
        if ($kullanim > 0) {
            throw new GecersizIslemException('Bu form '.$kullanim.' kayıtta kullanılmaktadır. Silinemez.');
        }
        AktiviteLog::yaz($salonId, $yapanId, 'form_sablon_sil', 'form_sablon', (int) $form->id, $form->form_adi, 'Form şablonu silindi');
        $form->delete();
    }

    /**
     * Komşuyla yer değiştir + salonun tüm şablonlarını 0..n yeniden numarala (gönderim
     * modalı da aynı sırayı kullandığı için eski davranış korunur). Güncel liste döner.
     */
    public function sirala(int $salonId, int $id, string $yon, int $yapanId): array
    {
        if (! in_array($yon, ['yukari', 'asagi'], true)) {
            throw new GecersizIslemException('Geçersiz yön.');
        }
        $this->bul($salonId, $id);

        DB::transaction(function () use ($salonId, $id, $yon) {
            $idler = $this->salonSorgusu($salonId)->lockForUpdate()->pluck('id')->map(fn ($v) => (int) $v)->all();
            $i = array_search($id, $idler, true);
            $j = $yon === 'yukari' ? $i - 1 : $i + 1;
            if ($i === false || $j < 0 || $j >= count($idler)) {
                return;
            }
            [$idler[$i], $idler[$j]] = [$idler[$j], $idler[$i]];
            foreach ($idler as $sira => $formId) {
                FormTaslaklari::whereKey($formId)->update(['sira' => $sira]);
            }
        });
        AktiviteLog::yaz($salonId, $yapanId, 'form_sablon_sirala', 'form_sablon', $id, null, 'Form şablonu sırası değişti', ['yon' => $yon]);

        return $this->liste($salonId);
    }

    /** Salonun sözleşme şartları metni (kayıtlı değilse fabrika metni). */
    public function sozlesmeMetni(int $salonId): array
    {
        $salonMetni = DB::table('salonlar')->where('id', $salonId)->value('sozlesme_varsayilan_metin');

        return [
            'sozlesme_metni' => SozlesmeMetni::sec($salonMetni),
            'salona_ozel'    => trim((string) $salonMetni) !== '',
        ];
    }

    /** Boş metin = fabrika metnine dön (NULL yazılır). */
    public function sozlesmeMetniKaydet(int $salonId, ?string $metin, int $yapanId): array
    {
        $metin = trim((string) $metin);
        DB::table('salonlar')->where('id', $salonId)->update(['sozlesme_varsayilan_metin' => $metin !== '' ? $metin : null]);
        AktiviteLog::yaz($salonId, $yapanId, 'sozlesme_varsayilan', 'salon', $salonId, null, 'Varsayılan sözleşme şartları güncellendi');

        return $this->sozlesmeMetni($salonId);
    }

    // ---------------------------------------------------------------------

    private function salonSorgusu(int $salonId)
    {
        return FormTaslaklari::where('salon_id', $salonId)->orderBy('sira')->orderByDesc('id');
    }

    private function bul(int $salonId, int $id): FormTaslaklari
    {
        $form = FormTaslaklari::where('salon_id', $salonId)->whereKey($id)->first();
        if (! $form) {
            throw new BulunamadiException('Form bulunamadı.');
        }

        return $form;
    }

    /**
     * Şablon sözleşmesi. `sorular_json` (string) Flutter Sozlesme/form_sablon_duzenle
     * uyumu için korunur; `sorular` aynı içeriğin çözülmüş halidir.
     */
    private function donustur(FormTaslaklari $f): array
    {
        $sorular = SablonSorulari::coz($f->sorular_json);

        return [
            'id'               => (int) $f->id,
            'form_adi'         => $f->form_adi,
            'aciklama'         => $f->aciklama,
            'is_sozlesme_tipi' => (int) $f->is_sozlesme_tipi,
            'eleman_sayisi'    => count($sorular),
            'sorular'          => $sorular,
            'sorular_json'     => $f->sorular_json,
            'created_at'       => $f->created_at ? (string) $f->created_at : null,
            'tarih'            => $f->created_at ? date('d.m.Y', strtotime((string) $f->created_at)) : null,
            'bos_pdf_url'      => EskiPanelLinki::url('/isletmeyonetim/bosFormIndirDinamik', ['formId' => $f->id, 'sube' => $f->salon_id]),
        ];
    }
}
