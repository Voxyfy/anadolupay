<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Voxyfy\AnadoluPay\DTO\CreatePaymentData;
use Voxyfy\AnadoluPay\DTO\RefundPaymentData;
use Voxyfy\AnadoluPay\Exceptions\InvalidSignatureException;
use Voxyfy\AnadoluPay\Exceptions\PaymentFailedException;
use Voxyfy\AnadoluPay\Gateways\Provider\PaycellGateway;
use Voxyfy\AnadoluPay\Tests\Support\BankTestConfig;
use Voxyfy\AnadoluPay\Tests\Support\CallsProtected;

/**
 * Paycell (Turkcell)
 *
 * Paycell'in ayırt edici yanı, kart bilgisinin ödeme ucuna hiç gitmemesi:
 * önce ayrı bir uçtan kart token'ı alınır, ödeme o token ile yapılır.
 * İmza iki aşamalıdır ve girdinin tamamı büyük harfe çevrilir.
 */
function paycell(array $overrides = []): PaycellGateway
{
    /** @var PaycellGateway */
    return BankTestConfig::make(PaycellGateway::class, array_replace_recursive([
        'merchant_id' => '9998',
        'username' => 'PAYCELLTEST',
        'password' => 'PaycellTestPassword',
        'secret_key' => 'PAYCELL12345',
        'extra' => ['msisdn' => '5380521479', 'client_ip' => '10.0.0.1'],
        'endpoints' => [
            'payment_api' => 'https://paycell.test/provision',
            'token_api' => 'https://paycell.test/token/getCardTokenSecure',
            'gateway_3d' => 'https://paycell.test/threeDSecure',
        ],
    ], $overrides));
}

/*
| Paycell'in test ortamında ölçülmüş imza.
|
| 2026-08-10'da yayınlanmış test kimlikleriyle gerçek bir kart token'ı
| alındı; sağlayıcının yanıtta gönderdiği hashData aşağıdaki girdilerle
| birebir eşleşti. Uydurma değil, sağlayıcıdan gelmiş bir değerdir.
*/
it('Paycell yanıt imzasını sağlayıcının ürettiğiyle aynı hesaplar', function () {
    $hash = CallsProtected::call(paycell(), 'responseHash',
        '17863475987307763618',
        '20260810073958000',
        '0',
        '68aebf28-f014-4f26-a5ba-d5bcbbf5c2ce',
    );

    expect($hash)->toBe('/2iVndGUELNe5HD2OcpieaukjhO/EC5daNl0AFERzl4=');
});

it('Paycell istek imzasında yanıt alanları yer almaz', function () {
    $gateway = paycell();

    $request = CallsProtected::call($gateway, 'requestHash', 'TX1', 'DT1');
    $response = CallsProtected::call($gateway, 'responseHash', 'TX1', 'DT1', '', '');

    // responseCode ve cardToken boşken iki imza aynı girdiye iner.
    expect($request)->toBe($response);
});

it('Paycell imzayı büyük harfe çevirerek hesaplar', function () {
    $gateway = paycell();

    expect(CallsProtected::call($gateway, 'hash', 'abc'))
        ->toBe(CallsProtected::call($gateway, 'hash', 'ABC'));
});

it('Paycell işlem numarasını 20 hane üretir', function () {
    $gateway = paycell();

    foreach (range(1, 5) as $ignored) {
        expect(CallsProtected::call($gateway, 'transactionId'))->toMatch('/^\d{20}$/');
    }
});

it('Paycell işlem zamanını 17 hane üretir', function () {
    expect(CallsProtected::call(paycell(), 'transactionDateTime'))->toMatch('/^\d{17}$/');
});

it('Paycell referans numarasını 20 haneye tamamlar', function () {
    expect(CallsProtected::call(paycell(), 'referenceNumber', 'ORDER-42'))
        ->toBe('00000000000000000042');
});

it('Paycell çok uzun sipariş numarasını sessizce kesmez', function () {
    CallsProtected::call(paycell(), 'referenceNumber', '123456789012345678901');
})->throws(PaymentFailedException::class, '20 hane');

