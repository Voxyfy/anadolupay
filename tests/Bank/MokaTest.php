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
use Voxyfy\AnadoluPay\Gateways\Provider\MokaGateway;
use Voxyfy\AnadoluPay\Tests\Support\BankTestConfig;
use Voxyfy\AnadoluPay\Tests\Support\CallsProtected;

/** Moka dokümanındaki örnek CodeForHash. */
const MOKA_CODE = '9FDFBDFC-42C5-417E-AA93-E4D9D5312AAC';

/** Aynı dokümandaki başarılı işlem hash'i. */
const MOKA_HASH_SUCCESS = 'cdb7869505bdaaac2f4c891fc9ed889885fd7a0c880127ab5d508883efa3ee83';

/** Aynı dokümandaki başarısız işlem hash'i. */
const MOKA_HASH_FAILURE = 'acc929d261fdbf9c41de3db1ae854b1ee1e46344fad0292fd4bbbc43d094c2a3';

/**
 * Moka'yı sabit kimlik bilgileriyle üretir.
 *
 * @param  array<string, mixed>  $overrides
 */
function moka(array $overrides = []): MokaGateway
{
    /** @var MokaGateway $gateway */
    $gateway = BankTestConfig::make(MokaGateway::class, array_replace_recursive([
        'merchant_id' => 'DEALER1',
        'username' => 'apiuser',
        'password' => 'apipass',
        'endpoints' => ['payment_api' => 'https://service.refmokaunited.com'],
    ], $overrides));

    return $gateway;
}

/**
 * Moka yanıt zarfı.
 *
 * @param  array<string, mixed>  $data
 * @return array<string, mixed>
 */
function mokaData(array $data): array
{
    return ['Data' => $data, 'ResultCode' => 'Success', 'ResultMessage' => '', 'Exception' => null];
}

/**
 * Geçerli imzalı bir 3D dönüşü.
 *
 * @return array<string, string>
 */
function mokaCallback(string $hash = MOKA_HASH_SUCCESS): array
{
    return [
        'hashValue' => $hash,
        'resultCode' => '',
        'resultMessage' => '',
        'trxCode' => 'ORDER-17131QQFG04026575',
        'OtherTrxCode' => 'ORDER-1',
    ];
}

describe('Moka kimlik doğrulama', function () {
    it('CheckKey’i dokümandaki formüle göre üretir', function () {
        $checkKey = CallsProtected::call(moka(), 'checkKey');

        // DealerCode + "MK" + Username + "PD" + Password
        expect($checkKey)->toBe(hash('sha256', 'DEALER1MKapiuserPDapipass'));
    });

    it('her isteğe kimlik bloğunu ekler', function () {
        Http::fake(['service.refmokaunited.com/*' => Http::response(mokaData([
            'Url' => 'https://service.refmokaunited.com/PaymentDealerThreeDProcess?threeDTrxCode=x',
            'CodeForHash' => MOKA_CODE,
        ]))]);

        moka()->createPayment(BankTestConfig::order());

        Http::assertSent(function (Request $request) {
            $auth = json_decode($request->body(), true)['PaymentDealerAuthentication'];

            return $auth['DealerCode'] === 'DEALER1'
                && $auth['Username'] === 'apiuser'
                && $auth['CheckKey'] === hash('sha256', 'DEALER1MKapiuserPDapipass');
        });
    });

    it('moka preset anahtarından çözümlenir', function () {
        expect(app(AnadoluPay::class)->driver('moka'))->toBeInstanceOf(MokaGateway::class);
    });
});

