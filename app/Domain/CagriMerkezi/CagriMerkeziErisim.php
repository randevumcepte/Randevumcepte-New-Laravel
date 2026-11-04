<?php

namespace App\Domain\CagriMerkezi;

/**
 * Giriş yapan kullanıcının bir salonun çağrı merkezi üzerindeki erişim kapsamı (değer nesnesi;
 * CagriMerkeziErisimServisi üretir). Kurallar web + Flutter için TEK yerde:
 *
 *   - Yönetici (rol 5 değil): salonun tüm listeleri / randevuları / çağrıları; performans ve
 *     liste yönetimi; arama randevusunu başka personele verebilir.
 *   - Personel (rol 5)      : yalnız KENDİNE atanan listeler ve onların arama randevuları;
 *                             yalnız kendi dahilisinin çağrıları; arama randevusu yalnız kendine.
 *   - Maske (KVKK)          : 'musteri.telefon_gor' YOK → ad soyad kısaltılır, telefon maskelenir, ham
 *                             numara istemciye HİÇ gitmez, telefonla arama kapalı. Rolden bağımsızdır;
 *                             özel kaydı olmayan yönetici için anahtar zaten açıktır (YetkiServisi).
 */
final class CagriMerkeziErisim
{
    /**
     * @param array<int,string> $dahililer kullanıcının bu salondaki dahilileri (normal + WebRTC)
     * @param array<string,bool> $yetkiler buton görünürlüğü için yetki bayrakları
     */
    public function __construct(
        public readonly int $salonId,
        public readonly int $yetkiliId,
        public readonly bool $yonetici,
        public readonly ?int $personelId,
        public readonly array $dahililer,
        public readonly bool $telefonGor,
        public readonly array $yetkiler = [],
    ) {}

    /** Telefon görme yetkisi yok → maskele. */
    public function maskele(): bool
    {
        return ! $this->telefonGor;
    }

    /** Eylem bu kullanıcıya açık mı? (rol + anahtar; kural CagriMerkeziEylemi'nde) */
    public function yapabilir(CagriMerkeziEylemi $eylem): bool
    {
        return (! $eylem->yalnizYonetici() || $this->yonetici)
            && ($eylem->anahtarlar() === [] || $this->yetki($eylem->value));
    }

    /** Bu listeyi görebilir / işleyebilir mi? (yönetici tümü, personel kendine atanan) */
    public function listeyeErisir(?int $listePersonelId): bool
    {
        return $this->yonetici || ($this->personelId !== null && $listePersonelId === $this->personelId);
    }

    /** Arama randevusunu bu personele verebilir mi? (personel yalnız kendine) */
    public function personeleAtayabilir(int $personelId): bool
    {
        return $this->yonetici || $personelId === $this->personelId;
    }

    public function yetki(string $anahtar): bool
    {
        return (bool) ($this->yetkiler[$anahtar] ?? false);
    }

    /** İstemcinin (web/Flutter) ekran kurgusu için yayınlanan bayraklar. */
    public function dizi(): array
    {
        return [
            'yonetici'    => $this->yonetici,
            'personel_id' => $this->personelId,
            'dahili_var'  => $this->dahililer !== [],
            'telefon_gor' => ! $this->maskele(),
            'yetkiler'    => $this->yetkiler,
        ];
    }
}
