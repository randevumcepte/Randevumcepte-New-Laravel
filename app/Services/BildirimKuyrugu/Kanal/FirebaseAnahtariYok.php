<?php

namespace App\Services\BildirimKuyrugu\Kanal;

use RuntimeException;

/**
 * Sunucuda Firebase JSON'u yok: hata cihazin degil kurulumun. Cihaz satirina hata sayilmaz, gonderimi cagirana firlatir.
 * Eski kodda karsiligi yok (dosyalar hep yerindeydi); burada olmasaydi eksik dosya tum token'lari pasife dusururdu.
 */
class FirebaseAnahtariYok extends RuntimeException {}
