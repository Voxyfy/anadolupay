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
use Voxyfy\AnadoluPay\Gateways\Provider\ParatikaGateway;
use Voxyfy\AnadoluPay\Support\Money;
use Voxyfy\AnadoluPay\Tests\Support\BankTestConfig;

/**
 * Paratika'yı sabit kimlik bilgileriyle üretir.
 *
 * @param  array<string, mixed>  $overrides
 */
function paratika(array $overrides = []): ParatikaGateway
{
    /** @var ParatikaGateway $gateway */
    $gateway = BankTestConfig::make(ParatikaGateway::class, array_replace_recursive([
        'merchant_id' => '700100000',
        'username' => 'api@shop.test',
        'password' => 'apipass',
        'secret_key' => 'SECRET',
        'endpoints' => [
            'payment_api' => 'https://entegrasyon.paratika.com.tr/paratika/api/v2',
            'gateway_3d' => 'https://entegrasyon.paratika.com.tr/paratika/api/v2/post/sale3d',
            'gateway_3d_auth' => 'https://entegrasyon.paratika.com.tr/paratika/api/v2/post/auth3d',
            'gateway_3d_host' => 'https://entegrasyon.paratika.com.tr/payment',
        ],
    ], $overrides));

    return $gateway;
}

/** Oturum anahtarı yanıtı. */
function paratikaSession(string $token = 'VYD7AXJ6C446GIN55V6KKOB677VRTOZH'): array
{
    return ['responseCode' => '00', 'responseMsg' => 'Approved', 'sessionToken' => $token];
}

/**
 * İstek gövdesini diziye çözer (form-encoded).
 *
 * @return array<string, string>
 */
function paratikaBody(Request $request): array
{
    parse_str($request->body(), $fields);

    /** @var array<string, string> $fields */
    return $fields;
}

