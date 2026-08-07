<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Voxyfy\AnadoluPay\DTO\CreatePaymentData;
use Voxyfy\AnadoluPay\DTO\RefundPaymentData;
use Voxyfy\AnadoluPay\DTO\VerifyPaymentData;
use Voxyfy\AnadoluPay\Events\PaymentFailed;
use Voxyfy\AnadoluPay\Events\PaymentInitiated;
use Voxyfy\AnadoluPay\Events\PaymentVerified;
use Voxyfy\AnadoluPay\Events\RefundIssued;
use Voxyfy\AnadoluPay\Exceptions\DuplicatePaymentException;
use Voxyfy\AnadoluPay\Exceptions\GatewayHttpException;
use Voxyfy\AnadoluPay\Exceptions\GatewayUnreachableException;
use Voxyfy\AnadoluPay\Exceptions\PaymentFailedException;
use Voxyfy\AnadoluPay\Exceptions\TransportException;
use Voxyfy\AnadoluPay\Gateways\Bank\AssecoGateway;
use Voxyfy\AnadoluPay\Support\Bank\BankHttpClient;
use Voxyfy\AnadoluPay\Support\IdempotencyGuard;
use Voxyfy\AnadoluPay\Tests\Support\BankTestConfig;

describe('hata sınıflandırması', function () {
    it('bağlantı hatasını tekrar denenebilir olarak işaretler', function () {
        Http::fake(fn () => throw new ConnectionException('Could not resolve host'));

        try {
            (new BankHttpClient(bank: 'akbank'))->postXml('https://bank.test/api', ['a' => 'b'], 'r');
        } catch (GatewayUnreachableException $exception) {
            expect($exception->safeToRetry)->toBeTrue()
                ->and($exception->context['reason'])->toBe('connection_failed');

            return;
        }

        $this->fail('GatewayUnreachableException bekleniyordu.');
    });

    it('zaman aşımını tekrar denenemez olarak işaretler', function () {
        // Zaman aşımında istek bankaya ulaşmış ve işlenmiş olabilir;
        // körlemesine tekrar denemek çift çekim üretir.
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

        try {
            (new BankHttpClient(bank: 'garanti'))->postXml('https://bank.test/api', ['a' => 'b'], 'r');
        } catch (GatewayUnreachableException $exception) {
            expect($exception->safeToRetry)->toBeFalse()
                ->and($exception->context['reason'])->toBe('timeout')
                ->and($exception->getMessage())->toContain('belirsizdir');

            return;
        }

        $this->fail('GatewayUnreachableException bekleniyordu.');
    });

    it('beklenmeyen HTTP durumunu iş kuralı reddinden ayırır', function () {
        Http::fake(['bank.test/*' => Http::response('sunucu hatası', 502)]);

        try {
            (new BankHttpClient(bank: 'akbank'))->postXml('https://bank.test/api', ['a' => 'b'], 'r');
        } catch (GatewayHttpException $exception) {
            expect($exception)->toBeInstanceOf(TransportException::class)
                // Taşıma hatası bir ödeme reddi değildir.
                ->and($exception)->not->toBeInstanceOf(PaymentFailedException::class)
                ->and($exception->safeToRetry)->toBeFalse()
                ->and($exception->getCode())->toBe(502);

            return;
        }

        $this->fail('GatewayHttpException bekleniyordu.');
    });

    it('HTTP hatasının gövdesini maskeleyerek raporlar', function () {
        Http::fake(['bank.test/*' => Http::response('kart: 4155650100416111', 500)]);

        try {
            (new BankHttpClient(bank: 'akbank'))->postXml('https://bank.test/api', ['a' => 'b'], 'r');
        } catch (GatewayHttpException $exception) {
            expect($exception->context['body'])
                ->not->toContain('4155650100416111')
                ->toContain('415565******6111');
        }
    });
});

