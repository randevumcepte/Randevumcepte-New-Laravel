<?php

namespace App\Http\Middleware;

use App\Exceptions\LisansKapaliException;
use App\Services\Lisans\LisansServisi;
use App\Services\Oturum\ErisimTokenServisi;
use App\Support\SalonIdListesi;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lisans kapisi — suresi biten salonun isletme uclarini TEK yerde kapatir (eski panelde her
 * sayfadaki lisans_sure_kontrol + isletmeadmin.lisanssurebitti).
 *
 * Kapsam: /api/v2 altinda 'auth:isletmeyonetim-api' guard'li rotalar; config('lisans.muaf_yollar')
 * haric. Musteri (auth:api) ve sistem yonetimi (sistemyonetim-api) uclari kapsam disidir. "Salon
 * hesabina gir" token'i (destek) kapidan gecer.
 *
 * Cevap: 402 {basarili:false, mesaj, veri:{kod:'lisans_suresi_doldu', salon_id, uyelik_bitis_tarihi,
 * aktif_olmadi}}. Web (v2-ortak.js) ve Flutter (ApiIstemci) bu kodu yakalayip lisans ekranina gecer.
 *
 * 'api' grubuna eklidir (bootstrap/app.php); auth middleware'inden once calisabildigi icin kullaniciyi
 * guard'dan kendisi cozer (RequestGuard sonucu istek boyunca saklar, token ikinci kez dogrulanmaz).
 * Kimliksiz istek dokunulmadan gecer; 401'i auth middleware'i verir.
 *
 * Iki katman: (1) burada istekteki salon alanlari (salon_id / sube / salonid / salon_ids; farkliysa hepsi) ve kullanicinin
 * bagli salonlari denetlenir; (2) denetlenen istek OZNITELIK=DENETLE ile isaretlenir ve salon erisiminin tek
 * gecidi (GuvenlikKapisi::kapsamDenetle) erisilen salonun lisansini her cagrida taze okur. Muaf uc ve salon
 * hesabina giris MUAF isaretlenir; kapsam gecidi bunlarda lisansa bakmaz.
 */
class LisansKapisi
{
    public const GUARD = 'isletmeyonetim-api';

    /**
     * Istekte salonu belirten alanlar (v2 'salon_id'; eski mobil uyumlu 'sube', 'salonid'; coklu sube hedefi
     * 'salon_ids' — dizi / JSON / "1,2", SalonIdListesi; tekil alanlar yalniz rakam kabul eder).
     */
    private const SALON_ALANLARI = ['salon_id', 'sube', 'salonid', 'salon_ids'];

    /** Coklu salon listesi tasiyan alan(lar). */
    private const LISTE_ALANLARI = ['salon_ids'];

    /** Istek ozniteligi: kapsam gecidinin lisans denetimi (DENETLE) ya da muaf uc / salon hesabina giris (MUAF). */
    public const OZNITELIK = 'lisans_denetimi';

    public const DENETLE = 'denetle';

    public const MUAF = 'muaf';

    public function __construct(private LisansServisi $lisans) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('lisans.aktif', true) || ! $request->is('api/v2/*') || ! self::isletmeRotasi($request->route()?->gatherMiddleware() ?? [])) {
            return $next($request);
        }
        if (self::muafMi(Str::after($request->path(), 'api/v2/'), (array) config('lisans.muaf_yollar', []))) {
            $request->attributes->set(self::OZNITELIK, self::MUAF);

            return $next($request);
        }

        $kullanici = auth(self::GUARD)->user();
        if (! $kullanici) {
            return $next($request);
        }
        if (ErisimTokenServisi::impersonationTokeniMi($request->attributes->get('oauth_access_token_id'))) {
            $request->attributes->set(self::OZNITELIK, self::MUAF);

            return $next($request);
        }

        // Kapsam gecidi (GuvenlikKapisi::kapsamDenetle) bu istekte erisilen salonun lisansini da denetler.
        $request->attributes->set(self::OZNITELIK, self::DENETLE);
        $kapali = $this->lisans->kapaliSalon((int) $kullanici->getAuthIdentifier(), self::istenenSalonlar($request->all()));

        return $kapali === null ? $next($request) : (new LisansKapaliException($kapali))->render();
    }

    /**
     * Bu istekte kapsam gecidi lisansa bakmali mi? Yalniz bu middleware'in DENETLE isaretledigi istekte: muaf uc,
     * salon hesabina giris (imp_), kapi kapali, isletme disi rota (musteri, sistem yonetimi) ve HTTP disi cagri
     * (kuyruk, komut) isaretsizdir.
     */
    public static function denetlenir(?Request $istek): bool
    {
        return $istek !== null && $istek->attributes->get(self::OZNITELIK) === self::DENETLE && config('lisans.aktif', true);
    }

    /** @param  string[]  $desenler  api/v2/ sonrasi yol desenleri (Str::is) */
    public static function muafMi(string $yol, array $desenler): bool
    {
        return $desenler !== [] && Str::is($desenler, trim($yol, '/'));
    }

    /** Rota isletme guard'i ile mi korunuyor? ('auth:isletmeyonetim-api' ya da guard listesinde) */
    public static function isletmeRotasi(array $middleware): bool
    {
        foreach ($middleware as $m) {
            if (is_string($m) && str_starts_with($m, 'auth:') && in_array(self::GUARD, explode(',', substr($m, 5)), true)) {
                return true;
            }
        }

        return false;
    }

    /** @return int[] Istekteki gecerli salon alanlari (tekrarsiz); farkli alanlar farkli salon verirse hepsi denetlenir. */
    public static function istenenSalonlar(array $girdi): array
    {
        $salonlar = [];
        foreach (self::SALON_ALANLARI as $alan) {
            $v = $girdi[$alan] ?? null;
            if (in_array($alan, self::LISTE_ALANLARI, true)) {
                array_push($salonlar, ...SalonIdListesi::coz($v));
            } elseif ((is_int($v) || is_string($v)) && ctype_digit(trim((string) $v)) && (int) $v > 0) {
                $salonlar[] = (int) $v;
            }
        }

        return array_values(array_unique($salonlar));
    }
}
