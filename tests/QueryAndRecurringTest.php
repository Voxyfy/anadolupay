<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Voxyfy\AnadoluPay\Contracts\SupportsBinQuery;
use Voxyfy\AnadoluPay\Contracts\SupportsInstallmentQuery;
use Voxyfy\AnadoluPay\Contracts\SupportsOrderHistory;
use Voxyfy\AnadoluPay\Contracts\SupportsRecurringPayments;
use Voxyfy\AnadoluPay\DTO\CreatePaymentData;
use Voxyfy\AnadoluPay\DTO\RecurringPlan;
use Voxyfy\AnadoluPay\Exceptions\PaymentFailedException;
use Voxyfy\AnadoluPay\Gateways\Bank\AssecoGateway;
use Voxyfy\AnadoluPay\Gateways\Bank\GarantiGateway;
use Voxyfy\AnadoluPay\Gateways\Bank\PayFlexGateway;
use Voxyfy\AnadoluPay\Gateways\Bank\PosNetGateway;
use Voxyfy\AnadoluPay\Gateways\Provider\PayTrGateway;
use Voxyfy\AnadoluPay\Support\Money;
use Voxyfy\AnadoluPay\Tests\Support\BankTestConfig;

describe('BIN sorgusu', function () {
    it('Garanti kart bilgisini eşler', function () {
        Http::fake(['bank.test/*' => Http::response(
            '<GVPSResponse><Transaction><Response><Code>00</Code></Response>'
            .'<BINList><BINInfo><BankName>Garanti BBVA</BankName>'
            .'<CardBrand>Visa</CardBrand><CardType>C</CardType>'
            .'</BINInfo></BINList></Transaction></GVPSResponse>'
        )]);

        $bin = BankTestConfig::make(GarantiGateway::class)->binLookup('415565');

        expect($bin->found)->toBeTrue()
            ->and($bin->bankName)->toBe('Garanti BBVA')
            ->and($bin->brand)->toBe('visa')
            ->and($bin->isCredit())->toBeTrue()
            ->and($bin->isDebit())->toBeFalse();

        Http::assertSent(fn ($request) => str_contains($request->body(), '<Type>bininq</Type>')
            && str_contains($request->body(), '<BINNum>415565</BINNum>'));
    });

    it('tanınmayan BIN için found=false döner', function () {
        Http::fake(['bank.test/*' => Http::response(
            '<GVPSResponse><Transaction><Response><Code>99</Code></Response></Transaction></GVPSResponse>'
        )]);

        expect(BankTestConfig::make(GarantiGateway::class)->binLookup('000000')->found)->toBeFalse();
    });

    it('BIN sorgusu desteklemeyen driver arayüzü uygulamaz', function () {
        expect(BankTestConfig::make(PosNetGateway::class))->not->toBeInstanceOf(SupportsBinQuery::class);
    });
});

describe('taksit sorgusu', function () {
    it('PayTR oranlarından taksit seçeneklerini üretir', function () {
        Http::fake(['paytr.test/*' => Http::response(['oranlar' => ['1' => 0, '3' => 4.5, '6' => 9.0]])]);

        $options = BankTestConfig::make(PayTrGateway::class, [
            'endpoints' => ['payment_api' => 'https://paytr.test'],
        ])->installmentOptions(Money::fromMinorUnits(10000));

        expect($options)->toHaveCount(3)
            ->and($options[0]->count)->toBe(1)
            ->and($options[0]->isSingle())->toBeTrue()
            ->and($options[0]->totalPrice?->minorUnits)->toBe(10000)
            // %4,5 komisyonla 100 TL → 104,50 TL
            ->and($options[1]->totalPrice?->minorUnits)->toBe(10450)
            ->and($options[1]->monthlyPrice?->minorUnits)->toBe(3483)
            ->and($options[1]->commissionRate)->toBe(4.5);
    });

    it('taksit sorgusu desteklemeyen driver arayüzü uygulamaz', function () {
        expect(BankTestConfig::make(AssecoGateway::class))->not->toBeInstanceOf(SupportsInstallmentQuery::class);
    });
});