describe('yeniden deneme', function () {
    it('bağlantı hatasında tekrar dener', function () {
        $attempts = 0;

        Http::fake(function () use (&$attempts) {
            $attempts++;

            if ($attempts < 3) {
                throw new ConnectionException('Connection refused');
            }

            return Http::response('<r><ProcReturnCode>00</ProcReturnCode></r>');
        });

        $client = new BankHttpClient(bank: 'akbank', retryTimes: 3, retrySleepMs: 0);

        expect($client->postXml('https://bank.test/api', ['a' => 'b'], 'r'))
            ->toBe(['ProcReturnCode' => '00'])
            ->and($attempts)->toBe(3);
    });

    it('zaman aşımında tekrar denemez', function () {
        $attempts = 0;

        Http::fake(function () use (&$attempts) {
            $attempts++;
            throw new ConnectionException('Operation timed out');
        });

        $client = new BankHttpClient(bank: 'akbank', retryTimes: 5, retrySleepMs: 0);

        try {
            $client->postXml('https://bank.test/api', ['a' => 'b'], 'r');
        } catch (GatewayUnreachableException) {
            // Beklenen.
        }

        expect($attempts)->toBe(1);
    });

    it('HTTP hatasında tekrar denemez', function () {
        $attempts = 0;

        Http::fake(function () use (&$attempts) {
            $attempts++;

            return Http::response('hata', 500);
        });

        $client = new BankHttpClient(bank: 'akbank', retryTimes: 5, retrySleepMs: 0);

        try {
            $client->postXml('https://bank.test/api', ['a' => 'b'], 'r');
        } catch (GatewayHttpException) {
            // Beklenen: istek bankaya ulaştı, tekrar denemek güvenli değil.
        }

        expect($attempts)->toBe(1);
    });

    it('varsayılan olarak kapalıdır', function () {
        $attempts = 0;

        Http::fake(function () use (&$attempts) {
            $attempts++;
            throw new ConnectionException('Connection refused');
        });

        try {
            (new BankHttpClient(bank: 'akbank'))->postXml('https://bank.test/api', ['a' => 'b'], 'r');
        } catch (GatewayUnreachableException) {
            // Beklenen.
        }

        expect($attempts)->toBe(1);
    });
});

describe('event’ler', function () {
    it('ödeme başlatıldığında PaymentInitiated yayınlar', function () {
        Event::fake();

        BankTestConfig::make(AssecoGateway::class)->createPayment(BankTestConfig::order(amount: 199.90));

        Event::assertDispatched(PaymentInitiated::class, function (PaymentInitiated $event) {
            return $event->driver === 'test-bank'
                && $event->orderId === 'ORDER-1'
                && $event->amount->minorUnits === 19990
                && $event->installment === 1;
        });
    });

    it('event’ler kart verisi taşımaz', function () {
        Event::fake();

        BankTestConfig::make(AssecoGateway::class)->createPayment(BankTestConfig::order());

        Event::assertDispatched(PaymentInitiated::class, function (PaymentInitiated $event) {
            return ! str_contains(json_encode(get_object_vars($event)) ?: '', '4155650100416111');
        });
    });

    it('doğrulama sonrası PaymentVerified yayınlar', function () {
        Event::fake();
        Http::fake();

        BankTestConfig::make(AssecoGateway::class, ['verify_hash' => false])
            ->verify(new VerifyPaymentData([
                'oid' => 'ORDER-1',
                'mdStatus' => '0',
                'storetype' => '3d',
            ]));

        Event::assertDispatched(PaymentVerified::class, fn (PaymentVerified $event) => $event->orderId === 'ORDER-1'
            && $event->success === false
            && $event->status === 'failed');
    });

    it('iade sonrası RefundIssued yayınlar', function () {
        Event::fake();
        Http::fake(['bank.test/*' => Http::response(
            '<r><ProcReturnCode>00</ProcReturnCode><TransId>RF-1</TransId></r>'
        )]);

        BankTestConfig::make(AssecoGateway::class)->refund(new RefundPaymentData('ORDER-1', 49.90));

        Event::assertDispatched(RefundIssued::class, fn (RefundIssued $event) => $event->paymentId === 'ORDER-1'
            && $event->amount?->minorUnits === 4990
            && $event->refundId === 'RF-1'
            && $event->success);
    });

    it('hata durumunda PaymentFailed yayınlar ve istisnayı yutmaz', function () {
        Event::fake();
        Http::fake(['bank.test/*' => Http::response('hata', 500)]);

        try {
            BankTestConfig::make(AssecoGateway::class)->refund(new RefundPaymentData('ORDER-1'));
            $this->fail('İstisna bekleniyordu.');
        } catch (GatewayHttpException) {
            // Beklenen: event yayınlanır ama istisna yukarı çıkmaya devam eder.
        }

        Event::assertDispatched(PaymentFailed::class, fn (PaymentFailed $event) => $event->orderId === 'ORDER-1'
            && $event->exception instanceof GatewayHttpException);
    });

    it('yapılandırmadan kapatılabilir', function () {
        config()->set('anadolupay.events.enabled', false);
        Event::fake();

        BankTestConfig::make(AssecoGateway::class)->createPayment(BankTestConfig::order());

        Event::assertNotDispatched(PaymentInitiated::class);
    });
});