it('Paycell kart token isteğini ayrı uca gönderir', function () {
    Http::fake([
        'paycell.test/token/*' => Http::response([
            'header' => ['responseCode' => '0', 'responseDescription' => 'Islem basarili'],
            'cardToken' => 'TOKEN-1',
        ]),
    ]);

    $token = CallsProtected::call(
        paycell(['verify_hash' => false]),
        'createCardToken',
        BankTestConfig::order(),
    );

    expect($token)->toBe('TOKEN-1');

    Http::assertSent(function ($request) {
        $body = $request->data();

        return str_contains($request->url(), '/token/getCardTokenSecure')
            && $body['creditCardNo'] === '4155650100416111'
            && $body['expireDateYear'] === '2030'
            && $body['header']['applicationName'] === 'PAYCELLTEST'
            && strlen((string) $body['header']['transactionId']) === 20;
    });
});

it('Paycell token yanıtının imzası tutmazsa reddeder', function () {
    Http::fake([
        'paycell.test/token/*' => Http::response([
            'header' => [
                'responseCode' => '0',
                'responseDateTime' => '20260810073958000',
                'transactionId' => '17863475987307763618',
                'hashData' => 'BOZUK-IMZA',
            ],
            'cardToken' => 'TOKEN-1',
        ]),
    ]);

    CallsProtected::call(paycell(), 'createCardToken', BankTestConfig::order());
})->throws(InvalidSignatureException::class);

it('Paycell 3D akışında oturum açar ve formu üretir', function () {
    Http::fake([
        'paycell.test/token/*' => Http::response([
            'header' => ['responseCode' => '0'],
            'cardToken' => 'TOKEN-1',
        ]),
        'paycell.test/provision/getThreeDSession/' => Http::response([
            'responseHeader' => ['responseCode' => '0'],
            'threeDSessionId' => 'SESSION-1',
        ]),
    ]);

    $response = paycell(['verify_hash' => false])->createPayment(BankTestConfig::order());

    expect($response->success)->toBeTrue()
        ->and($response->formAction)->toBe('https://paycell.test/threeDSecure')
        ->and($response->formFields['threeDSessionId'])->toBe('SESSION-1');
});

it('Paycell 3D oturumu açılamazsa sağlayıcının mesajını taşır', function () {
    Http::fake([
        'paycell.test/token/*' => Http::response([
            'header' => ['responseCode' => '0'],
            'cardToken' => 'TOKEN-1',
        ]),
        'paycell.test/provision/getThreeDSession/' => Http::response([
            'responseHeader' => ['responseCode' => '2032', 'responseDescription' => 'There was an invalid parameter.'],
        ]),
    ]);

    paycell(['verify_hash' => false])->createPayment(BankTestConfig::order());
})->throws(PaymentFailedException::class, 'invalid parameter');

it('Paycell 3D siz ödemede tutarı kuruş olarak gönderir', function () {
    Http::fake([
        'paycell.test/token/*' => Http::response([
            'header' => ['responseCode' => '0'],
            'cardToken' => 'TOKEN-1',
        ]),
        'paycell.test/provision/provision/' => Http::response([
            'responseHeader' => ['responseCode' => '0'],
            'orderId' => 'ORD-1',
        ]),
    ]);

    $response = paycell(['verify_hash' => false])
        ->createPayment(BankTestConfig::order(CreatePaymentData::MODEL_NON_SECURE));

    expect($response->success)->toBeTrue()
        ->and($response->paymentId)->toBe('ORD-1');

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/provision/provision/')) {
            return true;
        }

        return $request->data()['amount'] === '199'
            && $request->data()['paymentType'] === 'SALE'
            && $request->data()['cardToken'] === 'TOKEN-1';
    });
});

it('Paycell iptali reverse, iadeyi refund ucuna gönderir', function () {
    Http::fake([
        'paycell.test/provision/*' => Http::response([
            'responseHeader' => ['responseCode' => '0'],
            'orderId' => 'RF-1',
        ]),
    ]);

    $gateway = paycell();

    expect($gateway->cancel(new RefundPaymentData('ORDER-1'))->success)->toBeTrue();
    Http::assertSent(fn ($request) => str_contains($request->url(), '/reverse/'));

    expect($gateway->refund(new RefundPaymentData('ORDER-1', 1.99))->success)->toBeTrue();
    Http::assertSent(fn ($request) => str_contains($request->url(), '/refund/'));
});

it('Paycell başarısız yanıtı sağlayıcının koduyla eşler', function () {
    Http::fake([
        'paycell.test/provision/*' => Http::response([
            'responseHeader' => ['responseCode' => '4002', 'responseDescription' => 'Expired card'],
        ]),
    ]);

    $response = paycell()->refund(new RefundPaymentData('ORDER-1', 1.99));

    expect($response->success)->toBeFalse()
        ->and($response->errorMessage)->toBe('Expired card');
});
