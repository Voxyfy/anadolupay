<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Voxyfy\AnadoluPay\DTO\CapturePaymentData;
use Voxyfy\AnadoluPay\DTO\CreatePaymentData;
use Voxyfy\AnadoluPay\DTO\RefundPaymentData;
use Voxyfy\AnadoluPay\DTO\StatusResponse;
use Voxyfy\AnadoluPay\DTO\VerifyPaymentData;
use Voxyfy\AnadoluPay\Exceptions\InvalidSignatureException;
use Voxyfy\AnadoluPay\Gateways\Bank\InterPosGateway;
use Voxyfy\AnadoluPay\Gateways\Bank\PayForGateway;
use Voxyfy\AnadoluPay\Tests\Support\BankTestConfig;
use Voxyfy\AnadoluPay\Tests\Support\CallsProtected;

/**
 * InterPos (DenizBank) ve PayFor (QNB / Ziraat Katılım) benzer bir
 * protokol kullanır: düz alanlar, sha1+base64 imza, ayraçsız birleşim.
 * Aradaki farklar — InterPos form-encoded, PayFor XML — burada kilitlenir.
 */
describe('InterPos (DenizBank)', function () {
    beforeEach(function () {
        $this->gateway = BankTestConfig::make(InterPosGateway::class);
    });

    it('3D formunu imzalı üretir', function () {
        $fields = $this->gateway->createPayment(BankTestConfig::order(amount: 199.90))->formFields;

        expect($fields['ShopCode'])->toBe('MERCHANT1')
            ->and($fields['TxnType'])->toBe('Auth')
            ->and($fields['SecureType'])->toBe('3DModel')
            // InterPos tutarı doğal gösterimle bekler: 199.9, "199.90" değil
            ->and($fields['PurchAmount'])->toBe('199.9')
            ->and($fields['Currency'])->toBe('949')
            // Tek çekimde taksit alanı boş
            ->and($fields['InstallmentCount'])->toBe('')
            ->and($fields['CardType'])->toBe('0')
            ->and($fields['Hash'])->toMatch('#^[A-Za-z0-9+/]{27}=$#');
    });

    it('ödeme modelini SecureType alanına çevirir', function () {
        foreach ([
            CreatePaymentData::MODEL_3D_SECURE => '3DModel',
            CreatePaymentData::MODEL_3D_PAY => '3DPay',
            CreatePaymentData::MODEL_3D_HOST => '3DHost',
        ] as $model => $expected) {
            $fields = $this->gateway->createPayment(BankTestConfig::order(paymentModel: $model))->formFields;

            expect($fields['SecureType'])->toBe($expected);
        }
    });

    it('dönüş imzasını HASHPARAMS alanına göre doğrular', function () {
        $payload = ['OrderId' => 'ORDER-1', 'ProcReturnCode' => '00', 'HASHPARAMS' => 'OrderId:ProcReturnCode'];
        $payload['HASH'] = base64_encode(hash('sha1', 'ORDER-1'.'00'.'SECRETKEY', true));

        expect(CallsProtected::call($this->gateway, 'checkCallbackHash', $payload))->toBeTrue();
    });

    it('bozuk imzayı reddeder', function () {
        $this->gateway->verify(new VerifyPaymentData([
            'OrderId' => 'ORDER-1',
            'HASHPARAMS' => 'OrderId',
            'HASH' => 'sahte',
        ]));
    })->throws(InvalidSignatureException::class);

    it('imza doğrulaması kapalıyken provizyonu tamamlar', function () {
        Http::fake(['bank.test/*' => Http::response('ProcReturnCode=00&TransId=TX-1')]);

        $gateway = BankTestConfig::make(InterPosGateway::class, ['verify_hash' => false]);

        $result = $gateway->verify(new VerifyPaymentData([
            'OrderId' => 'ORDER-1',
            '3DStatus' => '1',
            'SecureType' => '3DModel',
            'MD' => 'MD-1',
            'PayerAuthenticationCode' => 'CAVV-1',
        ]));

        expect($result->success)->toBeTrue()
            ->and($result->paymentId)->toBe('TX-1');

        Http::assertSent(fn ($request) => str_contains($request->body(), 'MD=MD-1')
            && str_contains($request->body(), 'SecureType=NonSecure'));
    });

    it('iadeyi Refund işlem tipiyle gönderir', function () {
        Http::fake(['bank.test/*' => Http::response('ProcReturnCode=00&TransId=RF-1')]);

        $response = $this->gateway->refund(new RefundPaymentData('ORDER-1', 49.90));

        expect($response->success)->toBeTrue()
            ->and($response->refundId)->toBe('RF-1');

        Http::assertSent(fn ($request) => str_contains($request->body(), 'TxnType=Refund')
            && str_contains($request->body(), 'orgOrderId=ORDER-1')
            && str_contains($request->body(), 'PurchAmount=49.9'));
    });

    it('durum sorgusunda iptal ve iadeyi ayırt eder', function () {
        Http::fake(['bank.test/*' => Http::response(
            'ProcReturnCode=00&OrderId=ORDER-1&TransId=TX-1&PurchAmount=199.9&RefundedAmount=49.90&VoidDate=1.1.0001 00:00:00'
        )]);

        $status = $this->gateway->status('ORDER-1');

        expect($status->found)->toBeTrue()
            // VoidDate boş tarih; iptal değil iade sayılmalı
            ->and($status->isRefunded())->toBeTrue()
            ->and($status->refundedAmount?->minorUnits)->toBe(4990)
            ->and($status->amount?->minorUnits)->toBe(19990);
    });

    it('ön provizyon işlem tipini PreAuth yapar', function () {
        $fields = $this->gateway->preAuthorize(BankTestConfig::order())->formFields;

        expect($fields['TxnType'])->toBe('PreAuth');
    });

    it('kapamayı PostAuth ile gönderir', function () {
        Http::fake(['bank.test/*' => Http::response('ProcReturnCode=00&TransId=CP-1')]);

        $response = $this->gateway->capture(new CapturePaymentData('ORDER-1', 99.90));

        expect($response->success)->toBeTrue();

        Http::assertSent(fn ($request) => str_contains($request->body(), 'TxnType=PostAuth')
            && str_contains($request->body(), 'PurchAmount=99.9'));
    });
});