describe('Moka ödeme akışı', function () {
    it('3D akışında Moka’nın bağlantısına yönlendirir', function () {
        Http::fake(['service.refmokaunited.com/*' => Http::response(mokaData([
            'Url' => 'https://service.refmokaunited.com/PaymentDealerThreeDProcess?threeDTrxCode=abc',
            'CodeForHash' => MOKA_CODE,
        ]))]);

        $response = moka()->createPayment(BankTestConfig::order(amount: 199.90));

        expect($response->success)->toBeTrue()
            ->and($response->redirectUrl)->toContain('threeDTrxCode=abc')
            // CodeForHash saklanabilsin diye yanıtta taşınır.
            ->and($response->raw['code_for_hash'])->toBe(MOKA_CODE);

        Http::assertSent(function (Request $request) {
            $body = json_decode($request->body(), true)['PaymentDealerRequest'];

            return str_ends_with($request->url(), '/PaymentDealer/DoDirectPaymentThreeD')
                // Tutar ondalık, para birimi Moka'nın kendi kısaltması.
                && (float) $body['Amount'] === 199.9
                && $body['Currency'] === 'TL'
                && $body['InstallmentNumber'] === 0
                && $body['OtherTrxCode'] === 'ORDER-1'
                && $body['ReturnHash'] === 1
                && $body['IsPreAuth'] === 0
                && $body['RedirectUrl'] === 'https://shop.test/basarili'
                && $body['CardNumber'] === '4155650100416111'
                && $body['ExpYear'] === '2030';
        });
    });

    it('taksitli satışta taksit sayısını gönderir', function () {
        Http::fake(['service.refmokaunited.com/*' => Http::response(mokaData([
            'Url' => 'https://x.test/3d', 'CodeForHash' => MOKA_CODE,
        ]))]);

        moka()->createPayment(BankTestConfig::order(installment: 6));

        Http::assertSent(fn (Request $request) => json_decode($request->body(), true)['PaymentDealerRequest']['InstallmentNumber'] === 6);
    });

    it('bağlantı alınamazsa açık hata verir', function () {
        Http::fake(['service.refmokaunited.com/*' => Http::response(mokaData([]))]);

        moka()->createPayment(BankTestConfig::order());
    })->throws(PaymentFailedException::class, 'Moka 3D ödeme bağlantısı alınamadı.');

    it('Moka isteği hiç işleyemediyse dış zarftaki hatayı yükseltir', function () {
        Http::fake(['service.refmokaunited.com/*' => Http::response([
            'Data' => null,
            'ResultCode' => 'PaymentDealer.CheckPaymentDealerAuthentication.InvalidAccount',
            'ResultMessage' => '',
        ])]);

        moka()->createPayment(BankTestConfig::order());
    })->throws(PaymentFailedException::class, 'InvalidAccount');

    it('non-secure ödemede IsSuccessful alanına bakar', function () {
        Http::fake(['service.refmokaunited.com/*' => Http::response(mokaData([
            'IsSuccessful' => true,
            'ResultCode' => '',
            'VirtualPosOrderId' => 'ORDER-17131QMlH04026199',
        ]))]);

        $response = moka()->createPayment(
            BankTestConfig::order(paymentModel: CreatePaymentData::MODEL_NON_SECURE)
        );

        expect($response->success)->toBeTrue()
            ->and($response->paymentId)->toBe('ORDER-17131QMlH04026199');

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/PaymentDealer/DoDirectPayment'));
    });

    it('bankanın reddettiği ödemeyi başarılı saymaz', function () {
        Http::fake(['service.refmokaunited.com/*' => Http::response(mokaData([
            'IsSuccessful' => false,
            'ResultCode' => '051',
            'ResultMessage' => 'Yetersiz bakiye',
        ]))]);

        $response = moka()->createPayment(
            BankTestConfig::order(paymentModel: CreatePaymentData::MODEL_NON_SECURE)
        );

        expect($response->success)->toBeFalse()
            ->and($response->errorMessage)->toBe('Yetersiz bakiye')
            ->and($response->errorCode)->toBe('051');
    });

    it('ön provizyonu IsPreAuth ile bildirir', function () {
        Http::fake(['service.refmokaunited.com/*' => Http::response(mokaData([
            'IsSuccessful' => true, 'VirtualPosOrderId' => 'ORDER-1',
        ]))]);

        moka()->preAuthorize(BankTestConfig::order(paymentModel: CreatePaymentData::MODEL_NON_SECURE));

        Http::assertSent(fn (Request $request) => json_decode($request->body(), true)['PaymentDealerRequest']['IsPreAuth'] === 1);
    });

    it('provizyonu DoCapture ile kapatır', function () {
        Http::fake(['service.refmokaunited.com/*' => Http::response(mokaData([
            'IsSuccessful' => true, 'VirtualPosOrderId' => 'ORDER-17131QQFG04026575',
        ]))]);

        $response = moka()->capture(new CapturePaymentData(
            orderId: 'ORDER-17131QQFG04026575',
            amount: 50.0,
        ));

        expect($response->success)->toBeTrue();

        Http::assertSent(function (Request $request) {
            $body = json_decode($request->body(), true)['PaymentDealerRequest'];

            /*
             * Verilen değer sizin sipariş numaranız sayılır. Moka'nın işlem
             * kodu biçimi kuruluma göre değişiyor (dokümanda `ORDER-…`,
             * gerçek bir bayide `Test-df91b14d-…`), bu yüzden biçime bakarak
             * tahmin edilmiyor.
             */
            return str_ends_with($request->url(), '/PaymentDealer/DoCapture')
                && $body['OtherTrxCode'] === 'ORDER-17131QQFG04026575'
                && $body['VirtualPosOrderId'] === ''
                && (float) $body['Amount'] === 50.0;
        });
    });
});

