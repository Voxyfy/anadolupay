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
use Voxyfy\AnadoluPay\Exceptions\PaymentFailedException;
use Voxyfy\AnadoluPay\Gateways\Provider\CraftgateGateway;
use Voxyfy\AnadoluPay\Support\Money;
use Voxyfy\AnadoluPay\Tests\Support\BankTestConfig;
use Voxyfy\AnadoluPay\Tests\Support\CallsProtected;

/**
 * Craftgate'i sabit kimlik bilgileriyle üretir.
 *
 * @param  array<string, mixed>  $overrides
 */
function craftgate(array $overrides = []): CraftgateGateway
{
    /** @var CraftgateGateway $gateway */
    $gateway = BankTestConfig::make(CraftgateGateway::class, array_replace_recursive([
        'username' => 'api-key',
        'secret_key' => 'secret-key',
        'password' => 'merchantThreeDsCallbackKeySndbox',
        'extra' => ['merchant_hook_key' => 'Aoh7tReTybO6wOjBmOJFFsOR53SBojEp'],
        'endpoints' => ['payment_api' => 'http://localhost:8000'],
    ], $overrides));

    return $gateway;
}

/**
 * Craftgate yanıt zarfı.
 *
 * @param  array<string, mixed>  $data
 * @return array<string, mixed>
 */
function craftgateData(array $data): array
{
    return ['data' => $data];
}

describe('Craftgate imzaları', function () {
    /*
     * Aşağıdaki üç vektör craftgate/craftgate-php-client deposundaki
     * unit-tests/Craftgate/Tests/Util/SignatureTest.php dosyasından
     * birebir alınmıştır. İmza algoritmasında bir bayt kaysa bu testler
     * kırılır.
     */
    it('gövdesiz isteğin imzasını resmi test vektörüne göre üretir', function () {
        $signature = CallsProtected::call(
            craftgate(),
            'signature',
            '/installment/v1/installments',
            '1234',
            '',
        );

        expect($signature)->toBe('uK7LkWOEzVH+Px/YOiMuSXvPkHLR4KoA7PuykXcYovQ=');
    });

    it('gövdeli isteğin imzasını resmi test vektörüne göre üretir', function () {
        $signature = CallsProtected::call(
            craftgate(),
            'signature',
            '/payment/v1/cards',
            '1234',
            '{"cardUserKey":"de050909-39a9-473c-a81a-f186dd55cfef"}',
        );

        expect($signature)->toBe('kQm62AfjXCw6rS6QBCLXta9tV1GqD/SXsAlYP+cEhG8=');
    });

    it('sorgu dizgisini imzalamadan önce çözer', function () {
        $signature = CallsProtected::call(
            craftgate(),
            'signature',
            '/onboarding/v1/members?name=Zeytinya%C4%9F%C4%B1%20%C3%9Cretim',
            '1234',
            '',
        );

        expect($signature)->toBe('vxYA+5LH3F4m8tHQA2LpVBwzVgCqGRHue4XAgcVjjYQ=');
    });

    it('3D dönüş imzasını resmi test vektörüne göre doğrular', function () {
        // craftgate-php-client / samples/verify_3DS_callback.php
        $verified = CallsProtected::call(craftgate(), 'checkCallbackHash', [
            'hash' => '1d3fa1e51fe7c350185c5a7f8c3ff513a991367b08c16a56f4ab9abeb738a1e1',
            'paymentId' => '5',
            'conversationData' => 'conversation-data',
            'conversationId' => 'conversation-id',
            'status' => 'SUCCESS',
            'completeStatus' => 'WAITING',
        ]);

        expect($verified)->toBeTrue();
    });

    it('değiştirilmiş dönüşü reddeder', function () {
        $verified = CallsProtected::call(craftgate(), 'checkCallbackHash', [
            'hash' => '1d3fa1e51fe7c350185c5a7f8c3ff513a991367b08c16a56f4ab9abeb738a1e1',
            // Ödeme kimliği değiştirildi.
            'paymentId' => '6',
            'conversationData' => 'conversation-data',
            'conversationId' => 'conversation-id',
            'status' => 'SUCCESS',
            'completeStatus' => 'WAITING',
        ]);

        expect($verified)->toBeFalse();
    });

    it('webhook imzasını resmi test vektörüne göre doğrular', function () {
        // craftgate-php-client / samples/verify_webhook.php
        $payload = [
            'eventType' => 'API_VERIFY_AND_AUTH',
            'eventTimestamp' => 1661521221,
            'status' => 'SUCCESS',
            'payloadId' => '584',
        ];

        expect(craftgate()->verifyWebhookSignature('0wRB5XqWJxwwPbn5Z9TcbHh8EGYFufSYTsRMB74N094=', $payload))->toBeTrue()
            ->and(craftgate()->verifyWebhookSignature('bozuk-imza', $payload))->toBeFalse();
    });

    it('imzalanan gövde ile gönderilen gövde aynıdır', function () {
        Http::fake(['localhost:8000/*' => Http::response(craftgateData(['htmlContent' => base64_encode('<html></html>')]))]);

        craftgate()->createPayment(BankTestConfig::order());

        Http::assertSent(function (Request $request) {
            $body = $request->body();
            $randomKey = $request->header('x-rnd-key')[0];

            // İmzayı gönderilen baytlardan yeniden hesaplıyoruz: gövde
            // imzalandıktan sonra yeniden kodlanırsa bu eşleşme bozulur.
            $expected = base64_encode(hash('sha256', implode('', [
                'http://localhost:8000',
                '/payment/v1/card-payments/3ds-init',
                'api-key',
                'secret-key',
                $randomKey,
                $body,
            ]), true));

            return $request->header('x-signature')[0] === $expected
                && $request->header('x-api-key')[0] === 'api-key'
                && $request->header('x-auth-version')[0] === 'v1';
        });
    });
});

