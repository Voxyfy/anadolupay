<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Voxyfy\AnadoluPay\Contracts\SupportsPreAuthorization;
use Voxyfy\AnadoluPay\DTO\CapturePaymentData;
use Voxyfy\AnadoluPay\Exceptions\PaymentFailedException;
use Voxyfy\AnadoluPay\Gateways\Bank\AssecoGateway;
use Voxyfy\AnadoluPay\Gateways\Bank\GarantiGateway;
use Voxyfy\AnadoluPay\Gateways\Bank\KuveytPosGateway;
use Voxyfy\AnadoluPay\Gateways\Provider\PayTrGateway;
use Voxyfy\AnadoluPay\Support\Money;
use Voxyfy\AnadoluPay\Tests\Support\BankTestConfig;

describe('ön provizyon yeteneği', function () {
    it('destekleyen driver’lar arayüzü uygular', function () {
        expect(BankTestConfig::make(AssecoGateway::class))->toBeInstanceOf(SupportsPreAuthorization::class)
            ->and(BankTestConfig::make(GarantiGateway::class))->toBeInstanceOf(SupportsPreAuthorization::class);
    });

    it('Kuveyt Türk ve PayTR ön provizyon sunmaz', function () {
        // Sağlayıcı sınırı, bizim eksiğimiz değil.
        expect(BankTestConfig::make(KuveytPosGateway::class))->not->toBeInstanceOf(SupportsPreAuthorization::class)
            ->and(BankTestConfig::make(PayTrGateway::class))->not->toBeInstanceOf(SupportsPreAuthorization::class);
    });
});

describe('ön provizyon işlem tipi', function () {
    it('NestPay formunda TranType’ı PreAuth yapar', function () {
        $gateway = BankTestConfig::make(AssecoGateway::class);

        $sale = $gateway->createPayment(BankTestConfig::order())->formFields;
        $preAuth = $gateway->preAuthorize(BankTestConfig::order())->formFields;

        expect($sale['TranType'])->toBe('Auth')
            ->and($preAuth['TranType'])->toBe('PreAuth')
            // İşlem tipi hash'e girdiği için imza da değişmeli.
            ->and($preAuth['hash'])->not->toBe($sale['hash']);
    });

    it('Garanti formunda txntype’ı preauth yapar', function () {
        $gateway = BankTestConfig::make(GarantiGateway::class);

        expect($gateway->preAuthorize(BankTestConfig::order())->formFields['txntype'])->toBe('preauth')
            ->and($gateway->createPayment(BankTestConfig::order())->formFields['txntype'])->toBe('sales');
    });

    it('DTO’yu değiştirmeden kopyalar', function () {
        $order = BankTestConfig::order();
        $preAuth = $order->asPreAuthorization();

        expect($order->preAuthorization)->toBeFalse()
            ->and($preAuth->preAuthorization)->toBeTrue()
            ->and($preAuth->orderId)->toBe($order->orderId)
            ->and($preAuth->money()->minorUnits)->toBe($order->money()->minorUnits);
    });
});

describe('provizyon kapama', function () {
    it('NestPay kapamayı PostAuth ile gönderir', function () {
        Http::fake(['bank.test/*' => Http::response(
            '<CC5Response><ProcReturnCode>00</ProcReturnCode><TransId>TX-9</TransId></CC5Response>'
        )]);

        $response = BankTestConfig::make(AssecoGateway::class)
            ->capture(new CapturePaymentData('ORDER-1', 149.90));

        expect($response->success)->toBeTrue()
            ->and($response->paymentId)->toBe('TX-9');

        Http::assertSent(fn ($request) => str_contains($request->body(), '<Type>PostAuth</Type>')
            && str_contains($request->body(), '<Total>149.9</Total>'));
    });

    it('kısmi kapamada ön provizyon tutarını PREAMT ile bildirir', function () {
        Http::fake(['bank.test/*' => Http::response('<CC5Response><ProcReturnCode>00</ProcReturnCode></CC5Response>')]);

        BankTestConfig::make(AssecoGateway::class)->capture(new CapturePaymentData(
            orderId: 'ORDER-1',
            amount: Money::fromMinorUnits(14990),
            metadata: ['pre_auth_amount' => 199.90],
        ));

        Http::assertSent(fn ($request) => str_contains($request->body(), '<PREAMT>199.9</PREAMT>'));
    });

    it('Garanti kapaması ref_ret_num olmadan çalışmaz', function () {
        BankTestConfig::make(GarantiGateway::class)->capture(new CapturePaymentData('ORDER-1', 149.90));
    })->throws(PaymentFailedException::class);

    it('Garanti kapamayı postauth ile imzalar', function () {
        Http::fake(['bank.test/*' => Http::response(
            '<GVPSResponse><Transaction><Response><Code>00</Code></Response>'
            .'<RetrefNum>REF-9</RetrefNum></Transaction></GVPSResponse>'
        )]);

        $response = BankTestConfig::make(GarantiGateway::class)->capture(new CapturePaymentData(
            orderId: 'ORDER-1',
            amount: 149.90,
            metadata: ['ref_ret_num' => 'REF-1'],
        ));

        expect($response->success)->toBeTrue()
            ->and($response->paymentId)->toBe('REF-9');

        Http::assertSent(fn ($request) => str_contains($request->body(), '<Type>postauth</Type>')
            // Garanti kuruş cinsinden tutar bekler.
            && str_contains($request->body(), '<Amount>14990</Amount>')
            && str_contains($request->body(), '<OriginalRetrefNum>REF-1</OriginalRetrefNum>'));
    });

    it('reddedilen kapamayı başarısız olarak raporlar', function () {
        Http::fake(['bank.test/*' => Http::response(
            '<CC5Response><ProcReturnCode>99</ProcReturnCode><ErrMsg>Provizyon bulunamadı</ErrMsg></CC5Response>'
        )]);

        $response = BankTestConfig::make(AssecoGateway::class)
            ->capture(new CapturePaymentData('ORDER-1', 149.90));

        expect($response->success)->toBeFalse()
            ->and($response->errorMessage)->toBe('Provizyon bulunamadı')
            ->and($response->errorCode)->toBe('99');
    });
});
