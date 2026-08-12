<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Voxyfy\AnadoluPay\AnadoluPay;
use Voxyfy\AnadoluPay\DTO\CapturePaymentData;
use Voxyfy\AnadoluPay\DTO\CreatePaymentData;
use Voxyfy\AnadoluPay\DTO\RefundPaymentData;
use Voxyfy\AnadoluPay\DTO\StatusResponse;
use Voxyfy\AnadoluPay\DTO\VerifyPaymentData;
use Voxyfy\AnadoluPay\Exceptions\InvalidSignatureException;
use Voxyfy\AnadoluPay\Gateways\Provider\TamiGateway;
use Voxyfy\AnadoluPay\Tests\Support\BankTestConfig;
use Voxyfy\AnadoluPay\Tests\Support\CallsProtected;

/**
 * Tami'yi sabit kimlik bilgileriyle üretir.
 *
 * `username`/`password` burada JWK `kid`/`k` çiftidir — Tami'nin
 * `securityHash` alanı için ayrı bir anahtar (`PG-Auth-Token`'daki
 * `secret_key`'den farklı).
 *
 * @param  array<string, mixed>  $overrides
 */
function tami(array $overrides = []): TamiGateway
{
    /** @var TamiGateway $gateway */
    $gateway = BankTestConfig::make(TamiGateway::class, array_replace_recursive([
        'username' => 'jwk-kid-1',
        'password' => base64_encode('jwk-secret-bytes'),
        'endpoints' => ['payment_api' => 'https://tami.test'],
    ], $overrides));

    return $gateway;
}

/**
 * `securityHash` alanını testte bağımsızca yeniden hesaplar; sürücünün
 * ürettiğiyle eşleşmesi gerekir.
 *
 * @param  array<string, mixed>  $body  `securityHash` alanı OLMADAN gövde
 */