describe('Moka dönüş doğrulaması', function () {
    /*
     * Aşağıdaki hash'ler developer.mokaunited.com'daki
     * "hashValue nasıl hesaplanır" sayfasından birebir alınmıştır.
     */
    it('başarılı dönüşü dokümandaki hash vektörüyle doğrular', function () {
        $response = moka()->verify(new VerifyPaymentData(
            mokaCallback(),
            order: ['code_for_hash' => MOKA_CODE],
        ));

        expect($response->success)->toBeTrue()
            // trxCode iptal/iade için saklanacak numaradır.
            ->and($response->paymentId)->toBe('ORDER-17131QQFG04026575');
    });

    it('başarısızlık hash’ini başarı saymaz', function () {
        $response = moka()->verify(new VerifyPaymentData(
            mokaCallback(MOKA_HASH_FAILURE),
            order: ['code_for_hash' => MOKA_CODE],
        ));

        expect($response->success)->toBeFalse()
            ->and($response->status)->toBe('failed');
    });

    it('küçük harfli CodeForHash ile de doğrular', function () {
        $response = moka()->verify(new VerifyPaymentData(
            mokaCallback(),
            order: ['code_for_hash' => strtolower(MOKA_CODE)],
        ));

        expect($response->success)->toBeTrue();
    });

    it('T ya da F ile eşleşmeyen hash’i reddeder', function () {
        moka()->verify(new VerifyPaymentData(
            mokaCallback(str_repeat('0', 64)),
            order: ['code_for_hash' => MOKA_CODE],
        ));
    })->throws(InvalidSignatureException::class);

    it('CodeForHash olmadan sonucu tahmin etmez', function () {
        moka()->verify(new VerifyPaymentData(mokaCallback()));
    })->throws(PaymentFailedException::class, 'code_for_hash');

    it('bildirimi düz metin OK ile onaylar', function () {
        expect(moka()->webhookAcknowledgement(true))->toBe('OK')
            ->and(moka()->webhookAcknowledgementContentType())->toBe('text/plain');
    });
});