it('craftgate preset anahtarından çözümlenir', function () {
    expect(app(AnadoluPay::class)->driver('craftgate'))
        ->toBeInstanceOf(CraftgateGateway::class);
});

describe('Craftgate ödeme akışı', function () {
    it('3D akışında hazır HTML sayfasını çözerek döndürür', function () {
        Http::fake(['localhost:8000/*' => Http::response(craftgateData([
            'paymentId' => 42,
            'htmlContent' => base64_encode('<html>3d</html>'),
        ]))]);

        $response = craftgate()->createPayment(BankTestConfig::order(amount: 199.90));

        expect($response->success)->toBeTrue()
            ->and($response->paymentId)->toBe('42')
            ->and($response->htmlContent)->toBe('<html>3d</html>')
            // Banka geçidine form POST edilmez.
            ->and($response->formAction)->toBeNull();

        Http::assertSent(function (Request $request) {
            $body = json_decode($request->body(), true);

            return $request->url() === 'http://localhost:8000/payment/v1/card-payments/3ds-init'
                // Tutar ondalık sayı; kuruş cinsinden tam sayı değil.
                && $body['price'] === 199.9
                && $body['paidPrice'] === 199.9
                && $body['currency'] === 'TRY'
                && $body['installment'] === 1
                && $body['conversationId'] === 'ORDER-1'
                && $body['paymentPhase'] === 'AUTH'
                && $body['callbackUrl'] === 'https://shop.test/basarili'
                && $body['card']['cardNumber'] === '4155650100416111'
                && $body['card']['expireYear'] === '2030';
        });
    });

    it('oturum açılamazsa Craftgate hata açıklamasını yükseltir', function () {
        Http::fake(['localhost:8000/*' => Http::response([
            'errors' => ['errorCode' => '5008', 'errorDescription' => 'Kart numarası geçersizdir'],
        ], 400)]);

        craftgate()->createPayment(BankTestConfig::order());
    })->throws(PaymentFailedException::class, 'Kart numarası geçersizdir');

    it('non-secure ödemeyi tek istekte tamamlar', function () {
        Http::fake(['localhost:8000/*' => Http::response(craftgateData([
            'id' => 7,
            'paymentStatus' => 'SUCCESS',
        ]))]);

        $response = craftgate()->createPayment(
            BankTestConfig::order(paymentModel: CreatePaymentData::MODEL_NON_SECURE)
        );

        expect($response->success)->toBeTrue()
            ->and($response->paymentId)->toBe('7');

        Http::assertSent(fn (Request $request) => $request->url() === 'http://localhost:8000/payment/v1/card-payments');
    });

    it('ön provizyonu paymentPhase ile bildirir', function () {
        Http::fake(['localhost:8000/*' => Http::response(craftgateData(['id' => 8, 'paymentStatus' => 'SUCCESS']))]);

        craftgate()->preAuthorize(BankTestConfig::order(paymentModel: CreatePaymentData::MODEL_NON_SECURE));

        Http::assertSent(fn (Request $request) => json_decode($request->body(), true)['paymentPhase'] === 'PRE_AUTH');
    });

    it('provizyonu post-auth ucundan kapatır', function () {
        Http::fake(['localhost:8000/*' => Http::response(craftgateData(['id' => 8, 'paymentStatus' => 'SUCCESS']))]);

        $response = craftgate()->capture(new CapturePaymentData(orderId: '8', amount: 50.0));

        expect($response->success)->toBeTrue();

        Http::assertSent(fn (Request $request) => $request->url() === 'http://localhost:8000/payment/v1/card-payments/8/post-auth'
            // json_encode(50.0) tam sayı üretir; Craftgate BigDecimal olarak okur.
            && (float) json_decode($request->body(), true)['paidPrice'] === 50.0);
    });

    it('reddedilen ödemenin hata kodunu taşır', function () {
        Http::fake(['localhost:8000/*' => Http::response(craftgateData([
            'id' => 9,
            'paymentStatus' => 'FAILURE',
            'paymentError' => ['errorCode' => '10051', 'errorDescription' => 'Yetersiz bakiye'],
        ]))]);

        $response = craftgate()->createPayment(
            BankTestConfig::order(paymentModel: CreatePaymentData::MODEL_NON_SECURE)
        );

        expect($response->success)->toBeFalse()
            ->and($response->errorCode)->toBe('10051')
            ->and($response->errorMessage)->toBe('Yetersiz bakiye');
    });
});

