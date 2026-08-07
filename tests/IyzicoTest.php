<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Voxyfy\AnadoluPay\DTO\CardData;
use Voxyfy\AnadoluPay\DTO\CreatePaymentData;
use Voxyfy\AnadoluPay\DTO\RefundPaymentData;
use Voxyfy\AnadoluPay\DTO\VerifyPaymentData;
use Voxyfy\AnadoluPay\Exceptions\InvalidSignatureException;
use Voxyfy\AnadoluPay\Gateways\IyzicoGateway;
use Voxyfy\AnadoluPay\Support\IyzicoHttpClient;
use Voxyfy\AnadoluPay\Support\IyzicoSignatureValidator;

const IYZICO_SECRET = 'sandbox-secret-key';
const IYZICO_API_KEY = 'sandbox-api-key';

/**
 * iyzico'nun imza şeması bu paketin geçmişinde doğrulanmamış hâlde
 * kalmıştı. Aşağıdaki beklentiler resmi dokümantasyondaki alan sıralarını
 * birebir yansıtır; algoritma değişirse testler kırılır.
 *
 * @see https://docs.iyzico.com/en/advanced/response-signature-validation
 * @see https://docs.iyzico.com/en/getting-started/preliminaries/authentication/hmacsha256-auth
 */
function iyzicoValidator(bool $enabled = true): IyzicoSignatureValidator
{
    return new IyzicoSignatureValidator(secretKey: IYZICO_SECRET, enabled: $enabled);
}

/** Belirtilen alanlar için beklenen imzayı üretir. */
function iyzicoSign(string ...$values): string
{
    return hash_hmac('sha256', implode(':', $values), IYZICO_SECRET);
}

describe('IYZWSv2 kimlik doğrulama', function () {
    it('Authorization başlığını randomKey + uriPath + gövde üzerinden imzalar', function () {
        $client = new IyzicoHttpClient('https://sandbox-api.iyzipay.com', IYZICO_API_KEY, IYZICO_SECRET);

        $body = '{"locale":"tr"}';
        $header = $client->authorizationHeader('/payment/3dsecure/initialize', $body, 'RND123');

        $expectedSignature = hash_hmac(
            'sha256',
            'RND123'.'/payment/3dsecure/initialize'.$body,
            IYZICO_SECRET,
        );

        expect($header)->toStartWith('IYZWSv2 ');

        $decoded = base64_decode(substr($header, strlen('IYZWSv2 ')), true);

        expect($decoded)->toBe(
            'apiKey:'.IYZICO_API_KEY.'&randomKey:RND123&signature:'.$expectedSignature
        )
            // İmza hex olmalı, base64 değil
            ->and($expectedSignature)->toMatch('/^[0-9a-f]{64}$/');
    });

    it('imzada query string’i yok sayar', function () {
        $client = new IyzicoHttpClient('https://sandbox-api.iyzipay.com', IYZICO_API_KEY, IYZICO_SECRET);

        expect($client->authorizationHeader('/v2/payment/refund?x=1', '{}', 'RND'))
            ->toBe($client->authorizationHeader('/v2/payment/refund', '{}', 'RND'));
    });

    it('imzalanan gövdeyi birebir gönderir', function () {
        Http::fake(['*' => Http::response(['status' => 'success'])]);

        $client = new IyzicoHttpClient('https://sandbox-api.iyzipay.com', IYZICO_API_KEY, IYZICO_SECRET);
        $client->post('/payment/auth', ['price' => '1.99', 'callbackUrl' => 'https://a.test/x']);

        Http::assertSent(function ($request) use ($client) {
            $body = $request->body();
            $randomKey = $request->header('x-iyzi-rnd')[0];

            // Gönderilen gövde ile imzalanan gövde aynı olmalı; aksi hâlde
            // iyzico imzayı reddeder.
            return $request->header('Authorization')[0]
                === $client->authorizationHeader('/payment/auth', $body, $randomKey);
        });
    });
});