describe('Paratika ödeme akışı', function () {
    it('önce oturum anahtarı alır, sonra 3D Pay formu üretir', function () {
        Http::fake(['entegrasyon.paratika.com.tr/*' => Http::response(paratikaSession())]);

        $response = paratika()->createPayment(
            BankTestConfig::order(paymentModel: CreatePaymentData::MODEL_3D_PAY, amount: 199.90)
        );

        expect($response->success)->toBeTrue()
            // 3D Pay tek adımda satışı da tamamlar.
            ->and($response->formAction)->toBe('https://entegrasyon.paratika.com.tr/paratika/api/v2/post/sale3d/VYD7AXJ6C446GIN55V6KKOB677VRTOZH')
            ->and($response->formFields['pan'])->toBe('4155650100416111')
            ->and($response->formFields['expiryYear'])->toBe('2030')
            ->and($response->formFields['callbackUrl'])->toBe('https://shop.test/basarili');

        Http::assertSent(function (Request $request) {
            $body = paratikaBody($request);

            return $body['ACTION'] === 'SESSIONTOKEN'
                && $body['SESSIONTYPE'] === 'PAYMENTSESSION'
                && $body['MERCHANTPAYMENTID'] === 'ORDER-1'
                // Tutar ondalık, para birimi ISO alfabetik.
                && $body['AMOUNT'] === '199.90'
                && $body['CURRENCY'] === 'TRY'
                && $body['MERCHANT'] === '700100000'
                && $body['MERCHANTUSER'] === 'api@shop.test'
                && $body['RETURNURL'] === 'https://shop.test/basarili';
        });
    });

    it('klasik 3D modelinde kimlik doğrulama ucuna yönlendirir', function () {
        Http::fake(['entegrasyon.paratika.com.tr/*' => Http::response(paratikaSession())]);

        $response = paratika()->createPayment(
            BankTestConfig::order(paymentModel: CreatePaymentData::MODEL_3D_SECURE)
        );

        expect($response->formAction)->toContain('/post/auth3d/');
    });

    it('3D Host modelinde ortak ödeme sayfasına yönlendirir ve kart göndermez', function () {
        Http::fake(['entegrasyon.paratika.com.tr/*' => Http::response(paratikaSession())]);

        $response = paratika()->createPayment(
            BankTestConfig::order(paymentModel: CreatePaymentData::MODEL_3D_HOST)
        );

        expect($response->redirectUrl)->toBe('https://entegrasyon.paratika.com.tr/payment/VYD7AXJ6C446GIN55V6KKOB677VRTOZH')
            ->and($response->formFields)->toBe([]);
    });

    it('sepet satırlarını URL kodlanmış JSON olarak gönderir', function () {
        Http::fake(['entegrasyon.paratika.com.tr/*' => Http::response(paratikaSession())]);

        paratika()->createPayment(BankTestConfig::order(amount: 199.90));

        Http::assertSent(function (Request $request) {
            $body = paratikaBody($request);

            // Form kodlaması bir kez çözer; geriye Paratika'nın beklediği
            // URL kodlu JSON kalır.
            $items = json_decode(rawurldecode($body['ORDERITEMS']), true);

            return is_array($items)
                && count($items) === 1
                && $items[0]['amount'] === 199.9
                && $items[0]['quantity'] === 1;
        });
    });

    it('oturum açılamazsa Paratika hata mesajını yükseltir', function () {
        Http::fake(['entegrasyon.paratika.com.tr/*' => Http::response([
            'responseCode' => '99',
            'errorCode' => 'ERR10010',
            'errorMsg' => 'İstekte zorunlu parametrelerden biri bulunamadı',
        ])]);

        paratika()->createPayment(BankTestConfig::order());
    })->throws(PaymentFailedException::class, 'zorunlu parametrelerden');

    it('non-secure satışı oturum anahtarıyla tek istekte tamamlar', function () {
        Http::fake(['entegrasyon.paratika.com.tr/*' => Http::sequence()
            ->push(paratikaSession())
            ->push(['responseCode' => '00', 'responseMsg' => 'Approved', 'pgTranId' => '18285OQZD14766']),
        ]);

        $response = paratika()->createPayment(
            BankTestConfig::order(paymentModel: CreatePaymentData::MODEL_NON_SECURE)
        );

        expect($response->success)->toBeTrue()
            ->and($response->paymentId)->toBe('18285OQZD14766');

        Http::assertSent(function (Request $request) {
            $body = paratikaBody($request);

            return ($body['ACTION'] ?? '') === 'SALE'
                && $body['SESSIONTOKEN'] === 'VYD7AXJ6C446GIN55V6KKOB677VRTOZH'
                && $body['CARDPAN'] === '4155650100416111'
                && $body['CARDEXPIRY'] === '12.2030';
        });
    });

    it('ön provizyonda PREAUTH aksiyonunu kullanır', function () {
        Http::fake(['entegrasyon.paratika.com.tr/*' => Http::sequence()
            ->push(paratikaSession())
            ->push(['responseCode' => '00', 'pgTranId' => 'TRX-1']),
        ]);

        paratika()->preAuthorize(BankTestConfig::order(paymentModel: CreatePaymentData::MODEL_NON_SECURE));

        Http::assertSent(fn (Request $request) => (paratikaBody($request)['ACTION'] ?? '') === 'PREAUTH');
    });

    it('reddedilen satışın hata kodunu taşır', function () {
        Http::fake(['entegrasyon.paratika.com.tr/*' => Http::sequence()
            ->push(paratikaSession())
            ->push([
                'responseCode' => '99',
                'responseMsg' => 'Declined',
                'pgTranErrorCode' => '51',
                'pgTranErrorText' => 'Yetersiz bakiye',
            ]),
        ]);

        $response = paratika()->createPayment(
            BankTestConfig::order(paymentModel: CreatePaymentData::MODEL_NON_SECURE)
        );

        expect($response->success)->toBeFalse()
            ->and($response->errorMessage)->toBe('Yetersiz bakiye')
            ->and($response->errorCode)->toBe('51');
    });

    it('provizyon kapamayı POSTAUTH ile gönderir', function () {
        Http::fake(['entegrasyon.paratika.com.tr/*' => Http::response([
            'responseCode' => '00', 'pgTranId' => 'TRX-2',
        ])]);

        $response = paratika()->capture(new CapturePaymentData(orderId: 'ORDER-1', amount: 50.0));

        expect($response->success)->toBeTrue();

        Http::assertSent(function (Request $request) {
            $body = paratikaBody($request);

            return $body['ACTION'] === 'POSTAUTH'
                && $body['MERCHANTPAYMENTID'] === 'ORDER-1'
                && $body['AMOUNT'] === '50.00';
        });
    });
});