describe('Craftgate dönüş doğrulaması', function () {
    /**
     * Geçerli imzalı bir 3D dönüşü üretir.
     *
     * @return array<string, string>
     */
    function craftgateCallback(string $completeStatus = 'WAITING', string $status = 'SUCCESS'): array
    {
        $payload = [
            'status' => $status,
            'completeStatus' => $completeStatus,
            'paymentId' => '42',
            'conversationData' => '',
            'conversationId' => 'ORDER-1',
            'callbackStatus' => '',
        ];

        $hashString = 'merchantThreeDsCallbackKeySndbox';

        foreach (['status', 'completeStatus', 'paymentId', 'conversationData', 'conversationId', 'callbackStatus'] as $field) {
            $hashString .= '###'.$payload[$field];
        }

        return $payload + ['hash' => hash('sha256', $hashString)];
    }

    it('bekleyen ödemeyi 3ds-complete ile tamamlar', function () {
        Http::fake(['localhost:8000/*' => Http::response(craftgateData([
            'id' => 42,
            'paymentStatus' => 'SUCCESS',
        ]))]);

        $response = craftgate()->verify(new VerifyPaymentData(craftgateCallback()));

        expect($response->success)->toBeTrue()
            ->and($response->paymentId)->toBe('42');

        Http::assertSent(fn (Request $request) => $request->url() === 'http://localhost:8000/payment/v1/card-payments/3ds-complete'
            && json_decode($request->body(), true)['paymentId'] === 42);
    });

    it('Craftgate ödemeyi kendisi kapattıysa ikinci istek göndermez', function () {
        Http::fake(['localhost:8000/*' => Http::response(craftgateData([]))]);

        $response = craftgate()->verify(new VerifyPaymentData(craftgateCallback(completeStatus: 'SUCCESS')));

        expect($response->success)->toBeTrue();

        Http::assertNothingSent();
    });

    it('3D doğrulaması başarısızsa provizyon istemez', function () {
        Http::fake(['localhost:8000/*' => Http::response(craftgateData([]))]);

        $response = craftgate()->verify(new VerifyPaymentData(craftgateCallback(status: 'FAILURE')));

        expect($response->success)->toBeFalse();

        Http::assertNothingSent();
    });

    it('imzası bozuk dönüşü reddeder', function () {
        $payload = craftgateCallback();
        $payload['paymentId'] = '43';

        craftgate()->verify(new VerifyPaymentData($payload));
    })->throws(InvalidSignatureException::class);
});

describe('Craftgate iade', function () {
    it('tam iadeyi refunds ucuna gönderir', function () {
        Http::fake(['localhost:8000/*' => Http::response(craftgateData(['id' => 100, 'status' => 'SUCCESS']))]);

        $response = craftgate()->refund(new RefundPaymentData(paymentId: '42'));

        expect($response->success)->toBeTrue()
            ->and($response->refundId)->toBe('100');

        Http::assertSent(fn (Request $request) => $request->url() === 'http://localhost:8000/payment/v1/refunds'
            && json_decode($request->body(), true)['paymentId'] === 42);
    });

    it('kısmi iadeyi işlem bazında gönderir', function () {
        Http::fake(['localhost:8000/*' => Http::response(craftgateData(['id' => 101, 'status' => 'SUCCESS']))]);

        craftgate()->refund(new RefundPaymentData(
            paymentId: '42',
            amount: 20.0,
            metadata: ['payment_transaction_id' => 555],
        ));

        Http::assertSent(function (Request $request) {
            $body = json_decode($request->body(), true);

            return $request->url() === 'http://localhost:8000/payment/v1/refund-transactions'
                && $body['paymentTransactionId'] === 555
                && (float) $body['refundPrice'] === 20.0;
        });
    });

    it('işlem numarası olmadan kısmi iadeyi sessizce tam iadeye çevirmez', function () {
        Http::fake(['localhost:8000/*' => Http::response(craftgateData(['id' => 102, 'status' => 'SUCCESS']))]);

        craftgate()->refund(new RefundPaymentData(paymentId: '42', amount: 20.0));
    })->throws(PaymentFailedException::class);
});

