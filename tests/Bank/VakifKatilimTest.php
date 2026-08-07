<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Voxyfy\AnadoluPay\Contracts\SupportsOrderHistory;
use Voxyfy\AnadoluPay\Contracts\SupportsPreAuthorization;
use Voxyfy\AnadoluPay\DTO\CapturePaymentData;
use Voxyfy\AnadoluPay\DTO\CreatePaymentData;
use Voxyfy\AnadoluPay\DTO\RefundPaymentData;
use Voxyfy\AnadoluPay\DTO\StatusResponse;
use Voxyfy\AnadoluPay\DTO\VerifyPaymentData;
use Voxyfy\AnadoluPay\Gateways\Bank\VakifKatilimGateway;
use Voxyfy\AnadoluPay\Support\Money;
use Voxyfy\AnadoluPay\Tests\Support\BankTestConfig;

/**
 * Vakıf Katılım, Kuveyt Türk ile aynı imza algoritmasını kullanır ama
 * istek şeması ve uç noktaları farklıdır: işlem tipi URL'in son
 * parçasıdır. Bu testler o ayrımı ve yanıt eşlemesini kilitler.
 */
function vakifKatilim(array $overrides = []): VakifKatilimGateway
{
    return BankTestConfig::make(VakifKatilimGateway::class, array_replace_recursive([
        'endpoints' => ['payment_api' => 'https://bank.test/api'],
        'extra' => ['customer_id' => 'CUST1', 'sub_merchant_id' => '0'],
    ], $overrides));
}

/** Bankanın başarılı XML yanıtı. */
function vkResponse(string $inner = ''): string
{
    return '<VPosTransactionResponseContract><ResponseCode>00</ResponseCode>'
        .'<ResponseMessage>Basarili</ResponseMessage><OrderId>9988</OrderId>'
        .$inner.'</VPosTransactionResponseContract>';
}

describe('Vakıf Katılım 3D akışı', function () {
    it('3D isteğini ThreeDModelPayGate ucuna gönderir', function () {
        Http::fake(['bank.test/*' => Http::response('<html>banka 3d</html>')]);

        $response = vakifKatilim()->createPayment(BankTestConfig::order(amount: 199.90));

        // Vakıf Katılım form alanı değil hazır HTML döner.
        expect($response->success)->toBeTrue()
            ->and($response->htmlContent)->toBe('<html>banka 3d</html>')
            ->and($response->requiresForm())->toBeTrue()
            ->and($response->formFields)->toBe([]);

        Http::assertSent(function ($request) {
            $body = $request->body();

            return str_ends_with($request->url(), '/ThreeDModelPayGate')
                // Tutar kuruş cinsinden
                && str_contains($body, '<Amount>19990</Amount>')
                && str_contains($body, '<DisplayAmount>19990</DisplayAmount>')
                && str_contains($body, '<TransactionSecurity>3</TransactionSecurity>')
                && str_contains($body, '<FECCurrencyCode>949</FECCurrencyCode>')
                && str_contains($body, '<MerchantOrderId>ORDER-1</MerchantOrderId>');
        });
    });

    it('isteği imzalar ve şifreyi hash’lenmiş gönderir', function () {
        Http::fake(['bank.test/*' => Http::response('<html></html>')]);

        vakifKatilim()->createPayment(BankTestConfig::order());

        $expectedHashedPassword = base64_encode(hash('sha1', 'SECRETKEY', true));

        Http::assertSent(fn ($request) => str_contains($request->body(), '<HashPassword>'.htmlspecialchars($expectedHashedPassword, ENT_XML1).'</HashPassword>')
            && preg_match('#<HashData>[^<]+</HashData>#', $request->body()) === 1);
    });

    it('3D Host modelinde kart bilgisi göndermez', function () {
        Http::fake(['bank.test/*' => Http::response('<html></html>')]);

        vakifKatilim()->createPayment(new CreatePaymentData(
            amount: 50.0,
            currency: 'TRY',
            orderId: 'ORDER-1',
            customer: [],
            successUrl: 'https://shop.test/ok',
            failUrl: 'https://shop.test/fail',
            paymentModel: CreatePaymentData::MODEL_3D_HOST,
        ));

        Http::assertSent(fn ($request) => ! str_contains($request->body(), '<CardNumber>'));
    });

    it('provizyonu ayrı bir uca gönderir ve onayı eşler', function () {
        Http::fake(['bank.test/*' => Http::response(vkResponse())]);

        $result = vakifKatilim()->verify(new VerifyPaymentData([
            'ResponseCode' => '00',
            'MerchantOrderId' => 'ORDER-1',
            'MD' => 'MD-1',
            'Amount' => '19990',
            'InstallmentCount' => '0',
        ]));

        expect($result->success)->toBeTrue()
            ->and($result->status)->toBe('success')
            ->and($result->paymentId)->toBe('9988');

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/ThreeDModelProvisionGate')
            && str_contains($request->body(), '<Key>MD</Key>')
            && str_contains($request->body(), '<Data>MD-1</Data>'));
    });

    it('banka dönüşü başarısızsa provizyon istemez', function () {
        Http::fake();

        $result = vakifKatilim()->verify(new VerifyPaymentData([
            'ResponseCode' => '99',
            'MerchantOrderId' => 'ORDER-1',
        ]));

        expect($result->success)->toBeFalse()
            ->and($result->paymentId)->toBe('ORDER-1');

        Http::assertNothingSent();
    });
});

