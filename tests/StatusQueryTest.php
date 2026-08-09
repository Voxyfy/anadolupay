<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Voxyfy\AnadoluPay\Contracts\SupportsCancellation;
use Voxyfy\AnadoluPay\Contracts\SupportsStatusQuery;
use Voxyfy\AnadoluPay\DTO\RefundPaymentData;
use Voxyfy\AnadoluPay\DTO\StatusResponse;
use Voxyfy\AnadoluPay\Gateways\Bank\AssecoGateway;
use Voxyfy\AnadoluPay\Gateways\Bank\GarantiGateway;
use Voxyfy\AnadoluPay\Gateways\Bank\KuveytPosGateway;
use Voxyfy\AnadoluPay\Gateways\Provider\AkbankPosGateway;
use Voxyfy\AnadoluPay\Gateways\Provider\PayTrGateway;
use Voxyfy\AnadoluPay\Support\Bank\OrderStatus;
use Voxyfy\AnadoluPay\Tests\Support\BankTestConfig;

describe('yetenek sözleşmeleri', function () {
    it('durum sorgusu ve iptal yeteneği instanceof ile anlaşılır', function () {
        expect(BankTestConfig::make(AssecoGateway::class))
            ->toBeInstanceOf(SupportsStatusQuery::class)
            ->toBeInstanceOf(SupportsCancellation::class);
    });

    it('PayTR iptal desteklemez', function () {
        // Sağlayıcı sınırı: PayTR void sunmaz, tek seçenek iadedir.
        expect(BankTestConfig::make(PayTrGateway::class))
            ->toBeInstanceOf(SupportsStatusQuery::class)
            ->not->toBeInstanceOf(SupportsCancellation::class);
    });

    it('Akbank POS tekil durum sorgusu desteklemez', function () {
        // Akbank'ın yeni API'si yalnızca tarih aralıklı işlem geçmişi sunar.
        expect(BankTestConfig::make(AkbankPosGateway::class))
            ->toBeInstanceOf(SupportsCancellation::class)
            ->not->toBeInstanceOf(SupportsStatusQuery::class);
    });
});

describe('durum kodu sözlüğü', function () {
    it('banka kodlarını tek sözlüğe indirger', function () {
        expect(OrderStatus::map('A', OrderStatus::NESTPAY))->toBe(StatusResponse::STATUS_PAID)
            ->and(OrderStatus::map('V', OrderStatus::NESTPAY))->toBe(StatusResponse::STATUS_CANCELLED)
            ->and(OrderStatus::map('PN', OrderStatus::NESTPAY))->toBe(StatusResponse::STATUS_PENDING)
            ->and(OrderStatus::map('1', OrderStatus::BOA))->toBe(StatusResponse::STATUS_PAID)
            ->and(OrderStatus::map('6', OrderStatus::BOA))->toBe(StatusResponse::STATUS_CANCELLED)
            ->and(OrderStatus::map('SUCCESS', OrderStatus::PARAM))->toBe(StatusResponse::STATUS_PAID)
            ->and(OrderStatus::map('Başarılı', OrderStatus::AKBANK))->toBe(StatusResponse::STATUS_PAID);
    });

    it('tanımadığı kodu başarılı saymaz', function () {
        expect(OrderStatus::map('ZZZ', OrderStatus::NESTPAY))->toBe(StatusResponse::STATUS_UNKNOWN)
            ->and(OrderStatus::map(null, OrderStatus::NESTPAY))->toBe(StatusResponse::STATUS_UNKNOWN)
            ->and(OrderStatus::map('', OrderStatus::NESTPAY))->toBe(StatusResponse::STATUS_UNKNOWN);
    });
});