describe('Craftgate sorgular', function () {
    it('sayısal kimliği doğrudan okur', function () {
        Http::fake(['localhost:8000/*' => Http::response(craftgateData([
            'id' => 42,
            'conversationId' => 'ORDER-1',
            'paymentStatus' => 'SUCCESS',
            'paidPrice' => 199.9,
            'currency' => 'TRY',
            'installment' => 3,
            'binNumber' => '415565',
            'lastFourDigits' => '6111',
            'createdDate' => '2026-08-08T10:00:00',
        ]))]);

        $status = craftgate()->status('42');

        expect($status->found)->toBeTrue()
            ->and($status->status)->toBe(StatusResponse::STATUS_PAID)
            ->and($status->orderId)->toBe('ORDER-1')
            ->and($status->amount?->minorUnits)->toBe(19990)
            ->and($status->installment)->toBe(3)
            ->and($status->maskedCardNumber)->toBe('415565******6111');

        Http::assertSent(fn (Request $request) => $request->url() === 'http://localhost:8000/payment-reporting/v1/payments/42'
            && $request->method() === 'GET');
    });

    it('sipariş numarasını conversationId ile arar', function () {
        Http::fake(['localhost:8000/*' => Http::response(craftgateData([
            'items' => [['id' => 42, 'conversationId' => 'ORDER-1', 'paymentStatus' => 'SUCCESS']],
        ]))]);

        expect(craftgate()->status('ORDER-1')->isPaid())->toBeTrue();

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'conversationId=ORDER-1'));
    });

    it('bulunamayan ödemeyi başarılı saymaz', function () {
        Http::fake(['localhost:8000/*' => Http::response(craftgateData(['items' => []]))]);

        $status = craftgate()->status('YOK');

        expect($status->found)->toBeFalse()
            ->and($status->status)->toBe(StatusResponse::STATUS_UNKNOWN);
    });

    it('tanımadığı durum kodunu ödendi saymaz', function () {
        Http::fake(['localhost:8000/*' => Http::response(craftgateData(['id' => 1, 'paymentStatus' => 'YENI_DURUM']))]);

        expect(craftgate()->status('1')->status)->toBe(StatusResponse::STATUS_UNKNOWN);
    });

    it('BIN sorgusunu eşler', function () {
        Http::fake(['localhost:8000/*' => Http::response(craftgateData([
            'binNumber' => '525864',
            'cardType' => 'CREDIT_CARD',
            'cardAssociation' => 'MASTER_CARD',
            'bankName' => 'Denizbank',
            'commercial' => false,
        ]))]);

        $bin = craftgate()->binLookup('525864');

        expect($bin->found)->toBeTrue()
            ->and($bin->bankName)->toBe('Denizbank')
            ->and($bin->type)->toBe('credit')
            ->and($bin->isCredit())->toBeTrue();

        Http::assertSent(fn (Request $request) => $request->url() === 'http://localhost:8000/installment/v1/bins/525864');
    });

    it('taksit seçeneklerini bankalar arasında düzleştirir', function () {
        Http::fake(['localhost:8000/*' => Http::response(craftgateData([
            'items' => [[
                'bankName' => 'Denizbank',
                'installmentPrices' => [
                    ['installmentNumber' => 1, 'totalPrice' => 100.0, 'installmentPrice' => 100.0],
                    ['installmentNumber' => 3, 'totalPrice' => 106.0, 'installmentPrice' => 35.33],
                ],
            ]],
        ]))]);

        $options = craftgate()->installmentOptions(Money::fromDecimal(100.0));

        expect($options)->toHaveCount(2)
            ->and($options[1]->count)->toBe(3)
            ->and($options[1]->bankName)->toBe('Denizbank')
            ->and($options[1]->totalPrice?->minorUnits)->toBe(10600);

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'price=100')
            && str_contains($request->url(), 'currency=TRY'));
    });

    it('işlem dökümünü raporlama ucundan okur', function () {
        Http::fake(['localhost:8000/*' => Http::response(craftgateData(['items' => []]))]);

        craftgate()->orderHistory('42');

        Http::assertSent(fn (Request $request) => $request->url() === 'http://localhost:8000/payment-reporting/v1/payments/42/transactions');
    });
});
