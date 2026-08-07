<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Voxyfy\AnadoluPay\AnadoluPay;
use Voxyfy\AnadoluPay\Contracts\ProvidesWebhookAcknowledgement;
use Voxyfy\AnadoluPay\Contracts\SupportsCancellation;
use Voxyfy\AnadoluPay\Contracts\SupportsPreAuthorization;
use Voxyfy\AnadoluPay\Contracts\SupportsStatusQuery;
use Voxyfy\AnadoluPay\DTO\CapturePaymentData;
use Voxyfy\AnadoluPay\DTO\CreatePaymentData;
use Voxyfy\AnadoluPay\DTO\RefundPaymentData;
use Voxyfy\AnadoluPay\DTO\StatusResponse;
use Voxyfy\AnadoluPay\Exceptions\PaymentFailedException;
use Voxyfy\AnadoluPay\Gateways\FakeGateway;
use Voxyfy\AnadoluPay\Gateways\Provider\PayTrGateway;
use Voxyfy\AnadoluPay\Tests\Support\BankTestConfig;

function fakeOrder(string $orderId = 'ORDER-1', float $amount = 199.90): CreatePaymentData
{
    return new CreatePaymentData(
        amount: $amount,
        currency: 'TRY',
        orderId: $orderId,
        customer: [],
    );
}

describe('sahte geçit', function () {
    beforeEach(function () {
        $this->gateway = new FakeGateway;
    });

    it('gerçek driver’larla aynı yetenek arayüzlerini uygular', function () {
        // Uygulama kodu instanceof kontrolü yapıyorsa fake ile de çalışmalı.
        expect($this->gateway)
            ->toBeInstanceOf(SupportsStatusQuery::class)
            ->toBeInstanceOf(SupportsCancellation::class)
            ->toBeInstanceOf(SupportsPreAuthorization::class);
    });

    it('varsayılan olarak her zaman başarılıdır', function () {
        // Rastgelelik testleri kırılgan yapar; sahte geçit öngörülebilir olmalı.
        for ($i = 0; $i < 20; $i++) {
            expect($this->gateway->createPayment(fakeOrder("ORDER-{$i}"))->success)->toBeTrue();
        }
    });

    it('başarı oranı sıfırlanınca hata verir', function () {
        config()->set('anadolupay.fake.success_rate', 0);

        $this->gateway->createPayment(fakeOrder());
    })->throws(PaymentFailedException::class);

    it('ödeme sonrası durumu paid döner', function () {
        $this->gateway->createPayment(fakeOrder('ORDER-1', 199.90));

        $status = $this->gateway->status('ORDER-1');

        expect($status->found)->toBeTrue()
            ->and($status->isPaid())->toBeTrue()
            ->and($status->amount?->minorUnits)->toBe(19990);
    });

    it('tanınmayan sipariş için found=false döner', function () {
        expect($this->gateway->status('YOK')->found)->toBeFalse();
    });

    it('iade sonrası durumu refunded olur', function () {
        $this->gateway->createPayment(fakeOrder('ORDER-1'));
        $this->gateway->refund(new RefundPaymentData('ORDER-1', 49.90));

        expect($this->gateway->status('ORDER-1')->isRefunded())->toBeTrue();
    });

    it('iptal sonrası durumu cancelled olur', function () {
        $this->gateway->createPayment(fakeOrder('ORDER-1'));
        $this->gateway->cancel(new RefundPaymentData('ORDER-1'));

        expect($this->gateway->status('ORDER-1')->isCancelled())->toBeTrue();
    });

    it('ödeme numarasıyla da iade edilebilir', function () {
        $paymentId = $this->gateway->createPayment(fakeOrder('ORDER-1'))->paymentId;

        $this->gateway->refund(new RefundPaymentData((string) $paymentId));

        expect($this->gateway->status('ORDER-1')->isRefunded())->toBeTrue();
    });

    it('ön provizyon tahsil edilmiş sayılmaz', function () {
        $this->gateway->preAuthorize(fakeOrder('ORDER-1'));

        $status = $this->gateway->status('ORDER-1');

        expect($status->status)->toBe(StatusResponse::STATUS_PRE_AUTHORIZED)
            ->and($status->isPaid())->toBeFalse();
    });

    it('provizyon kapama tahsilatı tamamlar', function () {
        $this->gateway->preAuthorize(fakeOrder('ORDER-1', 199.90));
        $this->gateway->capture(new CapturePaymentData('ORDER-1', 149.90));

        $status = $this->gateway->status('ORDER-1');

        expect($status->isPaid())->toBeTrue()
            ->and($status->amount?->minorUnits)->toBe(14990);
    });

    it('ön provizyon olmadan kapama yapılamaz', function () {
        $this->gateway->createPayment(fakeOrder('ORDER-1'));
        $this->gateway->capture(new CapturePaymentData('ORDER-1'));
    })->throws(PaymentFailedException::class);

    it('flush() bellekteki siparişleri temizler', function () {
        $this->gateway->createPayment(fakeOrder('ORDER-1'));
        $this->gateway->flush();

        expect($this->gateway->status('ORDER-1')->found)->toBeFalse();
    });

    it('driver olarak çözümlenebilir', function () {
        expect(app(AnadoluPay::class)->driver('fake'))->toBeInstanceOf(FakeGateway::class);
    });
});

describe('webhook onay yanıtı', function () {
    it('PayTR düz metin OK bekler', function () {
        $gateway = BankTestConfig::make(PayTrGateway::class);

        expect($gateway)->toBeInstanceOf(ProvidesWebhookAcknowledgement::class)
            ->and($gateway->webhookAcknowledgement(handled: true))->toBe('OK')
            ->and($gateway->webhookAcknowledgementContentType())->toBe('text/plain');
    });

    it('webhook rotası PayTR’ye OK döner', function () {
        config()->set('anadolupay.banks.paytr', [
            'gateway' => PayTrGateway::class,
            'merchant_id' => 'M1',
            'secret_key' => 'KEY',
            'password' => 'SALT',
            'verify_hash' => false,
            'endpoints' => ['payment_api' => 'https://paytr.test'],
        ]);

        Http::fake();

        $response = $this->post('/anadolupay/webhook/paytr', [
            'merchant_oid' => 'ORDER-1',
            'status' => 'success',
            'total_amount' => '19990',
        ]);

        // JSON dönersek PayTR bildirimi saatlerce yeniden gönderir.
        expect($response->getContent())->toBe('OK')
            ->and($response->headers->get('Content-Type'))->toContain('text/plain');
    });

    it('özel yanıt istemeyen driver JSON döner', function () {
        $response = $this->post('/anadolupay/webhook/fake', ['payment_id' => 'fake_pay_1']);

        $response->assertOk()->assertJson(['success' => true]);
    });
});