describe('Moka iade ve iptal', function () {
    it('iadeyi DoCreateRefundRequest ucuna gönderir', function () {
        Http::fake(['service.refmokaunited.com/*' => Http::response(mokaData([
            'IsSuccessful' => true, 'VirtualPosOrderId' => 'ORDER-1',
        ]))]);

        $response = moka()->refund(new RefundPaymentData(paymentId: 'ORDER-1', amount: 20.0));

        expect($response->success)->toBeTrue();

        Http::assertSent(function (Request $request) {
            $body = json_decode($request->body(), true)['PaymentDealerRequest'];

            return str_ends_with($request->url(), '/PaymentDealer/DoCreateRefundRequest')
                && (float) $body['Amount'] === 20.0;
        });
    });

    it('tutar verilmezse tam iade ister', function () {
        Http::fake(['service.refmokaunited.com/*' => Http::response(mokaData(['IsSuccessful' => true]))]);

        moka()->refund(new RefundPaymentData(paymentId: 'SIPARIS-1'));

        Http::assertSent(function (Request $request) {
            $body = json_decode($request->body(), true)['PaymentDealerRequest'];

            // Sipariş numarası ORDER- ile başlamıyorsa OtherTrxCode'dur.
            return $body['OtherTrxCode'] === 'SIPARIS-1'
                && $body['VirtualPosOrderId'] === ''
                && ! array_key_exists('Amount', $body);
        });
    });

    it('iptali DoVoid ucuna dış iptal sebebiyle gönderir', function () {
        Http::fake(['service.refmokaunited.com/*' => Http::response(mokaData(['IsSuccessful' => true]))]);

        moka()->cancel(new RefundPaymentData(paymentId: 'ORDER-1'));

        Http::assertSent(function (Request $request) {
            $body = json_decode($request->body(), true)['PaymentDealerRequest'];

            return str_ends_with($request->url(), '/PaymentDealer/DoVoid')
                && $body['VoidRefundReason'] === 2;
        });
    });

    it('reddedilen iadeyi başarılı saymaz', function () {
        Http::fake(['service.refmokaunited.com/*' => Http::response(mokaData([
            'IsSuccessful' => false, 'ResultMessage' => 'İade edilebilir tutar yok',
        ]))]);

        $response = moka()->refund(new RefundPaymentData(paymentId: 'ORDER-1'));

        expect($response->success)->toBeFalse()
            ->and($response->errorMessage)->toBe('İade edilebilir tutar yok');
    });
});

