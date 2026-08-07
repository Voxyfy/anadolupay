<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay\Gateways;

use Voxyfy\AnadoluPay\Contracts\PaymentGatewayInterface;
use Voxyfy\AnadoluPay\DTO\CreatePaymentData;
use Voxyfy\AnadoluPay\DTO\PaymentResponse;
use Voxyfy\AnadoluPay\DTO\RefundPaymentData;
use Voxyfy\AnadoluPay\DTO\RefundResponse;
use Voxyfy\AnadoluPay\DTO\VerificationResponse;
use Voxyfy\AnadoluPay\DTO\VerifyPaymentData;
use Voxyfy\AnadoluPay\Exceptions\PaymentFailedException;

/**
 * Fake Ödeme Geçidi
 *
 * Geliştirme ve test ortamları için sahte ödeme geçidi implementasyonu.
 * Gerçek API çağrısı yapmaz, rastgele başarı/başarısızlık simüle eder.
 */
class FakeGateway implements PaymentGatewayInterface
{
    /**
     * Başarı oranı (0-100 arası).
     */
    protected int $successRate = 80;

    /**
     * Ödeme oluşturma işlemini simüle eder.
     *
     * @param  CreatePaymentData  $data  Ödeme verileri
     * @return PaymentResponse Sahte ödeme yanıtı
     *
     * @throws PaymentFailedException Rastgele başarısızlık durumunda
     */
    public function createPayment(CreatePaymentData $data): PaymentResponse
    {
        if (! $this->shouldSucceed()) {
            throw new PaymentFailedException(
                message: 'Fake payment failed.',
                context: [
                    'order_id' => $data->orderId,
                    'amount' => $data->money()->toDecimalString(),
                ],
            );
        }

        $paymentId = uniqid('fake_pay_');

        return new PaymentResponse(
            success: true,
            paymentId: $paymentId,
            redirectUrl: "https://fake-gateway.test/pay/{$paymentId}",
            raw: [
                'payment_id' => $paymentId,
                'order_id' => $data->orderId,
                'amount' => $data->money()->toDecimalString(),
                'currency' => $data->currency,
            ],
        );
    }

    /**
     * Ödeme doğrulama işlemini simüle eder.
     *
     * @param  VerifyPaymentData  $data  Doğrulama verileri
     * @return VerificationResponse Her zaman başarılı yanıt
     */
    public function verify(VerifyPaymentData $data): VerificationResponse
    {
        $paymentId = $data->payload['payment_id'] ?? uniqid('fake_pay_');

        return new VerificationResponse(
            success: true,
            paymentId: $paymentId,
            status: 'success',
            raw: $data->payload,
        );
    }

    /**
     * İade işlemini simüle eder.
     *
     * @param  RefundPaymentData  $data  İade verileri
     * @return RefundResponse Sahte iade yanıtı
     *
     * @throws PaymentFailedException Rastgele başarısızlık durumunda
     */
    public function refund(RefundPaymentData $data): RefundResponse
    {
        if (! $this->shouldSucceed()) {
            throw new PaymentFailedException(
                message: 'Fake refund failed.',
                context: [
                    'payment_id' => $data->paymentId,
                    'amount' => $data->money()?->toDecimalString(),
                ],
            );
        }

        $refundId = uniqid('fake_ref_');

        return new RefundResponse(
            success: true,
            refundId: $refundId,
            raw: [
                'refund_id' => $refundId,
                'payment_id' => $data->paymentId,
                'amount' => $data->money()?->toDecimalString(),
            ],
        );
    }

    /**
     * Başarı oranına göre işlemin başarılı olup olmayacağını belirler.
     */
    protected function shouldSucceed(): bool
    {
        return random_int(1, 100) <= $this->successRate;
    }
}
