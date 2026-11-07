<?php

namespace Tests\Feature\Form;

use Tests\TestCase;

/** Kimliksiz erişim: v1 uçlarının karşılıkları token ya da imza ister. */
class FormPdfErisimTest extends TestCase
{
    public function test_arsiv_pdf_token_ister(): void
    {
        $this->getJson('/api/v2/form/arsiv/1/pdf')->assertStatus(401);
    }

    public function test_bos_pdf_linki_token_ister(): void
    {
        $this->getJson('/api/v2/form/sablon/1/bos-pdf-link?salon_id=1')->assertStatus(401);
    }

    public function test_bos_pdf_imzasiz_acilmaz(): void
    {
        $this->getJson('/api/v2/form/sablon/1/bos-pdf?sube=1')->assertStatus(403);
    }

    public function test_musteri_sozlesmeleri_token_ister(): void
    {
        $this->getJson('/api/v2/musteri-uygulama/sozlesmelerim?salon_id=1')->assertStatus(401);
    }
}
