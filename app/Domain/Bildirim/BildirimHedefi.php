<?php

namespace App\Domain\Bildirim;

/**
 * Bildirime dokununca nereye gidileceği — eski StoreAdminController@bildirimgit kuralı (saf, DB'siz).
 *
 * Sıra eskiyle aynı: '#not-' → ajanda, '#form-' → randevular, gerçek url → url, randevunun müşterisi,
 * bildirimin müşterisi, hiçbiri yoksa randevular.
 *
 * Eskiden farkı: NotificationService resimli push'ta `url` kolonuna GÖRSEL adresini yazıyor; eski kural
 * bunu sayfa sanıp kullanıcıyı görsele yönlendiriyordu. Görsel adresi burada hedef sayılmaz.
 */
final class BildirimHedefi
{
    public const AJANDA = 'ajanda';
    public const RANDEVULAR = 'randevular';
    public const MUSTERI = 'musteri';
    public const URL = 'url';

    private function __construct(
        public readonly string $tur,
        public readonly ?int $musteriId = null,
        public readonly ?string $url = null,
    ) {}

    /** @param array<int,?string> $gorseller bildirimin görsel adresleri (img_src, image_url) */
    public static function coz(?string $url, array $gorseller, ?int $randevuMusterisi, ?int $userId): self
    {
        $url = trim((string) $url);
        if (str_starts_with($url, '#not-')) {
            return new self(self::AJANDA);
        }
        if (str_starts_with($url, '#form-')) {
            return new self(self::RANDEVULAR);
        }
        if ($url !== '' && $url[0] !== '#' && ! in_array($url, $gorseller, true) && self::gezilebilir($url)) {
            return new self(self::URL, null, $url);
        }
        $musteri = $randevuMusterisi ?: $userId;

        return $musteri ? new self(self::MUSTERI, $musteri) : new self(self::RANDEVULAR);
    }

    /** @return array{tur:string, musteri_id:?int, url:?string} */
    public function dizi(): array
    {
        return ['tur' => $this->tur, 'musteri_id' => $this->musteriId, 'url' => $this->url];
    }

    /** Yalnız http(s) ve site-içi yol: 'javascript:' gibi şemalar istemciye link olarak gitmesin. */
    private static function gezilebilir(string $url): bool
    {
        return (bool) preg_match('#^(https?://|/(?!/))#i', $url);
    }
}