describe('NestPay durum sorgusu', function () {
    it('ödenmiş siparişi doğru eşler', function () {
        Http::fake(['bank.test/*' => Http::response(
            '<CC5Response><ProcReturnCode>00</ProcReturnCode><TransId>TX-1</TransId>'
            .'<Extra><ORDERSTATUS>A</ORDERSTATUS><ORIG_TRANS_AMT>199.90</ORIG_TRANS_AMT>'
            .'<TRXDATE>20260101 12:00:00</TRXDATE></Extra></CC5Response>'
        )]);

        $status = BankTestConfig::make(AssecoGateway::class)->status('ORDER-1');

        expect($status->found)->toBeTrue()
            ->and($status->isPaid())->toBeTrue()
            ->and($status->orderId)->toBe('ORDER-1')
            ->and($status->paymentId)->toBe('TX-1')
            ->and($status->amount?->minorUnits)->toBe(19990)
            ->and($status->transactionTime)->toBe('20260101 12:00:00')
            ->and($status->isSettled())->toBeTrue();
    });

    it('iptal edilmiş siparişi doğru eşler', function () {
        Http::fake(['bank.test/*' => Http::response(
            '<CC5Response><ProcReturnCode>00</ProcReturnCode>'
            .'<Extra><ORDERSTATUS>V</ORDERSTATUS></Extra></CC5Response>'
        )]);

        $status = BankTestConfig::make(AssecoGateway::class)->status('ORDER-1');

        expect($status->isCancelled())->toBeTrue()
            ->and($status->isPaid())->toBeFalse();
    });

    it('tanınmayan siparişi found=false olarak döndürür', function () {
        Http::fake(['bank.test/*' => Http::response(
            '<CC5Response><ProcReturnCode>00</ProcReturnCode><Extra></Extra></CC5Response>'
        )]);

        $status = BankTestConfig::make(AssecoGateway::class)->status('YOK');

        expect($status->found)->toBeFalse()
            ->and($status->status)->toBe(StatusResponse::STATUS_UNKNOWN)
            ->and($status->isPaid())->toBeFalse();
    });

    it('doğru istek gövdesini gönderir', function () {
        Http::fake(['bank.test/*' => Http::response(
            '<CC5Response><ProcReturnCode>00</ProcReturnCode><Extra><ORDERSTATUS>A</ORDERSTATUS></Extra></CC5Response>'
        )]);

        BankTestConfig::make(AssecoGateway::class)->status('ORDER-1');

        Http::assertSent(fn ($request) => str_contains($request->body(), '<ORDERSTATUS>QUERY</ORDERSTATUS>')
            && str_contains($request->body(), '<OrderId>ORDER-1</OrderId>'));
    });
});

describe('Garanti durum sorgusu', function () {
    it('ön provizyonu tahsil edilmiş ödemeden ayırır', function () {
        Http::fake(['bank.test/*' => Http::response(
            '<GVPSResponse><Transaction><Response><Code>00</Code></Response></Transaction>'
            .'<Order><OrderID>ORDER-1</OrderID><OrderInqResult>'
            .'<Status>WAITINGPOSTAUTH</Status><PreAuthAmount>19990</PreAuthAmount>'
            .'<AuthAmount>0</AuthAmount><RetrefNum>REF-1</RetrefNum><InstallmentCnt>3</InstallmentCnt>'
            .'<CardNumberMasked>415565******6111</CardNumberMasked>'
            .'</OrderInqResult></Order></GVPSResponse>'
        )]);

        $status = BankTestConfig::make(GarantiGateway::class)->status('ORDER-1');

        expect($status->status)->toBe(StatusResponse::STATUS_PRE_AUTHORIZED)
            // Ön provizyon "ödendi" değildir: tutar bloke, tahsil edilmemiş.
            ->and($status->isPaid())->toBeFalse()
            ->and($status->amount?->minorUnits)->toBe(19990)
            ->and($status->paymentId)->toBe('REF-1')
            ->and($status->installment)->toBe(3)
            ->and($status->maskedCardNumber)->toBe('415565******6111');
    });

    it('sorguyu imzalar', function () {
        Http::fake(['bank.test/*' => Http::response(
            '<GVPSResponse><Transaction><Response><Code>99</Code></Response></Transaction></GVPSResponse>'
        )]);

        BankTestConfig::make(GarantiGateway::class)->status('ORDER-1');

        Http::assertSent(fn ($request) => str_contains($request->body(), '<Type>orderinq</Type>')
            && preg_match('#<HashData>[0-9A-F]{128}</HashData>#', $request->body()) === 1);
    });
});