function tamiExpectedHash(array $body, string $kid, string $k): string
{
    $b64url = static fn (string $data): string => rtrim(strtr(base64_encode($data), '+/', '-_'), '=');

    $header = $b64url(json_encode(['alg' => 'HS512', 'typ' => 'JWT', 'kid' => $kid], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    $payload = $b64url(json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    $signingInput = $header.'.'.$payload;
    $key = base64_decode(strtr($k, '-_', '+/'));
    $signature = $b64url(hash_hmac('sha512', $signingInput, $key, true));

    return $signingInput.'.'.$signature;
}

it('tami preset anahtarından çözümlenir', function () {
    expect(app(AnadoluPay::class)->driver('tami'))->toBeInstanceOf(TamiGateway::class);
});

describe('Tami kimlik doğrulama', function () {
    it('PG-Auth-Token başlığını doğru formülle üretir', function () {
        $token = CallsProtected::call(tami(), 'authToken');

        $expectedHash = base64_encode(hash('sha256', 'MERCHANT1TERMINAL1SECRETKEY', true));

        expect($token)->toBe("MERCHANT1:TERMINAL1:{$expectedHash}");
    });

    it('securityHash alanını JWS/HS512 formülüyle üretir', function () {
        $body = ['orderId' => 'ORDER-1', 'amount' => '1.99'];

        $hash = CallsProtected::call(tami(), 'securityHash', $body);
        $expected = tamiExpectedHash($body, 'jwk-kid-1', base64_encode('jwk-secret-bytes'));

        expect($hash)->toBe($expected)
            // JWT bileşenleri (header.payload.signature) nokta ile ayrılır.
            ->and(substr_count($hash, '.'))->toBe(2);
    });
});

describe('Tami ödeme akışı', function () {
    it('3D akışında hazır HTML sayfasını çözerek döndürür ve securityHash gövdeyle tutarlıdır', function () {
        Http::fake(['tami.test/*' => Http::response([
            'success' => true,
            'orderId' => 'ORDER-1',
            'threeDSHtmlContent' => base64_encode('<html>3d</html>'),
        ])]);

        $response = tami()->createPayment(BankTestConfig::order(amount: 15.0));

        expect($response->success)->toBeTrue()
            ->and($response->paymentId)->toBe('ORDER-1')
            ->and($response->htmlContent)->toBe('<html>3d</html>')
            ->and($response->formAction)->toBeNull();

        Http::assertSent(function (Request $request) {
            $body = json_decode($request->body(), true);

            $withoutHash = $body;
            unset($withoutHash['securityHash']);
            $expectedHash = tamiExpectedHash($withoutHash, 'jwk-kid-1', base64_encode('jwk-secret-bytes'));

            return $request->url() === 'https://tami.test/payment/auth'
                && $request->header('PG-Auth-Token')[0] === CallsProtected::call(tami(), 'authToken')
                && $body['orderId'] === 'ORDER-1'
                && $body['amount'] === '15.00'
                && $body['currency'] === 'TRY'
                && $body['callbackUrl'] === 'https://shop.test/basarili'
                && $body['card']['number'] === '4155650100416111'
                && $body['card']['expireYear'] === 2030
                && $body['securityHash'] === $expectedHash;
        });
    });

    it('3D başlatma reddedilirse hata mesajını taşır', function () {
        Http::fake(['tami.test/*' => Http::response([
            'success' => false,
            'errorCode' => '4015',
            'errorMessage' => 'Security Hash alanının iletilmesi zorunludur',
        ])]);

        $response = tami()->createPayment(BankTestConfig::order());

        expect($response->success)->toBeFalse()
            ->and($response->errorCode)->toBe('4015')
            ->and($response->errorMessage)->toContain('Security Hash');
    });

    it('non-secure ödemeyi callbackUrl göndermeden tamamlar', function () {
        Http::fake(['tami.test/*' => Http::response([
            'success' => true,
            'bankReferenceNumber' => '5221043',
        ])]);

        $response = tami()->createPayment(
            BankTestConfig::order(paymentModel: CreatePaymentData::MODEL_NON_SECURE)
        );

        expect($response->success)->toBeTrue()
            ->and($response->paymentId)->toBe('5221043');

        Http::assertSent(function (Request $request) {
            $body = json_decode($request->body(), true);

            return $request->url() === 'https://tami.test/payment/auth'
                && ! array_key_exists('callbackUrl', $body);
        });
    });
});

describe('Tami dönüş doğrulaması', function () {
    /**
     * Geçerli `hashedData` imzalı bir 3D dönüşü üretir.
     *
     * Formül `checkCallbackHash()` docblock'unda anlatılıyor — Tami'nin
     * resmî bir onayı değil, bağımsız bir kaynaktan doğrulandı.
     *
     * @return array<string, string>
     */
    function tamiCallback(string $mdStatus = '1', string $success = 'true'): array
    {
        $payload = [
            'orderId' => 'ORDER-1',
            'success' => $success,
            'mdStatus' => $mdStatus,
            'cardOrganization' => 'VISA',
            'cardBrand' => 'Garanti',
            'cardType' => 'CREDIT',
            'maskedNumber' => '4155-65**-****-6111',
            'installmentCount' => '1',
            'currencyCode' => 'TRY',
            'txnAmount' => '15.00',
            'systemTime' => '2026-08-12T10:00:00',
        ];

        $fields = implode('', [
            $payload['cardOrganization'],
            $payload['cardBrand'],
            $payload['cardType'],
            $payload['maskedNumber'],
            $payload['installmentCount'],
            $payload['currencyCode'],
            $payload['txnAmount'],
            $payload['orderId'],
            $payload['systemTime'],
            $payload['success'],
        ]);

        $payload['hashedData'] = base64_encode(hash_hmac('sha256', $fields, 'SECRETKEY', true));

        return $payload;
    }

    it('mdStatus 1 ise complete-3ds isteği gönderir ve provizyonu tamamlar', function () {
        Http::fake(['tami.test/*' => Http::response([
            'success' => true,
            'bankReferenceNumber' => '5221999',
        ])]);

        $response = tami()->verify(new VerifyPaymentData(tamiCallback()));

        expect($response->success)->toBeTrue()
            ->and($response->paymentId)->toBe('5221999');

        Http::assertSent(fn (Request $request) => $request->url() === 'https://tami.test/payment/complete-3ds'
            && json_decode($request->body(), true)['orderId'] === 'ORDER-1');
    });

    it('success=false ise complete-3ds istemeden failed döner', function () {
        Http::fake(['tami.test/*' => Http::response([])]);

        $response = tami()->verify(new VerifyPaymentData(tamiCallback(mdStatus: '0', success: 'false')));

        expect($response->success)->toBeFalse();

        Http::assertNothingSent();
    });

    it('complete-3ds başarısız dönerse doğrulama başarısız sayılır', function () {
        Http::fake(['tami.test/*' => Http::response(['success' => false, 'errorCode' => '30015'])]);

        $response = tami()->verify(new VerifyPaymentData(tamiCallback()));

        expect($response->success)->toBeFalse();
    });

    it('hashedData imzası bozuk dönüşü reddeder', function () {
        $payload = tamiCallback();
        $payload['hashedData'] = 'bozuk-imza';

        tami()->verify(new VerifyPaymentData($payload));
    })->throws(InvalidSignatureException::class);

    it('hashedData eksikse reddeder', function () {
        $payload = tamiCallback();
        unset($payload['hashedData']);

        tami()->verify(new VerifyPaymentData($payload));
    })->throws(InvalidSignatureException::class);
});

describe('Tami iade / iptal / ön provizyon', function () {
    it('tam iadeyi reverse ucuna orderId ile gönderir', function () {
        Http::fake(['tami.test/*' => Http::response(['success' => true, 'bankReferenceNumber' => '900'])]);

        $response = tami()->refund(new RefundPaymentData(paymentId: 'ORDER-1'));

        expect($response->success)->toBeTrue()
            ->and($response->refundId)->toBe('900');

        Http::assertSent(function (Request $request) {
            $body = json_decode($request->body(), true);

            return $request->url() === 'https://tami.test/payment/reverse'
                && $body['orderId'] === 'ORDER-1'
                && ! array_key_exists('amount', $body);
        });
    });

    it('kısmi iadeyi amount alanıyla gönderir', function () {
        Http::fake(['tami.test/*' => Http::response(['success' => true, 'bankReferenceNumber' => '901'])]);

        tami()->refund(new RefundPaymentData(paymentId: 'ORDER-1', amount: 3.0));

        Http::assertSent(fn (Request $request) => json_decode($request->body(), true)['amount'] === '3.00');
    });

    it('iptal aynı reverse ucuna gider', function () {
        Http::fake(['tami.test/*' => Http::response(['success' => true, 'bankReferenceNumber' => '902'])]);

        $response = tami()->cancel(new RefundPaymentData(paymentId: 'ORDER-1'));

        expect($response->success)->toBeTrue();

        Http::assertSent(fn (Request $request) => $request->url() === 'https://tami.test/payment/reverse');
    });

    it('ön provizyonu post-auth ucundan kapatır', function () {
        Http::fake(['tami.test/*' => Http::response(['success' => true, 'bankReferenceNumber' => '903'])]);

        $response = tami()->capture(new CapturePaymentData(orderId: 'ORDER-1', amount: 5.0));

        expect($response->success)->toBeTrue();

        Http::assertSent(fn (Request $request) => $request->url() === 'https://tami.test/payment/post-auth'
            && json_decode($request->body(), true)['amount'] === '5.00');
    });
});

describe('Tami durum sorgusu', function () {
    it('AUTH durumunu paid olarak eşler', function () {
        Http::fake(['tami.test/*' => Http::response([
            'success' => true,
            'orderStatus' => 'AUTH',
            'paymentStatus' => 'SUCCESS',
            'amount' => 15.0,
            'currency' => 'TRY',
            'correlationId' => 'CORR-1',
            'installmentCount' => 1,
        ])]);

        $status = tami()->status('ORDER-1');

        expect($status->found)->toBeTrue()
            ->and($status->status)->toBe(StatusResponse::STATUS_PAID)
            ->and($status->paymentId)->toBe('CORR-1')
            ->and($status->amount?->minorUnits)->toBe(1500);
    });

    it('REFUND durumunu refunded olarak eşler', function () {
        Http::fake(['tami.test/*' => Http::response([
            'success' => true,
            'orderStatus' => 'REFUND',
            'paymentStatus' => 'SUCCESS',
        ])]);

        expect(tami()->status('ORDER-1')->isRefunded())->toBeTrue();
    });

    it('bulunamayan siparişte notFound döner', function () {
        Http::fake(['tami.test/*' => Http::response(['success' => false, 'errorCode' => '2010'])]);

        $status = tami()->status('YOK');

        expect($status->found)->toBeFalse()
            ->and($status->status)->toBe(StatusResponse::STATUS_UNKNOWN);
    });
});