describe('PayFor (QNB / Ziraat Katılım)', function () {
    beforeEach(function () {
        $this->gateway = BankTestConfig::make(PayForGateway::class);
    });

    it('3D formunu imzalı üretir', function () {
        $fields = $this->gateway->createPayment(BankTestConfig::order(amount: 199.90))->formFields;

        expect($fields['MbrId'])->toBe('5')
            ->and($fields['MerchantID'])->toBe('MERCHANT1')
            ->and($fields['SecureType'])->toBe('3DModel')
            ->and($fields['TxnType'])->toBe('Auth')
            ->and($fields['PurchAmount'])->toBe('199.9')
            // PayFor tek çekimde '0' bekler; NestPay'in boş dizgisinden farklı
            ->and($fields['InstallmentCount'])->toBe('0')
            ->and($fields['Currency'])->toBe('949')
            ->and($fields['Hash'])->toMatch('#^[A-Za-z0-9+/]{27}=$#');
    });

    it('dönüş imzasını kendi alan sırasıyla doğrular', function () {
        // MerchantId + secretKey + OrderId + AuthCode + ProcReturnCode + 3DStatus + ResponseRnd + UserCode
        $payload = [
            'OrderId' => 'ORDER-1',
            'AuthCode' => 'AUTH-1',
            'ProcReturnCode' => '00',
            '3DStatus' => '1',
            'ResponseRnd' => 'RND-1',
        ];
        $payload['ResponseHash'] = base64_encode(hash(
            'sha1',
            'MERCHANT1'.'SECRETKEY'.'ORDER-1'.'AUTH-1'.'00'.'1'.'RND-1'.'apiuser',
            true,
        ));

        expect(CallsProtected::call($this->gateway, 'checkCallbackHash', $payload))->toBeTrue();
    });

    it('provizyonu RequestGuid ile tamamlar', function () {
        Http::fake(['bank.test/*' => Http::response(
            '<PayforResponse><ProcReturnCode>00</ProcReturnCode><TransId>TX-9</TransId></PayforResponse>'
        )]);

        $gateway = BankTestConfig::make(PayForGateway::class, ['verify_hash' => false]);

        $result = $gateway->verify(new VerifyPaymentData([
            'OrderId' => 'ORDER-1',
            '3DStatus' => '1',
            'SecureType' => '3DModel',
            'RequestGuid' => 'GUID-1',
        ]));

        expect($result->success)->toBeTrue()
            ->and($result->paymentId)->toBe('TX-9');

        Http::assertSent(fn ($request) => str_contains($request->body(), '<RequestGuid>GUID-1</RequestGuid>')
            && str_contains($request->body(), '<SecureType>3DModelPayment</SecureType>'));
    });

    it('3D Pay modelinde ikinci istek göndermez', function () {
        Http::fake();

        $gateway = BankTestConfig::make(PayForGateway::class, ['verify_hash' => false]);

        $result = $gateway->verify(new VerifyPaymentData([
            'OrderId' => 'ORDER-1',
            '3DStatus' => '1',
            'SecureType' => '3DPay',
            'ProcReturnCode' => '00',
        ]));

        expect($result->success)->toBeTrue();
        Http::assertNothingSent();
    });

    it('durum sorgusunda iptal edilmiş işlemi ayırt eder', function () {
        Http::fake(['bank.test/*' => Http::response(
            '<PayforResponse><ProcReturnCode>00</ProcReturnCode><TransId>TX-1</TransId>'
            .'<PurchAmount>199.90</PurchAmount><VoidDate>20260101</VoidDate></PayforResponse>'
        )]);

        $status = $this->gateway->status('ORDER-1');

        expect($status->isCancelled())->toBeTrue()
            ->and($status->amount?->minorUnits)->toBe(19990);
    });

    it('tanınmayan sipariş için found=false döner', function () {
        Http::fake(['bank.test/*' => Http::response(
            '<PayforResponse><ProcReturnCode>99</ProcReturnCode></PayforResponse>'
        )]);

        expect($this->gateway->status('YOK')->status)->toBe(StatusResponse::STATUS_UNKNOWN);
    });

    it('iade ve iptali farklı işlem tipiyle gönderir', function () {
        Http::fake(['bank.test/*' => Http::response(
            '<PayforResponse><ProcReturnCode>00</ProcReturnCode><TransId>RF-1</TransId></PayforResponse>'
        )]);

        $this->gateway->refund(new RefundPaymentData('ORDER-1', 49.90));
        Http::assertSent(fn ($request) => str_contains($request->body(), '<TxnType>Refund</TxnType>')
            && str_contains($request->body(), '<PurchAmount>49.9</PurchAmount>'));

        $this->gateway->cancel(new RefundPaymentData('ORDER-1'));
        Http::assertSent(fn ($request) => str_contains($request->body(), '<TxnType>Void</TxnType>'));
    });

    it('ön provizyon ve kapamayı doğru işlem tipiyle gönderir', function () {
        expect($this->gateway->preAuthorize(BankTestConfig::order())->formFields['TxnType'])->toBe('PreAuth');

        Http::fake(['bank.test/*' => Http::response(
            '<PayforResponse><ProcReturnCode>00</ProcReturnCode><TransId>CP-1</TransId></PayforResponse>'
        )]);

        $this->gateway->capture(new CapturePaymentData('ORDER-1', 99.90));

        Http::assertSent(fn ($request) => str_contains($request->body(), '<TxnType>PostAuth</TxnType>'));
    });

    it('Ziraat Katılım preset’inde imza doğrulaması kapalıdır', function () {
        // Banka tarafında hash tutarsız üretildiği için bilinçli tercih.
        expect(config('anadolupay.banks.ziraat-katilim.verify_hash'))->toBeFalse();
    });
});
