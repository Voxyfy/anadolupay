<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay\Tests\Support;

use Voxyfy\AnadoluPay\DTO\CardData;
use Voxyfy\AnadoluPay\DTO\CreatePaymentData;
use Voxyfy\AnadoluPay\Gateways\Bank\AbstractBankGateway;

/**
 * Banka driver testleri için sabit kimlik bilgileri ve sipariş verisi.
 *
 * Hash testlerinin anlamlı olabilmesi için tüm girdilerin deterministik
 * olması gerekir; bu yüzden kart, sipariş ve kimlik bilgileri sabittir.
 */
final class BankTestConfig
{
    /**
     * Test kartı (banka test kartlarından biri değildir; yalnızca
     * istek üretimini doğrulamak için kullanılır).
     */
    public static function card(): CardData
    {
        return new CardData(
            number: '4155650100416111',
            expireMonth: '12',
            expireYear: '30',
            cvv: '123',
            holderName: 'AHMET YILMAZ',
            type: 'visa',
        );
    }

    /**
     * Sabit sipariş verisi.
     */
    public static function order(
        string $paymentModel = CreatePaymentData::MODEL_3D_SECURE,
        float $amount = 1.99,
        int $installment = 1,
    ): CreatePaymentData {
        return new CreatePaymentData(
            amount: $amount,
            currency: 'TRY',
            orderId: 'ORDER-1',
            customer: [
                'email' => 'ahmet@example.com',
                'name' => 'Ahmet Yılmaz',
                'phone' => '5551112233',
            ],
            successUrl: 'https://shop.test/basarili',
            failUrl: 'https://shop.test/hata',
            card: self::card(),
            installment: $installment,
            paymentModel: $paymentModel,
            ip: '88.10.20.30',
        );
    }

    /**
     * Driver'ı sabit kimlik bilgileriyle üretir.
     *
     * @param  class-string<AbstractBankGateway>  $gateway
     * @param  array<string, mixed>  $overrides
     */
    public static function make(string $gateway, array $overrides = []): AbstractBankGateway
    {
        return $gateway::forBank('test-bank', array_replace_recursive([
            'merchant_id' => 'MERCHANT1',
            'terminal_id' => 'TERMINAL1',
            'username' => 'apiuser',
            'password' => 'apipass',
            'secret_key' => 'SECRETKEY',
            'refund_password' => 'refundpass',
            'endpoints' => [
                'payment_api' => 'https://bank.test/api',
                'gateway_3d' => 'https://bank.test/3d',
                'gateway_3d_host' => 'https://bank.test/3dhost',
                'query_api' => 'https://bank.test/query',
            ],
        ], $overrides));
    }
}