describe('Vakıf Katılım 3D’siz ödeme', function () {
    it('Non3DPayGate ucunu kullanır', function () {
        Http::fake(['bank.test/*' => Http::response(vkResponse())]);

        $response = vakifKatilim()->createPayment(
            BankTestConfig::order(paymentModel: CreatePaymentData::MODEL_NON_SECURE, amount: 75.50)
        );

        expect($response->success)->toBeTrue()
            ->and($response->paymentId)->toBe('9988');

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/Non3DPayGate')
            && str_contains($request->body(), '<TransactionSecurity>1</TransactionSecurity>')
            && str_contains($request->body(), '<Amount>7550</Amount>')
            && str_contains($request->body(), '<CardNumber>4155650100416111</CardNumber>'));
    });

    it('reddedilen ödemeyi hata mesajıyla raporlar', function () {
        Http::fake(['bank.test/*' => Http::response(
            '<VPosTransactionResponseContract><ResponseCode>99</ResponseCode>'
            .'<ResponseMessage>Yetersiz bakiye</ResponseMessage></VPosTransactionResponseContract>'
        )]);

        $response = vakifKatilim()->createPayment(
            BankTestConfig::order(paymentModel: CreatePaymentData::MODEL_NON_SECURE)
        );

        expect($response->success)->toBeFalse()
            ->and($response->errorMessage)->toBe('Yetersiz bakiye')
            ->and($response->errorCode)->toBe('99');
    });
});

describe('Vakıf Katılım ön provizyon', function () {
    it('arayüzü uygular', function () {
        expect(vakifKatilim())->toBeInstanceOf(SupportsPreAuthorization::class);
    });

    it('ön provizyonu PreAuthorizaten ucuna gönderir', function () {
        Http::fake(['bank.test/*' => Http::response(vkResponse())]);

        vakifKatilim()->preAuthorize(
            BankTestConfig::order(paymentModel: CreatePaymentData::MODEL_NON_SECURE)
        );

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/PreAuthorizaten'));
    });

    it('kapamayı PreAuthorizatenClose ucuna gönderir', function () {
        Http::fake(['bank.test/*' => Http::response(vkResponse())]);

        $response = vakifKatilim()->capture(new CapturePaymentData(
            orderId: 'ORDER-1',
            amount: Money::fromMinorUnits(14990),
            metadata: ['remote_order_id' => '9988'],
        ));

        expect($response->success)->toBeTrue();

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/PreAuthorizatenClose')
            && str_contains($request->body(), '<Amount>14990</Amount>')
            && str_contains($request->body(), '<OrderId>9988</OrderId>'));
    });
});

describe('Vakıf Katılım iade ve iptal', function () {
    it('tam iadeyi DrawBack, kısmi iadeyi PartialDrawBack ucuna gönderir', function () {
        Http::fake(['bank.test/*' => Http::response(vkResponse())]);

        vakifKatilim()->refund(new RefundPaymentData('ORDER-1'));
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/DrawBack'));

        vakifKatilim()->refund(new RefundPaymentData('ORDER-1', 49.90));
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/PartialDrawBack')
            && str_contains($request->body(), '<Amount>4990</Amount>'));
    });

    it('iptali SaleReversal ucuna gönderir', function () {
        Http::fake(['bank.test/*' => Http::response(vkResponse())]);

        $response = vakifKatilim()->cancel(new RefundPaymentData('ORDER-1', metadata: [
            'remote_order_id' => '9988',
        ]));

        expect($response->success)->toBeTrue()
            ->and($response->refundId)->toBe('9988');

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/SaleReversal'));
    });
});

describe('Vakıf Katılım durum sorgusu', function () {
    it('BOA durum kodunu normalleştirir', function () {
        Http::fake(['bank.test/*' => Http::response(
            '<VPosTransactionResponseContract><ResponseCode>00</ResponseCode>'
            .'<VPosOrderData><OrderContract>'
            .'<LastOrderStatus>1</LastOrderStatus><OrderId>9988</OrderId>'
            .'<FEC>19990</FEC><InstallmentCount>3</InstallmentCount>'
            .'<MaskedPAN>415565******6111</MaskedPAN><OrderDate>2026-01-01</OrderDate>'
            .'</OrderContract></VPosOrderData></VPosTransactionResponseContract>'
        )]);

        $status = vakifKatilim()->status('ORDER-1');

        expect($status->found)->toBeTrue()
            ->and($status->isPaid())->toBeTrue()
            ->and($status->paymentId)->toBe('9988')
            ->and($status->amount?->minorUnits)->toBe(19990)
            ->and($status->installment)->toBe(3)
            ->and($status->maskedCardNumber)->toBe('415565******6111');

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/SelectOrderByMerchantOrderId'));
    });

    it('iade edilmiş siparişi ayırt eder', function () {
        Http::fake(['bank.test/*' => Http::response(
            '<VPosTransactionResponseContract><ResponseCode>00</ResponseCode>'
            .'<VPosOrderData><OrderContract><LastOrderStatus>4</LastOrderStatus>'
            .'</OrderContract></VPosOrderData></VPosTransactionResponseContract>'
        )]);

        expect(vakifKatilim()->status('ORDER-1')->isRefunded())->toBeTrue();
    });

    it('banka tanımayınca found=false döner', function () {
        Http::fake(['bank.test/*' => Http::response(
            '<VPosTransactionResponseContract><ResponseCode>99</ResponseCode></VPosTransactionResponseContract>'
        )]);

        $status = vakifKatilim()->status('YOK');

        expect($status->found)->toBeFalse()
            ->and($status->status)->toBe(StatusResponse::STATUS_UNKNOWN);
    });
});

describe('Vakıf Katılım hareket dökümü', function () {
    it('arayüzü uygular ve SelectOrder ucunu kullanır', function () {
        Http::fake(['bank.test/*' => Http::response(vkResponse())]);

        expect(vakifKatilim())->toBeInstanceOf(SupportsOrderHistory::class);

        vakifKatilim()->orderHistory('ORDER-1');

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/SelectOrder'));
    });
});
