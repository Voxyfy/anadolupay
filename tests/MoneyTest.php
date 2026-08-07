<?php

declare(strict_types=1);

use Voxyfy\AnadoluPay\DTO\CreatePaymentData;
use Voxyfy\AnadoluPay\DTO\RefundPaymentData;
use Voxyfy\AnadoluPay\Exceptions\PaymentFailedException;
use Voxyfy\AnadoluPay\Gateways\Bank\AssecoGateway;
use Voxyfy\AnadoluPay\Gateways\Bank\GarantiGateway;
use Voxyfy\AnadoluPay\Support\Money;
use Voxyfy\AnadoluPay\Tests\Support\BankTestConfig;

describe('Money', function () {
    it('kuruş cinsinden tam sayıyı kesinlik kaybı olmadan taşır', function () {
        $money = Money::fromMinorUnits(19990);

        expect($money->minorUnits)->toBe(19990)
            ->and($money->toMinorUnitsString())->toBe('19990')
            ->and($money->toDecimalString())->toBe('199.90')
            ->and($money->toNaturalString())->toBe('199.9');
    });

    it('ondalıklı tutarı kuruşa çevirir', function () {
        expect(Money::fromDecimal(199.90)->minorUnits)->toBe(19990)
            ->and(Money::fromDecimal(1.99)->minorUnits)->toBe(199)
            ->and(Money::fromDecimal(100)->minorUnits)->toBe(10000)
            ->and(Money::fromDecimal('49,90')->minorUnits)->toBe(4990);
    });

    it('float ikili gösterim artıklarını kuruşa taşımaz', function () {
        // 0.1 + 0.2 float’ta 0.30000000000000004 eder; naif bir
        // round($amount * 100) bu tür değerlerde bir kuruş kayabilir.
        expect(Money::fromDecimal(0.1 + 0.2)->minorUnits)->toBe(30)
            ->and(Money::fromDecimal(1.005)->minorUnits)->toBe(101)
            ->and(Money::fromDecimal(19.99 * 3)->minorUnits)->toBe(5997);
    });

    it('üç banka biçimini de doğru üretir', function () {
        $money = Money::fromMinorUnits(10000);

        expect($money->toMinorUnitsString())->toBe('10000')  // Garanti, PosNet
            ->and($money->toDecimalString())->toBe('100.00') // Akbank POS, PayFlex
            ->and($money->toNaturalString())->toBe('100');   // NestPay, PayFor
    });

    it('para birimini ISO 4217 sayısal koda çevirir', function () {
        expect(Money::fromMinorUnits(100, 'try')->currency)->toBe('TRY')
            ->and(Money::fromMinorUnits(100, 'TRY')->numericCurrency())->toBe('949');
    });

    it('sayı olmayan tutarı reddeder', function () {
        Money::fromDecimal('abc');
    })->throws(PaymentFailedException::class);

    it('zaten Money olan değeri olduğu gibi döndürür', function () {
        $money = Money::fromMinorUnits(1);

        expect(Money::of($money))->toBe($money);
    });
});

describe('DTO tutar entegrasyonu', function () {
    it('float ve Money aynı sonucu verir', function () {
        $withFloat = new CreatePaymentData(199.90, 'TRY', 'ORDER-1', []);
        $withMoney = new CreatePaymentData(Money::fromMinorUnits(19990), 'TRY', 'ORDER-1', []);

        expect($withFloat->money()->minorUnits)->toBe($withMoney->money()->minorUnits);
    });

    it('iade tutarı verilmediğinde null döner', function () {
        expect((new RefundPaymentData('ORDER-1'))->money())->toBeNull()
            ->and((new RefundPaymentData('ORDER-1', 49.90))->money()?->minorUnits)->toBe(4990);
    });
});

describe('driver tutar biçimleri', function () {
    it('Garanti tutarı kuruş olarak gönderir', function () {
        $fields = BankTestConfig::make(GarantiGateway::class)
            ->createPayment(BankTestConfig::order(amount: 199.90))
            ->formFields;

        expect($fields['txnamount'])->toBe('19990');
    });

    it('NestPay tutarı doğal gösterimle gönderir', function () {
        $gateway = BankTestConfig::make(AssecoGateway::class);

        expect($gateway->createPayment(BankTestConfig::order(amount: 199.90))->formFields['amount'])
            ->toBe('199.9')
            ->and($gateway->createPayment(BankTestConfig::order(amount: 100.00))->formFields['amount'])
            ->toBe('100')
            ->and($gateway->createPayment(BankTestConfig::order(amount: 1.99))->formFields['amount'])
            ->toBe('1.99');
    });

    it('Money ile verilen tutar float ile aynı dizgiyi üretir', function () {
        $gateway = BankTestConfig::make(GarantiGateway::class);

        $fromFloat = $gateway->createPayment(BankTestConfig::order(amount: 199.90))->formFields;

        $fromMoney = $gateway->createPayment(new CreatePaymentData(
            amount: Money::fromMinorUnits(19990),
            currency: 'TRY',
            orderId: 'ORDER-1',
            customer: [],
            successUrl: 'https://shop.test/basarili',
            failUrl: 'https://shop.test/hata',
            card: BankTestConfig::card(),
        ))->formFields;

        expect($fromMoney['txnamount'])->toBe($fromFloat['txnamount'])
            // Tutar imzaya girdiği için hash de birebir aynı olmalı.
            ->and($fromMoney['secure3dhash'])->toBe($fromFloat['secure3dhash']);
    });
});
