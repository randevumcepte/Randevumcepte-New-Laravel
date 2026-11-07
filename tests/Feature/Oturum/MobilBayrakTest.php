<?php

namespace Tests\Feature\Oturum;

use App\Domain\Oturum\TokenTipi;
use App\Services\Oturum\IstekTokeni;
use App\Services\Oturum\MobilBayraklar;
use GuzzleHttp\Psr7\ServerRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Tests\TestCase;

/** Mobil bayrak yalniz imzali mobil token'la etkilidir: guard, kimliksiz rota ve bayrak listesi. */
class MobilBayrakTest extends TestCase
{
    private const YOL = '/api/v2/test-bayrak';

    protected function setUp(): void
    {
        parent::setUp();
        Route::middleware('api')->match(['GET', 'POST'], self::YOL, fn (Request $r) => response()->json([
            'mobil' => $r->boolean('mobil'), 'mobil_sablon' => $r->boolean('mobil_sablon'), 'salon_id' => $r->input('salon_id'),
        ]));
    }

    /** @param  array<int,string>|null  $kapsam  null: token gecersiz */
    private function tokenKur(?array $kapsam): void
    {
        $this->app->instance(IstekTokeni::class, new class($kapsam) extends IstekTokeni
        {
            public function __construct(private ?array $kapsam) {}

            public function dogrula(Request $istek): ServerRequestInterface
            {
                if ($this->kapsam === null) {
                    throw new RuntimeException('gecersiz');
                }

                return (new ServerRequest('GET', '/'))->withAttribute('oauth_scopes', $this->kapsam)
                    ->withAttribute('oauth_user_id', '1')->withAttribute('oauth_access_token_id', 't');
            }
        });
    }

    public function test_temizle_query_form_ve_json_govdesinden_siler_digerlerine_dokunmaz(): void
    {
        $json = Request::create('/x?mobil=1&salon_id=5', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['mobil_sablon' => 1, 'ad' => 'a']));
        MobilBayraklar::temizle($json);

        $this->assertFalse($json->has('mobil'));
        $this->assertFalse($json->has('mobil_sablon'));
        $this->assertSame('5', $json->input('salon_id'));
        $this->assertSame('a', $json->input('ad'));

        $form = Request::create('/x', 'POST', ['gece_devri' => 1, 'ad' => 'a']);
        MobilBayraklar::temizle($form);

        $this->assertFalse($form->has('gece_devri'));
        $this->assertSame('a', $form->input('ad'));
    }

    public function test_kimliksiz_istekte_bayrak_etkisizdir(): void
    {
        $this->getJson(self::YOL.'?mobil=1&salon_id=5')->assertOk()->assertJson(['mobil' => false, 'salon_id' => '5']);
        $this->postJson(self::YOL, ['mobil' => 1, 'mobil_sablon' => 1])->assertOk()->assertJson(['mobil' => false, 'mobil_sablon' => false]);
    }

    public function test_kimliksiz_rotada_gecersiz_web_ve_tipsiz_token_bayragi_gecirmez(): void
    {
        foreach ([null, [], [TokenTipi::ISLETME], [TokenTipi::MUSTERI], [TokenTipi::MOBIL]] as $kapsam) {
            $this->tokenKur($kapsam);
            $this->withToken('x')->getJson(self::YOL.'?mobil=1')->assertOk()->assertJson(['mobil' => false]);
        }
    }

    public function test_kimliksiz_rotada_imzali_mobil_token_bayragi_korur(): void
    {
        $this->tokenKur(TokenTipi::kapsam(TokenTipi::MUSTERI, true));

        $this->withToken('x')->getJson(self::YOL.'?mobil=1')->assertOk()->assertJson(['mobil' => true]);
    }

    /** Sistem guard'i sayisal subject'i reddeder: kullanici sorgusuna gitmeden bayrak karari gorulur. */
    private function guarddanGecir(?array $kapsam): Request
    {
        $this->tokenKur($kapsam);
        $istek = Request::create('/x?mobil=1', 'GET');
        $this->app->instance('request', $istek);
        Auth::forgetGuards();
        Auth::guard('sistemyonetim-api')->user();

        return $istek;
    }

    public function test_guard_web_tipsiz_ve_gecersiz_tokende_bayragi_siler(): void
    {
        foreach ([null, [], [TokenTipi::ISLETME]] as $kapsam) {
            $istek = $this->guarddanGecir($kapsam);

            $this->assertFalse($istek->has('mobil'));
            $this->assertFalse(MobilBayraklar::mobilToken($istek));
        }
    }

    public function test_guard_imzali_mobil_tokende_bayragi_korur(): void
    {
        $istek = $this->guarddanGecir(TokenTipi::kapsam(TokenTipi::ISLETME, true));

        $this->assertTrue($istek->boolean('mobil'));
        $this->assertTrue(MobilBayraklar::mobilToken($istek));
    }
}
