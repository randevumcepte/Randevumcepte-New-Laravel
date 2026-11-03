<?php

namespace App\Domain\Afis;

/**
 * Salonun uygulama mağazası bağlantıları (salonlar.android_uygulama / ios_uygulama /
 * huawei_uygulama) ve afiş için hazır olma kuralı — TEK kaynak.
 *
 * Eski uygulamaAfisi ile BİREBİR: afiş (PDF + önizleme) için Android VE iOS linki şart;
 * paylaşılabilir indirme linki için herhangi biri yeterli. Huawei afiş şartı değildir
 * (yalnız /indir akıllı yönlendirmesinde kullanılır).
 */
final class MagazaLinkleri
{
    public readonly string $android;

    public readonly string $ios;

    public readonly string $huawei;

    public function __construct(?string $android, ?string $ios, ?string $huawei = null)
    {
        $this->android = trim((string) $android);
        $this->ios = trim((string) $ios);
        $this->huawei = trim((string) $huawei);
    }

    public function afisHazir(): bool
    {
        return $this->android !== '' && $this->ios !== '';
    }

    public function herhangiVar(): bool
    {
        return $this->android !== '' || $this->ios !== '';
    }

    /** @return array{android:bool, ios:bool, huawei:bool} (URL'ler istemciye gerekmez) */
    public function tanimlilar(): array
    {
        return ['android' => $this->android !== '', 'ios' => $this->ios !== '', 'huawei' => $this->huawei !== ''];
    }
}