describe('PayTR durum sorgusu', function () {
    it('iade edilmiş ödemeyi ayırt eder', function () {
        Http::fake(['paytr.test/*' => Http::response([
            'status' => 'success',
            'payment_status' => 'success',
            'total_amount' => 19990,
            'returned_amount' => 4990,
        ])]);

        $status = BankTestConfig::make(PayTrGateway::class, [
            'endpoints' => ['payment_api' => 'https://paytr.test'],
        ])->status('ORDER-1');

        expect($status->isRefunded())->toBeTrue()
            ->and($status->amount?->minorUnits)->toBe(19990)
            ->and($status->refundedAmount?->minorUnits)->toBe(4990);
    });
});

describe('Kuveyt Türk iptal', function () {
    it('iptali ayrı sorgu servisine gönderir', function () {
        Http::fake(['bank.test/query/*' => Http::response(['ResponseCode' => '00', 'OrderId' => '999'])]);

        $gateway = BankTestConfig::make(KuveytPosGateway::class, [
            'endpoints' => ['query_api' => 'https://bank.test/query'],
        ]);

        $response = $gateway->cancel(new RefundPaymentData('ORDER-1', metadata: [
            'remote_order_id' => '999',
            'ref_ret_num' => 'RRN-1',
            'auth_code' => 'AUTH-1',
        ]));

        expect($response->success)->toBeTrue();

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            return $body['request']['VPosMessage']['TransactionType'] === 'SaleReversal'
                && $body['request']['RRN'] === 'RRN-1'
                && $body['request']['ProvisionNumber'] === 'AUTH-1';
        });
    });
    /*
     * Gerçek NestPay terminaline sorulduğunda ortaya çıktı: sorgu yanıtında
     * `ORDERSTATUS` tek harflik bir durum kodu değil, birleşik bir alandır.
     * Driver bunu durum kodu sandığı için var olan sipariş `unknown`, var
     * olmayan sipariş de `found: true` görünüyordu.
     */
    it('birleşik ORDERSTATUS alanını çözümleyip TRANS_STAT’i kullanır', function () {
        Http::fake([
            'bank.test/*' => Http::response(
                '<CC5Response><ProcReturnCode>00</ProcReturnCode><TransId>TRX-1</TransId><Extra>'
                // Birleşik alandaki tutarlar kuruş cinsindendir.
                .'<ORDERSTATUS>ORD_ID:ORDER-1 CHARGE_TYPE_CD:S ORIG_TRANS_AMT:19990 CAPTURE_AMT:19990 '
                .'TRANS_STAT:A AUTH_DTTM:2026-08-09 19:12:07 CAPTURE_DTTM:2026-08-09 19:12:07 AUTH_CODE:123456</ORDERSTATUS>'
                .'</Extra></CC5Response>'
            ),
        ]);

        $status = BankTestConfig::make(AssecoGateway::class)->status('ORDER-1');

        expect($status->found)->toBeTrue()
            ->and($status->status)->toBe(StatusResponse::STATUS_PAID)
            ->and($status->amount?->minorUnits)->toBe(19990)
            // Tarih boşluk içeriyor; ayrıştırma bunu kesmemeli.
            ->and($status->transactionTime)->toBe('2026-08-09 19:12:07');
    });

    it('sipariş bulunamadığında boş şablonu durum sanmaz', function () {
        // Bankanın var olmayan sipariş için gerçekte döndürdüğü yanıt.
        Http::fake([
            'bank.test/*' => Http::response(
                '<CC5Response><Response>Declined</Response><ProcReturnCode>99</ProcReturnCode>'
                .'<ErrMsg>Kayıt bulunamadı YOK-1</ErrMsg><Extra>'
                .'<ORDERSTATUS>ORD_ID: CHARGE_TYPE_CD: ORIG_TRANS_AMT: CAPTURE_AMT: TRANS_STAT: '
                .'AUTH_DTTM: CAPTURE_DTTM: AUTH_CODE:</ORDERSTATUS><NUMCODE>99</NUMCODE>'
                .'</Extra></CC5Response>'
            ),
        ]);

        $status = BankTestConfig::make(AssecoGateway::class)->status('YOK-1');

        expect($status->found)->toBeFalse()
            ->and($status->status)->toBe(StatusResponse::STATUS_UNKNOWN);
    });
});
