<?php

declare(strict_types=1);

use Voxyfy\AnadoluPay\DTO\CreatePaymentData;
use Voxyfy\AnadoluPay\Exceptions\PaymentFailedException;
use Voxyfy\AnadoluPay\Facades\AnadoluPay;
use Voxyfy\AnadoluPay\Support\Money;
use Voxyfy\AnadoluPay\Support\OrderNumber;

describe('OrderNumber', function () {
    it('yapılandırılmış ön ek ve uzunlukla numara üretir', function () {
        config()->set('anadolupay.order.prefix', 'ODM-');
        config()->set('anadolupay.order.length', 10);

        expect(OrderNumber::generate())->toMatch('/^ODM-[A-Z0-9]{10}$/');
    });

    it('ön ek verilmediğinde yalnızca rastgele bölümü döndürür', function () {
        config()->set('anadolupay.order.prefix', '');

        expect(OrderNumber::generate())->toMatch('/^[A-Z0-9]{10}$/');
    });

    it('yalnızca büyük harf ve rakam kullanır', function () {
        // Bazı bankalar küçük harf veya noktalama içeren sipariş numarasını
        // reddeder; numara imzaya girdiği için sonradan düzeltilemez.
        foreach (range(1, 50) as $ignored) {
            expect(OrderNumber::make(length: 16))->toMatch('/^[A-Z0-9]{16}$/');
        }
    });

    it('ardışık çağrılarda aynı numarayı üretmez', function () {
        $numbers = array_map(fn () => OrderNumber::make('ODM-'), range(1, 200));

        expect(array_unique($numbers))->toHaveCount(200);
    });

    it('bankada geçmeyecek karakter içeren ön eki reddeder', function () {
        OrderNumber::make('ODM#');
    })->throws(PaymentFailedException::class, 'yalnızca harf, rakam');

    it('çakışma riski oluşturacak kadar kısa numarayı reddeder', function () {
        OrderNumber::make('ODM-', 4);
    })->throws(PaymentFailedException::class, 'en az 6 karakter');

    it('facade üzerinden ödeme başlatmadan önce numara üretebilir', function () {
        config()->set('anadolupay.order.prefix', 'ODM-');

        expect(AnadoluPay::orderId())->toMatch('/^ODM-[A-Z0-9]{10}$/');
    });
});

describe('CreatePaymentData sipariş numarası', function () {
    $data = fn (string $orderId) => new CreatePaymentData(
        amount: Money::fromMinorUnits(19990),
        currency: 'TRY',
        orderId: $orderId,
        customer: ['email' => 'test@example.com'],
    );

    it('sipariş numarası boş geçilirse yapılandırmadan üretir', function () use ($data) {
        config()->set('anadolupay.order.prefix', 'ODM-');

        expect($data('')->orderId)->toMatch('/^ODM-[A-Z0-9]{10}$/');
    });

    it('verilen sipariş numarasına dokunmaz', function () use ($data) {
        config()->set('anadolupay.order.prefix', 'ODM-');

        expect($data('SIPARIS-42')->orderId)->toBe('SIPARIS-42');
    });

    it('ön provizyon kopyasında aynı numarayı korur', function () use ($data) {
        $payment = $data('');

        expect($payment->asPreAuthorization()->orderId)->toBe($payment->orderId);
    });
});