describe('mükerrer ödeme koruması', function () {
    beforeEach(function () {
        Cache::flush();
        config()->set('anadolupay.idempotency', ['enabled' => true, 'ttl' => 30]);
    });

    it('aynı sipariş için ikinci ödemeyi engeller', function () {
        $guard = IdempotencyGuard::fromConfig();

        $guard->acquire('akbank', 'ORDER-1');
        $guard->acquire('akbank', 'ORDER-1');
    })->throws(DuplicatePaymentException::class);

    it('farklı siparişleri engellemez', function () {
        $guard = IdempotencyGuard::fromConfig();

        $guard->acquire('akbank', 'ORDER-1');
        $guard->acquire('akbank', 'ORDER-2');
        // Aynı sipariş numarası farklı bankada ayrı sayılır.
        $guard->acquire('garanti', 'ORDER-1');
    })->throwsNoExceptions();

    it('kilit bırakıldıktan sonra tekrar denemeye izin verir', function () {
        $guard = IdempotencyGuard::fromConfig();

        $guard->acquire('akbank', 'ORDER-1');
        $guard->release('akbank', 'ORDER-1');
        $guard->acquire('akbank', 'ORDER-1');
    })->throwsNoExceptions();

    it('kapalıyken hiçbir şey yapmaz', function () {
        config()->set('anadolupay.idempotency.enabled', false);
        $guard = IdempotencyGuard::fromConfig();

        $guard->acquire('akbank', 'ORDER-1');
        $guard->acquire('akbank', 'ORDER-1');
    })->throwsNoExceptions();

    it('driver üzerinden çift ödemeyi engeller', function () {
        $gateway = BankTestConfig::make(AssecoGateway::class);

        $gateway->createPayment(BankTestConfig::order());
        $gateway->createPayment(BankTestConfig::order());
    })->throws(DuplicatePaymentException::class);

    it('başarısız ödeme kilidi serbest bırakır', function () {
        $gateway = BankTestConfig::make(AssecoGateway::class);

        // Kart bilgisi olmadan başlatma hata verir; kilit kalmamalıdır.
        try {
            $gateway->createPayment(new CreatePaymentData(
                amount: 1.99,
                currency: 'TRY',
                orderId: 'ORDER-1',
                customer: [],
                successUrl: 'https://shop.test/ok',
                failUrl: 'https://shop.test/fail',
            ));
        } catch (PaymentFailedException) {
            // Beklenen.
        }

        // İkinci, geçerli deneme kilitlenmemeli.
        expect($gateway->createPayment(BankTestConfig::order())->success)->toBeTrue();
    });
});