describe('yanıt imzası doğrulama', function () {
    it('3DS callback imzasını doğrular', function () {
        $payload = [
            'conversationData' => 'CONV-DATA',
            'conversationId' => 'CONV-1',
            'mdStatus' => '1',
            'paymentId' => '12345',
            'status' => 'success',
        ];
        $payload['signature'] = iyzicoSign('CONV-DATA', 'CONV-1', '1', '12345', 'success');

        iyzicoValidator()->validateCallback($payload);
    })->throwsNoExceptions();

    it('bozuk callback imzasını reddeder', function () {
        iyzicoValidator()->validateCallback([
            'conversationId' => 'CONV-1',
            'mdStatus' => '1',
            'paymentId' => '12345',
            'status' => 'success',
            'signature' => 'sahte',
        ]);
    })->throws(InvalidSignatureException::class);

    it('imzası eksik callback’i reddeder', function () {
        iyzicoValidator()->validateCallback(['paymentId' => '12345']);
    })->throws(InvalidSignatureException::class);

    it('initialize yanıtını paymentId:conversationId sırasıyla doğrular', function () {
        iyzicoValidator()->validateInitializeResponse([
            'paymentId' => '12345',
            'conversationId' => 'CONV-1',
            'signature' => iyzicoSign('12345', 'CONV-1'),
        ]);
    })->throwsNoExceptions();

    it('auth yanıtını altı alanlı sırayla doğrular', function () {
        iyzicoValidator()->validateAuthResponse([
            'paymentId' => '12345',
            'currency' => 'TRY',
            'basketId' => 'B-1',
            'conversationId' => 'CONV-1',
            'paidPrice' => '1.99',
            'price' => '1.99',
            'signature' => iyzicoSign('12345', 'TRY', 'B-1', 'CONV-1', '1.99', '1.99'),
        ]);
    })->throwsNoExceptions();

    it('iade yanıtını paymentId:price:currency:conversationId sırasıyla doğrular', function () {
        iyzicoValidator()->validateRefundResponse([
            'paymentId' => '12345',
            'price' => '1.99',
            'currency' => 'TRY',
            'conversationId' => 'CONV-1',
            'signature' => iyzicoSign('12345', '1.99', 'TRY', 'CONV-1'),
        ]);
    })->throwsNoExceptions();

    it('tutarlardaki sondaki sıfırları imzadan önce atar', function () {
        $validator = iyzicoValidator();

        expect($validator->normalizePrice('10.50'))->toBe('10.5')
            ->and($validator->normalizePrice('10.5'))->toBe('10.5')
            ->and($validator->normalizePrice('10.00'))->toBe('10.0')
            ->and($validator->normalizePrice('10'))->toBe('10');

        // "1.90" gönderilse bile imza "1.9" üzerinden hesaplanır.
        $validator->validateRefundResponse([
            'paymentId' => '12345',
            'price' => '1.90',
            'currency' => 'TRY',
            'conversationId' => 'CONV-1',
            'signature' => iyzicoSign('12345', '1.9', 'TRY', 'CONV-1'),
        ]);
    });

    it('doğrulama kapalıyken imzayı kontrol etmez', function () {
        iyzicoValidator(enabled: false)->validateCallback(['signature' => 'sahte']);
    })->throwsNoExceptions();
});

describe('webhook imzası', function () {
    it('X-IYZ-SIGNATURE-V3 başlığını doğrular', function () {
        $payload = [
            'iyziEventType' => 'PAYMENT_API',
            'paymentId' => '12345',
            'paymentConversationId' => 'CONV-1',
            'status' => 'SUCCESS',
        ];

        // Webhook’ta secret key hem HMAC anahtarı hem de dizginin başında yer alır.
        $signature = hash_hmac(
            'sha256',
            IYZICO_SECRET.'PAYMENT_API'.'12345'.'CONV-1'.'SUCCESS',
            IYZICO_SECRET,
        );

        iyzicoValidator()->validateWebhook($payload, ['x-iyz-signature-v3' => $signature]);
    })->throwsNoExceptions();

    it('HPP bildiriminde token’ı imzaya dâhil eder', function () {
        $payload = [
            'iyziEventType' => 'CHECKOUTFORM_AUTH',
            'iyziPaymentId' => '999',
            'token' => 'TOKEN-1',
            'paymentConversationId' => 'CONV-1',
            'status' => 'SUCCESS',
        ];

        $signature = hash_hmac(
            'sha256',
            IYZICO_SECRET.'CHECKOUTFORM_AUTH'.'999'.'TOKEN-1'.'CONV-1'.'SUCCESS',
            IYZICO_SECRET,
        );

        iyzicoValidator()->validateWebhook($payload, ['X-IYZ-SIGNATURE-V3' => [$signature]]);
    })->throwsNoExceptions();

    it('imzası olmayan webhook’u reddeder', function () {
        iyzicoValidator()->validateWebhook(['iyziEventType' => 'PAYMENT_API'], []);
    })->throws(InvalidSignatureException::class);
});

