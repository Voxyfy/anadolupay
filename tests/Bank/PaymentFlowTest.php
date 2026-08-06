<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Voxyfy\AnadoluPay\DTO\CreatePaymentData;
use Voxyfy\AnadoluPay\DTO\RefundPaymentData;
use Voxyfy\AnadoluPay\DTO\VerifyPaymentData;
use Voxyfy\AnadoluPay\Exceptions\InvalidSignatureException;
use Voxyfy\AnadoluPay\Exceptions\PaymentFailedException;
use Voxyfy\AnadoluPay\Gateways\Bank\AssecoGateway;
use Voxyfy\AnadoluPay\Gateways\Bank\GarantiGateway;
use Voxyfy\AnadoluPay\Tests\Support\BankTestConfig;
use Voxyfy\AnadoluPay\Tests\Support\CallsProtected;

it('Asseco için imzalı 3D formu üretir', function () {
    $gateway = BankTestConfig::make(AssecoGateway::class);

    $response = $gateway->createPayment(BankTestConfig::order());

    expect($response->success)->toBeTrue()
        ->and($response->requiresForm())->toBeTrue()
        ->and($response->formAction)->toBe('https://bank.test/3d')
        ->and($response->formMethod)->toBe('POST');

    expect($response->formFields)
        ->toHaveKeys(['hashAlgorithm', 'clientid', 'storetype', 'amount', 'oid', 'okUrl', 'failUrl', 'rnd', 'hash'])
        ->and($response->formFields['storetype'])->toBe('3d')
        ->and($response->formFields['amount'])->toBe('1.99')
        ->and($response->formFields['currency'])->toBe('949')
        // Tek çekimde NestPay taksit alanını boş bekler.
        ->and($response->formFields['taksit'])->toBe('')
        ->and($response->formFields['pan'])->toBe('4155650100416111');
});

it('üretilen formun hash değeri kendi doğrulamasından geçer', function () {
    $gateway = BankTestConfig::make(AssecoGateway::class);

    $fields = $gateway->createPayment(BankTestConfig::order())->formFields;

    // Banka dönüşünü, formun kendisini geri gönderiyormuş gibi taklit ediyoruz.
    $callback = $fields;
    $callback['HASH'] = $fields['hash'];
    unset($callback['hash']);

    expect(CallsProtected::call($gateway, 'checkCallbackHash', $callback))->toBeTrue();
});

it('taksitli ödemede NestPay taksit alanını doldurur', function () {
    $gateway = BankTestConfig::make(AssecoGateway::class);

    $fields = $gateway->createPayment(BankTestConfig::order(installment: 6))->formFields;

    expect($fields['taksit'])->toBe('6');
});

it('Garanti tutarı kuruşa çevirir ve formu imzalar', function () {
    $gateway = BankTestConfig::make(GarantiGateway::class);

    $fields = $gateway->createPayment(BankTestConfig::order(amount: 199.90))->formFields;

    expect($fields['txnamount'])->toBe('19990')
        ->and($fields['txncurrencycode'])->toBe('949')
        ->and($fields['secure3dsecuritylevel'])->toBe('3D')
        ->and($fields['secure3dhash'])->toMatch('/^[0-9A-F]{128}$/');
});

it('3D Host modelinde kart bilgisi istemez', function () {
    $gateway = BankTestConfig::make(AssecoGateway::class);

    $order = new CreatePaymentData(
        amount: 1.99,
        currency: 'TRY',
        orderId: 'ORDER-1',
        customer: [],
        successUrl: 'https://shop.test/basarili',
        failUrl: 'https://shop.test/hata',
        paymentModel: CreatePaymentData::MODEL_3D_HOST,
    );

    $fields = $gateway->createPayment($order)->formFields;

    expect($fields)->not->toHaveKey('pan')
        ->and($fields['storetype'])->toBe('3d_host');
});

it('3D Secure modelinde kart bilgisi eksikse hata verir', function () {
    $gateway = BankTestConfig::make(AssecoGateway::class);

    $order = new CreatePaymentData(
        amount: 1.99,
        currency: 'TRY',
        orderId: 'ORDER-1',
        customer: [],
        successUrl: 'https://shop.test/basarili',
        failUrl: 'https://shop.test/hata',
    );

    $gateway->createPayment($order);
})->throws(PaymentFailedException::class);

it('başarı URL’i verilmemişse hata verir', function () {
    $gateway = BankTestConfig::make(AssecoGateway::class);

    $order = new CreatePaymentData(
        amount: 1.99,
        currency: 'TRY',
        orderId: 'ORDER-1',
        customer: [],
        card: BankTestConfig::card(),
    );

    $gateway->createPayment($order);
})->throws(PaymentFailedException::class);