describe('sipariş hareketleri', function () {
    it('NestPay hareket dökümünü ister', function () {
        Http::fake(['bank.test/*' => Http::response('<CC5Response><ProcReturnCode>00</ProcReturnCode></CC5Response>')]);

        BankTestConfig::make(AssecoGateway::class)->orderHistory('ORDER-1');

        Http::assertSent(fn ($request) => str_contains($request->body(), '<ORDERHISTORY>QUERY</ORDERHISTORY>'));
    });

    it('destekleyen driver’lar arayüzü uygular', function () {
        expect(BankTestConfig::make(AssecoGateway::class))->toBeInstanceOf(SupportsOrderHistory::class)
            ->and(BankTestConfig::make(GarantiGateway::class))->toBeInstanceOf(SupportsOrderHistory::class)
            ->and(BankTestConfig::make(PosNetGateway::class))->not->toBeInstanceOf(SupportsOrderHistory::class);
    });
});

describe('tekrarlayan ödeme', function () {
    it('geçersiz frekansı reddeder', function () {
        new RecurringPlan(1, 'fortnight', 12);
    })->throws(PaymentFailedException::class);

    it('sıfır veya negatif değerleri reddeder', function () {
        new RecurringPlan(0, RecurringPlan::FREQUENCY_MONTH, 12);
    })->throws(PaymentFailedException::class);

    it('bankanın desteklemediği frekansta hata verir', function () {
        // Garanti yıllık tekrarı desteklemez.
        $plan = new RecurringPlan(1, RecurringPlan::FREQUENCY_YEAR, 3);

        $plan->frequencyCode(['month' => 'M']);
    })->throws(PaymentFailedException::class);

    it('driver’lar desteklenen frekansları bildirir', function () {
        expect(BankTestConfig::make(GarantiGateway::class)->supportedRecurringFrequencies())
            ->toContain(RecurringPlan::FREQUENCY_MONTH)
            // Garanti yıllık tekrar sunmaz.
            ->not->toContain(RecurringPlan::FREQUENCY_YEAR)
            ->and(BankTestConfig::make(AssecoGateway::class)->supportedRecurringFrequencies())
            ->toContain(RecurringPlan::FREQUENCY_YEAR)
            ->and(BankTestConfig::make(PayFlexGateway::class)->supportedRecurringFrequencies())
            // PayFlex haftalık tekrar sunmaz.
            ->not->toContain(RecurringPlan::FREQUENCY_WEEK);
    });

    it('NestPay planı provizyon isteğine ekler', function () {
        Http::fake(['bank.test/*' => Http::response('<CC5Response><ProcReturnCode>00</ProcReturnCode></CC5Response>')]);

        $order = new CreatePaymentData(
            amount: 49.90,
            currency: 'TRY',
            orderId: 'ABONE-1',
            customer: [],
            card: BankTestConfig::card(),
            paymentModel: CreatePaymentData::MODEL_NON_SECURE,
            metadata: ['recurring' => new RecurringPlan(1, RecurringPlan::FREQUENCY_MONTH, 12)],
        );

        BankTestConfig::make(AssecoGateway::class)->createPayment($order);

        Http::assertSent(fn ($request) => str_contains($request->body(), '<PbOrder>')
            && str_contains($request->body(), '<OrderFrequencyCycle>M</OrderFrequencyCycle>')
            && str_contains($request->body(), '<TotalNumberPayments>12</TotalNumberPayments>'));
    });

    it('plan verilmeyince istek değişmez', function () {
        Http::fake(['bank.test/*' => Http::response('<CC5Response><ProcReturnCode>00</ProcReturnCode></CC5Response>')]);

        $order = new CreatePaymentData(
            amount: 49.90,
            currency: 'TRY',
            orderId: 'ORDER-1',
            customer: [],
            card: BankTestConfig::card(),
            paymentModel: CreatePaymentData::MODEL_NON_SECURE,
        );

        BankTestConfig::make(AssecoGateway::class)->createPayment($order);

        Http::assertSent(fn ($request) => ! str_contains($request->body(), '<PbOrder>'));
    });

    it('desteklemeyen driver arayüzü uygulamaz', function () {
        expect(BankTestConfig::make(PosNetGateway::class))->not->toBeInstanceOf(SupportsRecurringPayments::class);
    });
});