describe('Paratika dönüş doğrulaması', function () {
    /**
     * Geçerli imzalı bir dönüş üretir.
     *
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    function paratikaCallback(array $overrides = []): array
    {
        $payload = array_replace([
            'merchantPaymentId' => 'ORDER-1',
            'customerId' => 'Customer-1',
            'sessionToken' => 'VYD7AXJ6C446GIN55V6KKOB677VRTOZH',
            'responseCode' => '00',
            'responseMsg' => 'Approved',
            'random' => 'tpN2ynMYAn',
            'pgTranId' => '17012ONNI07013454',
        ], $overrides);

        $payload['sdSha512'] = hash('sha512', implode('|', [
            $payload['merchantPaymentId'],
            $payload['customerId'],
            $payload['sessionToken'],
            $payload['responseCode'],
            $payload['random'],
            'SECRET',
        ]));

        return $payload;
    }

    it('3D Pay dönüşünde ikinci istek göndermez', function () {
        Http::fake(['entegrasyon.paratika.com.tr/*' => Http::response(['responseCode' => '00'])]);

        $response = paratika()->verify(new VerifyPaymentData(paratikaCallback()));

        expect($response->success)->toBeTrue()
            ->and($response->paymentId)->toBe('17012ONNI07013454');

        Http::assertNothingSent();
    });

    it('ayrık 3D doğrulamasından sonra satışı tamamlar', function () {
        Http::fake(['entegrasyon.paratika.com.tr/*' => Http::response([
            'responseCode' => '00', 'pgTranId' => 'TRX-3',
        ])]);

        $response = paratika()->verify(new VerifyPaymentData(
            paratikaCallback(['auth3DToken' => 'AUTH-TOKEN', 'mdStatus' => '1'])
        ));

        expect($response->success)->toBeTrue()
            ->and($response->paymentId)->toBe('TRX-3');

        Http::assertSent(function (Request $request) {
            $body = paratikaBody($request);

            return $body['ACTION'] === 'SALE'
                && $body['SESSIONTOKEN'] === 'VYD7AXJ6C446GIN55V6KKOB677VRTOZH';
        });
    });

    it('3D doğrulaması başarısızsa satış istemez', function () {
        Http::fake(['entegrasyon.paratika.com.tr/*' => Http::response(['responseCode' => '00'])]);

        $response = paratika()->verify(new VerifyPaymentData(
            paratikaCallback(['responseCode' => '99', 'responseMsg' => 'Declined'])
        ));

        expect($response->success)->toBeFalse();

        Http::assertNothingSent();
    });

    it('imzası bozuk dönüşü reddeder', function () {
        $payload = paratikaCallback();
        $payload['merchantPaymentId'] = 'ORDER-2';

        paratika()->verify(new VerifyPaymentData($payload));
    })->throws(InvalidSignatureException::class);

    it('kullanımdan kalkmış SD_SHA512 alanını kabul etmez', function () {
        $payload = paratikaCallback();
        // Eski alanı doğru, yenisini bozuk gönderiyoruz: driver yeni
        // alana bakmalı ve dönüşü reddetmelidir.
        $payload['SD_SHA512'] = $payload['sdSha512'];
        $payload['sdSha512'] = str_repeat('0', 128);

        paratika()->verify(new VerifyPaymentData($payload));
    })->throws(InvalidSignatureException::class);
});

describe('Paratika iade ve iptal', function () {
    it('iadeyi REFUND aksiyonuyla gönderir', function () {
        Http::fake(['entegrasyon.paratika.com.tr/*' => Http::response([
            'responseCode' => '00', 'pgTranId' => 'TRX-4',
        ])]);

        $response = paratika()->refund(new RefundPaymentData(paymentId: 'ORDER-1', amount: 20.0));

        expect($response->success)->toBeTrue()
            ->and($response->refundId)->toBe('TRX-4');

        Http::assertSent(function (Request $request) {
            $body = paratikaBody($request);

            return $body['ACTION'] === 'REFUND'
                && $body['MERCHANTPAYMENTID'] === 'ORDER-1'
                // Tutar verilince Paratika bunu kısmi iade sayar.
                && $body['AMOUNT'] === '20.00';
        });
    });

    it('tutar verilmezse tam iade ister', function () {
        Http::fake(['entegrasyon.paratika.com.tr/*' => Http::response(['responseCode' => '00'])]);

        paratika()->refund(new RefundPaymentData(paymentId: 'ORDER-1'));

        Http::assertSent(fn (Request $request) => ! array_key_exists('AMOUNT', paratikaBody($request)));
    });

    it('iptali VOID aksiyonuyla gönderir', function () {
        Http::fake(['entegrasyon.paratika.com.tr/*' => Http::response(['responseCode' => '00'])]);

        paratika()->cancel(new RefundPaymentData(paymentId: 'ORDER-1'));

        Http::assertSent(fn (Request $request) => paratikaBody($request)['ACTION'] === 'VOID');
    });

    it('reddedilen iadeyi başarılı saymaz', function () {
        Http::fake(['entegrasyon.paratika.com.tr/*' => Http::response([
            'responseCode' => '99', 'errorMsg' => 'İade tutarı işlem tutarını aşıyor',
        ])]);

        $response = paratika()->refund(new RefundPaymentData(paymentId: 'ORDER-1'));

        expect($response->success)->toBeFalse()
            ->and($response->errorMessage)->toBe('İade tutarı işlem tutarını aşıyor');
    });
});

describe('Paratika sorgular', function () {
    /**
     * @param  list<array<string, mixed>>  $transactions
     * @return array<string, mixed>
     */
    function paratikaQuery(array $transactions): array
    {
        return [
            'responseCode' => '00',
            'responseMsg' => 'Approved',
            'transactionCount' => (string) count($transactions),
            'transactionList' => $transactions,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    function paratikaTrx(array $overrides = []): array
    {
        return array_replace([
            'pgTranId' => '18285OQZD14766',
            'amount' => 80,
            'currency' => 'TRY',
            'transactionStatus' => 'AP',
            'transactionType' => 'SALE',
            'installmentCount' => 1,
            'panLast4' => '4026',
            'bin' => '402277',
            'timeCreated' => '2018-10-12 14:16:26.474',
        ], $overrides);
    }

    it('başarılı satışı ödendi olarak eşler', function () {
        Http::fake(['entegrasyon.paratika.com.tr/*' => Http::response(paratikaQuery([paratikaTrx()]))]);

        $status = paratika()->status('ORDER-1');

        expect($status->found)->toBeTrue()
            ->and($status->status)->toBe(StatusResponse::STATUS_PAID)
            ->and($status->amount?->minorUnits)->toBe(8000)
            ->and($status->paymentId)->toBe('18285OQZD14766')
            ->and($status->maskedCardNumber)->toBe('402277******4026');

        Http::assertSent(fn (Request $request) => paratikaBody($request)['ACTION'] === 'QUERYTRANSACTION');
    });

    it('iade kaydını görmezden gelmez', function () {
        // Satış kaydı hâlâ AP; iade ayrı bir işlem olarak listelenir.
        Http::fake(['entegrasyon.paratika.com.tr/*' => Http::response(paratikaQuery([
            paratikaTrx(),
            paratikaTrx(['transactionType' => 'REFUND', 'amount' => 80]),
        ]))]);

        $status = paratika()->status('ORDER-1');

        expect($status->status)->toBe(StatusResponse::STATUS_REFUNDED)
            ->and($status->refundedAmount?->minorUnits)->toBe(8000);
    });

    it('kısmi iadede ödemeyi ayakta tutar', function () {
        Http::fake(['entegrasyon.paratika.com.tr/*' => Http::response(paratikaQuery([
            paratikaTrx(),
            paratikaTrx(['transactionType' => 'PTREFUND', 'amount' => 30]),
        ]))]);

        $status = paratika()->status('ORDER-1');

        expect($status->status)->toBe(StatusResponse::STATUS_PAID)
            ->and($status->refundedAmount?->minorUnits)->toBe(3000);
    });

    it('iptal edilen işlemi ödendi saymaz', function () {
        Http::fake(['entegrasyon.paratika.com.tr/*' => Http::response(paratikaQuery([
            paratikaTrx(),
            paratikaTrx(['transactionType' => 'VOID']),
        ]))]);

        expect(paratika()->status('ORDER-1')->status)->toBe(StatusResponse::STATUS_CANCELLED);
    });

    it('başarısız iade kaydını iade saymaz', function () {
        Http::fake(['entegrasyon.paratika.com.tr/*' => Http::response(paratikaQuery([
            paratikaTrx(),
            paratikaTrx(['transactionType' => 'REFUND', 'transactionStatus' => 'FA', 'amount' => 80]),
        ]))]);

        $status = paratika()->status('ORDER-1');

        expect($status->status)->toBe(StatusResponse::STATUS_PAID)
            ->and($status->refundedAmount)->toBeNull();
    });

    it('başarısız denemeler arasından onaylanan satışı seçer', function () {
        Http::fake(['entegrasyon.paratika.com.tr/*' => Http::response(paratikaQuery([
            paratikaTrx(['transactionStatus' => 'FA', 'pgTranId' => 'FAILED-1']),
            paratikaTrx(['pgTranId' => 'OK-1']),
        ]))]);

        $status = paratika()->status('ORDER-1');

        expect($status->status)->toBe(StatusResponse::STATUS_PAID)
            ->and($status->paymentId)->toBe('OK-1');
    });

    it('kapatılmamış ön provizyonu ödeme saymaz', function () {
        Http::fake(['entegrasyon.paratika.com.tr/*' => Http::response(paratikaQuery([
            paratikaTrx(['transactionType' => 'PREAUTH']),
        ]))]);

        expect(paratika()->status('ORDER-1')->status)->toBe(StatusResponse::STATUS_PRE_AUTHORIZED);
    });

    it('manuel incelemedeki işlemi başarılı saymaz', function () {
        Http::fake(['entegrasyon.paratika.com.tr/*' => Http::response(paratikaQuery([
            paratikaTrx(['transactionStatus' => 'MR']),
        ]))]);

        expect(paratika()->status('ORDER-1')->status)->toBe(StatusResponse::STATUS_PENDING);
    });

    it('bulunamayan siparişi başarılı saymaz', function () {
        Http::fake(['entegrasyon.paratika.com.tr/*' => Http::response(paratikaQuery([]))]);

        $status = paratika()->status('YOK');

        expect($status->found)->toBeFalse()
            ->and($status->status)->toBe(StatusResponse::STATUS_UNKNOWN);
    });

    it('BIN sorgusunu eşler', function () {
        Http::fake(['entegrasyon.paratika.com.tr/*' => Http::response([
            'responseCode' => '00',
            'bin' => [
                'bin' => '450803',
                'cardBrand' => 'VISA',
                'cardType' => 'CREDIT',
                'cardLevel' => 'BUSINESS',
                'issuer' => 'T. IS BANKASI A.S.',
                'countryIsoA3' => 'TUR',
            ],
        ])]);

        $bin = paratika()->binLookup('450803');

        expect($bin->found)->toBeTrue()
            ->and($bin->bankName)->toBe('T. IS BANKASI A.S.')
            ->and($bin->brand)->toBe('VISA')
            ->and($bin->isCredit())->toBeTrue()
            ->and($bin->commercial)->toBeTrue()
            ->and($bin->domestic)->toBeTrue();
    });

    it('taksit seçeneklerini POS’lar arasında düzleştirir ve sayısal olmayanları atar', function () {
        Http::fake(['entegrasyon.paratika.com.tr/*' => Http::response([
            'responseCode' => '00',
            'paymentSystemList' => [[
                'name' => 'My Finans Webpos Online Account (Test)',
                'installmentList' => [
                    // Tek çekim taksit seçeneği değildir.
                    ['count' => 'NOT_ON_US', 'customerCostCommissionRate' => 0],
                    ['count' => '3', 'customerCostCommissionRate' => 2.5],
                    ['count' => '6', 'customerCostCommissionRate' => 5],
                ],
            ]],
        ])]);

        $options = paratika()->installmentOptions(Money::fromDecimal(100.0));

        expect($options)->toHaveCount(2)
            ->and($options[0]->count)->toBe(3)
            ->and($options[0]->commissionRate)->toBe(2.5)
            ->and($options[0]->bankName)->toBe('My Finans Webpos Online Account (Test)');
    });

    it('paratika preset anahtarından çözümlenir', function () {
        expect(app(AnadoluPay::class)->driver('paratika'))->toBeInstanceOf(ParatikaGateway::class);
    });
});