it('geçersiz hash ile gelen dönüşü reddeder', function () {
    $gateway = BankTestConfig::make(AssecoGateway::class);

    $gateway->verify(new VerifyPaymentData([
        'oid' => 'ORDER-1',
        'mdStatus' => '1',
        'HASH' => 'sahte-hash',
    ]));
})->throws(InvalidSignatureException::class);

it('mdStatus başarısızsa provizyon isteği göndermez', function () {
    Http::fake();

    $gateway = BankTestConfig::make(AssecoGateway::class, ['verify_hash' => false]);

    $response = $gateway->verify(new VerifyPaymentData([
        'oid' => 'ORDER-1',
        'mdStatus' => '0',
        'storetype' => '3d',
    ]));

    expect($response->success)->toBeFalse()
        ->and($response->status)->toBe('failed')
        ->and($response->paymentId)->toBe('ORDER-1');

    Http::assertNothingSent();
});

it('3D doğrulaması başarılıysa provizyon isteği gönderir ve onayı eşler', function () {
    Http::fake([
        'bank.test/api' => Http::response(
            '<?xml version="1.0" encoding="ISO-8859-9"?><CC5Response>'
            .'<ProcReturnCode>00</ProcReturnCode><Response>Approved</Response>'
            .'<TransId>TX-123</TransId></CC5Response>',
            200,
        ),
    ]);

    $gateway = BankTestConfig::make(AssecoGateway::class, ['verify_hash' => false]);

    $response = $gateway->verify(new VerifyPaymentData([
        'oid' => 'ORDER-1',
        'mdStatus' => '1',
        'storetype' => '3d',
        'md' => 'MD-1',
        'xid' => 'XID-1',
        'eci' => '05',
        'cavv' => 'CAVV-1',
        'amount' => '1.99',
        'currency' => '949',
    ]));

    expect($response->success)->toBeTrue()
        ->and($response->status)->toBe('success')
        ->and($response->paymentId)->toBe('TX-123');

    Http::assertSent(function ($request) {
        $body = $request->body();

        return str_contains($body, '<Type>Auth</Type>')
            && str_contains($body, '<Number>MD-1</Number>')
            && str_contains($body, '<PayerAuthenticationCode>CAVV-1</PayerAuthenticationCode>');
    });
});

it('3D Pay modelinde ikinci bir provizyon isteği göndermez', function () {
    Http::fake();

    $gateway = BankTestConfig::make(AssecoGateway::class, ['verify_hash' => false]);

    $response = $gateway->verify(new VerifyPaymentData([
        'oid' => 'ORDER-1',
        'mdStatus' => '1',
        'storetype' => '3d_pay',
        'ProcReturnCode' => '00',
    ]));

    expect($response->success)->toBeTrue();

    Http::assertNothingSent();
});

it('reddedilen provizyonu başarısız olarak eşler', function () {
    Http::fake([
        'bank.test/api' => Http::response(
            '<CC5Response><ProcReturnCode>99</ProcReturnCode>'
            .'<ErrMsg>Yetersiz bakiye</ErrMsg></CC5Response>',
            200,
        ),
    ]);

    $gateway = BankTestConfig::make(AssecoGateway::class, ['verify_hash' => false]);

    $response = $gateway->verify(new VerifyPaymentData([
        'oid' => 'ORDER-1',
        'mdStatus' => '1',
        'storetype' => '3d',
    ]));

    expect($response->success)->toBeFalse()
        ->and($response->status)->toBe('failed');
});

it('Asseco iadesini Credit işlem tipiyle gönderir', function () {
    Http::fake([
        'bank.test/api' => Http::response(
            '<CC5Response><ProcReturnCode>00</ProcReturnCode><TransId>RF-1</TransId></CC5Response>',
            200,
        ),
    ]);

    $gateway = BankTestConfig::make(AssecoGateway::class);

    $response = $gateway->refund(new RefundPaymentData('ORDER-1', 1.99));

    expect($response->success)->toBeTrue()
        ->and($response->refundId)->toBe('RF-1');

    Http::assertSent(fn ($request) => str_contains($request->body(), '<Type>Credit</Type>')
        && str_contains($request->body(), '<Total>1.99</Total>'));
});

it('Garanti iadesi ref_ret_num olmadan çalışmaz', function () {
    $gateway = BankTestConfig::make(GarantiGateway::class);

    $gateway->refund(new RefundPaymentData('ORDER-1', 1.99));
})->throws(PaymentFailedException::class);
