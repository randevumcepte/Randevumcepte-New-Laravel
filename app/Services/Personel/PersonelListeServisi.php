<?php

namespace App\Services\Personel;

/**
 * Personel LISTE servisi (Faz 1, salt-okuma).
 *
 * Eski ApiController@personelgetir ile ayni filtre/sira: arsivliler elenir,
 * takvim_sirasi + id'ye gore siralanir, sayfalanir. DTO = PersonelOkumaServisi
 * ust-kumesi (tek kaynak; kod tekrari yok).
 */
class PersonelListeServisi
{
    public function __construct(private PersonelOkumaServisi $okuma) {}

    /**
     * @return array{data: array, current_page:int, last_page:int, per_page:int, total:int}
     */
    public function listele(int $salonId, ?string $baslik, bool $maasGor = true, int $perPage = 10): array
    {
        $sorgu = $this->okuma->temelSorgu()
            ->where('sp.salon_id', $salonId)
            // Arsivlileri gizle (NULL veya false kabul).
            ->where(function ($q) {
                $q->whereNull('sp.arsivli')->orWhere('sp.arsivli', false);
            })
            ->groupBy('sp.id')
            ->orderBy('sp.takvim_sirasi', 'asc')
            ->orderBy('sp.id', 'asc');

        if ($baslik !== null && $baslik !== '') {
            $sorgu->where('sp.personel_adi', 'like', '%' . $baslik . '%');
        }

        $sayfa = $sorgu->paginate($perPage);

        return [
            'data'         => array_map(
                fn ($row) => $this->okuma->donustur($row, $maasGor),
                $sayfa->items()
            ),
            'current_page' => $sayfa->currentPage(),
            'last_page'    => $sayfa->lastPage(),
            'per_page'     => $sayfa->perPage(),
            'total'        => $sayfa->total(),
        ];
    }
}
