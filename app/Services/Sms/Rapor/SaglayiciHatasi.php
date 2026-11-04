<?php

namespace App\Services\Sms\Rapor;

use RuntimeException;

/** Sağlayıcıdan rapor alınamadı — SmsRaporServisi bunu istemciye "uyari" olarak taşır. */
class SaglayiciHatasi extends RuntimeException {}