describe('iyzico ödeme akışı', function () {
    beforeEach(function () {
        config()->set('anadolupay.iyzico', [
            'api_key' => IYZICO_API_KEY,
            'secret_key' => IYZICO_SECRET,
            'base_url' => 'https://sandbox-api.iyzipay.com',
            'callback_url' => 'https://shop.test/donus',
            'validate_signature' => true,
        ]);
    });

    it('3DS HTML içeriğini çözer', function () {
        Http::fake([
            '*/payment/3dsecure/initialize' => Http::response([
                'status' => 'success',
                'paymentId' => '12345',
                'conversationId' => 'CONV-1',
                'signature' => iyzicoSign('12345', 'CONV-1'),
                'threeDSHtmlContent' => base64_encode('<html>3ds</html>'),
            ]),
        ]);

        $response = IyzicoGateway::fromConfig()->createPayment(new CreatePaymentData(
            amount: 1.99,
            currency: 'TRY',
            orderId: 'CONV-1',
            customer: ['email' => 'a@b.test', 'name' => 'Ahmet Yılmaz'],
            card: new CardData('5528790000000008', '12', '2030', '123', 'Ahmet Yılmaz'),
        ));

        expect($response->success)->toBeTrue()
            ->and($response->paymentId)->toBe('12345')
            // Base64 çözümü paket tarafında yapılır; tüketen uygulama uğraşmaz.
            ->and($response->htmlContent)->toBe('<html>3ds</html>')
            ->and($response->toHtmlForm())->toBe('<html>3ds</html>');
    });

    it('initialize yanıtının imzası bozuksa reddeder', function () {
        Http::fake([
            '*/payment/3dsecure/initialize' => Http::response([
                'status' => 'success',
                'paymentId' => '12345',
                'conversationId' => 'CONV-1',
                'signature' => 'sahte',
                'threeDSHtmlContent' => base64_encode('<html></html>'),
            ]),
        ]);

        IyzicoGateway::fromConfig()->createPayment(new CreatePaymentData(
            amount: 1.99,
            currency: 'TRY',
            orderId: 'CONV-1',
            customer: ['email' => 'a@b.test'],
            card: new CardData('5528790000000008', '12', '2030', '123'),
        ));
    })->throws(InvalidSignatureException::class);

    it('callback doğrulandıktan sonra provizyonu tamamlar', function () {
        Http::fake([
            '*/payment/3dsecure/auth' => Http::response([
                'status' => 'success',
                'paymentId' => '12345',
                'currency' => 'TRY',
                'basketId' => 'B-1',
                'conversationId' => 'CONV-1',
                'paidPrice' => '1.99',
                'price' => '1.99',
                'signature' => iyzicoSign('12345', 'TRY', 'B-1', 'CONV-1', '1.99', '1.99'),
            ]),
        ]);

        $callback = [
            'conversationData' => 'CONV-DATA',
            'conversationId' => 'CONV-1',
            'mdStatus' => '1',
            'paymentId' => '12345',
            'status' => 'success',
        ];
        $callback['signature'] = iyzicoSign('CONV-DATA', 'CONV-1', '1', '12345', 'success');

        $result = IyzicoGateway::fromConfig()->verify(new VerifyPaymentData($callback));

        expect($result->success)->toBeTrue()
            ->and($result->status)->toBe('success')
            ->and($result->paymentId)->toBe('12345');
    });

    it('mdStatus başarısızsa provizyon istemez', function () {
        Http::fake();

        $callback = [
            'conversationData' => '',
            'conversationId' => 'CONV-1',
            'mdStatus' => '0',
            'paymentId' => '12345',
            'status' => 'failure',
        ];
        $callback['signature'] = iyzicoSign('', 'CONV-1', '0', '12345', 'failure');

        $result = IyzicoGateway::fromConfig()->verify(new VerifyPaymentData($callback));

        expect($result->success)->toBeFalse();

        Http::assertNothingSent();
    });

    it('iadeyi v2 ucundan yapar ve yanıt imzasını doğrular', function () {
        Http::fake([
            '*/v2/payment/refund' => Http::response([
                'status' => 'success',
                'paymentId' => '12345',
                'price' => '1.99',
                'currency' => 'TRY',
                'conversationId' => 'CONV-1',
                'signature' => iyzicoSign('12345', '1.99', 'TRY', 'CONV-1'),
            ]),
        ]);

        $result = IyzicoGateway::fromConfig()->refund(new RefundPaymentData(
            paymentId: '12345',
            amount: 1.99,
            metadata: ['conversation_id' => 'CONV-1'],
        ));

        expect($result->success)->toBeTrue()
            ->and($result->refundId)->toBe('12345');

        Http::assertSent(fn ($request) => str_contains($request->body(), '"price":"1.99"')
            && str_contains($request->body(), '"paymentId":"12345"'));
    });

    it('başarısız iadeyi istisna fırlatmadan raporlar', function () {
        Http::fake([
            '*/v2/payment/refund' => Http::response([
                'status' => 'failure',
                'errorMessage' => 'İade edilebilir tutar aşıldı',
            ]),
        ]);

        $result = IyzicoGateway::fromConfig()->refund(new RefundPaymentData('12345', 999.0));

        expect($result->success)->toBeFalse()
            ->and($result->errorMessage)->toBe('İade edilebilir tutar aşıldı');
    });
});