describe('Moka sorgular', function () {
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    function mokaDetail(array $overrides = []): array
    {
        return mokaData([
            'IsSuccessful' => true,
            'PaymentDetail' => array_replace([
                'DealerPaymentId' => 27405,
                'OtherTrxCode' => 'ORDER-1',
                'CardNumberFirstSix' => '554960',
                'CardNumberLastFour' => '5523',
                'PaymentDate' => '2017-02-28T14:42:17.26',
                'Amount' => 20.10,
                'RefAmount' => 5.10,
                'CurrencyCode' => 'TL',
                'InstallmentNumber' => 0,
                'PaymentStatus' => 2,
                'TrxStatus' => 1,
            ], $overrides),
            'PaymentTrxDetailList' => [],
        ]);
    }

    it('ödeme durumunu ve iade edilen tutarı eşler', function () {
        Http::fake(['service.refmokaunited.com/*' => Http::response(mokaDetail())]);

        $status = moka()->status('ORDER-1');

        expect($status->found)->toBeTrue()
            ->and($status->status)->toBe(StatusResponse::STATUS_PAID)
            ->and($status->amount?->minorUnits)->toBe(2010)
            ->and($status->refundedAmount?->minorUnits)->toBe(510)
            // Moka TL der; paket ISO koduna çevirir.
            ->and($status->amount?->currency)->toBe('TRY')
            ->and($status->maskedCardNumber)->toBe('554960******5523');
    });

    it('başarısız işlemi ödeme durumuna bakarak başarılı saymaz', function () {
        // PaymentStatus 2 (Ödeme) ama TrxStatus 2 (Başarısız).
        Http::fake(['service.refmokaunited.com/*' => Http::response(mokaDetail(['TrxStatus' => 2]))]);

        expect(moka()->status('ORDER-1')->status)->toBe(StatusResponse::STATUS_FAILED);
    });

    it('ön provizyonu ödeme saymaz', function () {
        Http::fake(['service.refmokaunited.com/*' => Http::response(mokaDetail(['PaymentStatus' => 1]))]);

        expect(moka()->status('ORDER-1')->status)->toBe(StatusResponse::STATUS_PRE_AUTHORIZED);
    });

    it('tam iade edilen ödemeyi refunded olarak bildirir', function () {
        Http::fake(['service.refmokaunited.com/*' => Http::response(mokaDetail(['PaymentStatus' => 4]))]);

        expect(moka()->status('ORDER-1')->isRefunded())->toBeTrue();
    });

    it('tanımadığı durum kodunu ödendi saymaz', function () {
        Http::fake(['service.refmokaunited.com/*' => Http::response(mokaDetail(['PaymentStatus' => 9]))]);

        expect(moka()->status('ORDER-1')->status)->toBe(StatusResponse::STATUS_UNKNOWN);
    });

    it('bulunamayan ödemeyi başarılı saymaz', function () {
        Http::fake(['service.refmokaunited.com/*' => Http::response(mokaData(['IsSuccessful' => false]))]);

        $status = moka()->status('YOK');

        expect($status->found)->toBeFalse()
            ->and($status->status)->toBe(StatusResponse::STATUS_UNKNOWN);
    });

    it('BIN sorgusunu eşler', function () {
        Http::fake(['service.refmokaunited.com/*' => Http::response(mokaData([
            'BankName' => 'Garanti Bankası',
            'BinNumber' => '55496012',
            'CardType' => 'MASTER',
            'CreditType' => 'CreditCard',
            'ProductCategory' => 'Bireysel',
        ]))]);

        $bin = moka()->binLookup('55496012');

        expect($bin->found)->toBeTrue()
            ->and($bin->bankName)->toBe('Garanti Bankası')
            ->and($bin->brand)->toBe('MASTER')
            ->and($bin->isCredit())->toBeTrue()
            ->and($bin->commercial)->toBeFalse();

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/PaymentDealer/GetBankCardInformation'));
    });

    it('işlem dökümünü ödeme detay ucundan okur', function () {
        Http::fake(['service.refmokaunited.com/*' => Http::response(mokaDetail())]);

        $history = moka()->orderHistory('ORDER-1');

        expect($history)->toHaveKey('PaymentTrxDetailList');

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/PaymentDealer/GetDealerPaymentTrxDetailList'));
    });
    /*
     * Gerçek Moka test servisinde ortaya çıktı: servislerin çoğu istek
     * gövdesini `PaymentDealerRequest` altında bekler ama BIN sorgusu
     * `BankCardInformationRequest` ister. Yanlış sarmalayıcıya
     * `GetBankCardInformation.InvalidRequest` döner — yani BIN sorgusu
     * hiç çalışmıyordu.
     */
    it('BIN sorgusunu BankCardInformationRequest sarmalayıcısıyla gönderir', function () {
        Http::fake(['service.refmokaunited.com/*' => Http::response(mokaData([
            'BankName' => 'İŞ BANKASI', 'CardType' => 'VISA', 'CreditType' => 'CreditCard',
        ]))]);

        moka()->binLookup('41834411');

        Http::assertSent(function (Request $request) {
            $body = json_decode($request->body(), true);

            return array_key_exists('BankCardInformationRequest', $body)
                && ! array_key_exists('PaymentDealerRequest', $body)
                && $body['BankCardInformationRequest']['BinNumber'] === '41834411';
        });
    });

    it('diğer servisler PaymentDealerRequest kullanmaya devam eder', function () {
        Http::fake(['service.refmokaunited.com/*' => Http::response(mokaData(['IsSuccessful' => true]))]);

        moka()->refund(new RefundPaymentData(paymentId: 'ORDER-1'));

        Http::assertSent(fn (Request $request) => array_key_exists(
            'PaymentDealerRequest',
            json_decode($request->body(), true),
        ));
    });
    it('Moka’nın işlem kodu bildirilirse VirtualPosOrderId olarak gönderir', function () {
        Http::fake(['service.refmokaunited.com/*' => Http::response(mokaData(['IsSuccessful' => true]))]);

        moka()->cancel(new RefundPaymentData(
            paymentId: 'SIPARIS-1',
            metadata: ['virtual_pos_order_id' => 'Test-df91b14d-4d37-41e6-9ce6-491542b9a35b'],
        ));

        Http::assertSent(function (Request $request) {
            $body = json_decode($request->body(), true)['PaymentDealerRequest'];

            return $body['VirtualPosOrderId'] === 'Test-df91b14d-4d37-41e6-9ce6-491542b9a35b'
                && $body['OtherTrxCode'] === '';
        });
    });
});
