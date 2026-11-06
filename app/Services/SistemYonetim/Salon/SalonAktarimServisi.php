<?php

namespace App\Services\SistemYonetim\Salon;

use App\Domain\SistemYonetim\UyelikKurallari;
use App\Models\SistemYoneticisi;
use App\Services\SistemYonetim\Denetim;
use App\Support\CsvCevabi;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Salon listesi ve salon musterileri CSV aktarimi. Satirlar akarak yazilir: buyuk salon bellege dolmaz. */
class SalonAktarimServisi
{
    /** Telefon Excel'de bilimsel gosterime kaymasin diye basa konan sifir genislikli bosluk. */
    private const METIN_ONEKI = "\xE2\x80\x8B";

    public function __construct(private SalonListeServisi $liste) {}

    public function salonlar(?string $ara, array $filtre, SistemYoneticisi $yonetici): StreamedResponse
    {
        $sorgu = $this->liste->sorgu($ara, $filtre, $yonetici);
        Denetim::yaz('csv_export', 'salon', null, 'Salon listesi ('.(clone $sorgu)->count().')');

        return CsvCevabi::indir('salonlar-'.date('Ymd-His').'.csv',
            ['ID', 'Salon Adı', 'İl', 'İlçe', 'Yetkili', 'Yetkili Tel', 'İşletme Tel', 'Müşteri Temsilcisi', 'Durum', 'Kayıt Tarihi'],
            $this->salonSatirlari($sorgu));
    }

    public function musteriler(object $salon): StreamedResponse
    {
        $sorgu = DB::table('musteri_portfoy')->join('users', 'musteri_portfoy.user_id', '=', 'users.id')->where('musteri_portfoy.salon_id', $salon->id);
        Denetim::yaz('excel_export', 'salon', (int) $salon->id, 'Müşteri listesi ('.(clone $sorgu)->count().') — '.$salon->salon_adi);

        $slug = Str::slug((string) $salon->salon_adi) ?: 'salon-'.$salon->id;

        return CsvCevabi::indir('musteriler-'.$slug.'-'.date('Ymd-His').'.csv',
            ['ID', 'Ad Soyad', 'Cep Telefon', 'E-posta', 'Cinsiyet', 'Doğum Tarihi', 'WhatsApp Onay', 'Durum', 'Kara Liste', 'Müşteri Tipi', 'Kayıt Tarihi'],
            $this->musteriSatirlari($sorgu));
    }

    private function salonSatirlari($sorgu): iterable
    {
        $iller = DB::table('il')->pluck('il_adi', 'id')->all();
        $ilceler = DB::table('ilce')->pluck('ilce_adi', 'id')->all();
        $temsilciler = DB::table('sistemyoneticileri')->pluck('name', 'id')->all();

        foreach ($sorgu->orderByDesc('id')->cursor() as $s) {
            yield [
                $s->id,
                $s->salon_adi,
                $iller[(int) $s->il_id] ?? '',
                $ilceler[(int) $s->ilce_id] ?? '',
                $s->yetkili_adi,
                $this->telefon($s->yetkili_telefon),
                $this->telefon($s->telefon_1),
                $temsilciler[(int) $s->musteri_yetkili_id] ?? '',
                (int) $s->askiya_alindi === 1 ? 'Askıda' : 'Aktif',
                $this->tarih($s->created_at, 'd.m.Y H:i'),
            ];
        }
    }

    private function musteriSatirlari($sorgu): iterable
    {
        $satirlar = $sorgu->orderByDesc('musteri_portfoy.id')->select(
            'users.id as user_id', 'users.name', 'users.cep_telefon', 'users.email', 'users.cinsiyet', 'users.dogum_tarihi', 'users.whatsapp_onay',
            'musteri_portfoy.musteri_tipi', 'musteri_portfoy.kara_liste', 'musteri_portfoy.aktif', 'musteri_portfoy.created_at as kayit_tarihi',
        )->cursor();

        foreach ($satirlar as $m) {
            yield [
                $m->user_id,
                $m->name,
                $this->telefon($m->cep_telefon),
                $m->email,
                $m->cinsiyet === null || $m->cinsiyet === '' ? '' : ((int) $m->cinsiyet === 1 ? 'Erkek' : 'Kadın'),
                $this->tarih($m->dogum_tarihi, 'd.m.Y'),
                (int) $m->whatsapp_onay === 1 ? 'Evet' : 'Hayır',
                (int) $m->aktif === 1 ? 'Aktif' : 'Pasif',
                (int) $m->kara_liste === 1 ? 'Evet' : 'Hayır',
                $m->musteri_tipi,
                $this->tarih($m->kayit_tarihi, 'd.m.Y H:i'),
            ];
        }
    }

    private function telefon(?string $telefon): string
    {
        return $telefon ? self::METIN_ONEKI.$telefon : '';
    }

    private function tarih(?string $deger, string $bicim): string
    {
        if (! UyelikKurallari::gecerliMi($deger)) {
            return '';
        }

        return Carbon::parse($deger)->format($bicim);
    }
}
