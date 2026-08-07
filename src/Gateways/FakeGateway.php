<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay\Gateways;

use Voxyfy\AnadoluPay\Contracts\PaymentGatewayInterface;
use Voxyfy\AnadoluPay\Contracts\SupportsCancellation;
use Voxyfy\AnadoluPay\Contracts\SupportsPreAuthorization;
use Voxyfy\AnadoluPay\Contracts\SupportsStatusQuery;
use Voxyfy\AnadoluPay\DTO\CapturePaymentData;
use Voxyfy\AnadoluPay\DTO\CreatePaymentData;
use Voxyfy\AnadoluPay\DTO\PaymentResponse;
use Voxyfy\AnadoluPay\DTO\RefundPaymentData;
use Voxyfy\AnadoluPay\DTO\RefundResponse;
use Voxyfy\AnadoluPay\DTO\StatusResponse;
use Voxyfy\AnadoluPay\DTO\VerificationResponse;
use Voxyfy\AnadoluPay\DTO\VerifyPaymentData;
use Voxyfy\AnadoluPay\Exceptions\PaymentFailedException;
use Voxyfy\AnadoluPay\Support\Money;

/**
 * Sahte Ödeme Geçidi
 *
 * Geliştirme ve otomatik testler için; hiçbir ağ isteği yapmaz.
 *
 * Gerçek driver'ların desteklediği yetenek arayüzlerini uygular, böylece
 * `SupportsStatusQuery` gibi kontroller yapan uygulama kodunuz bu driver
 * ile de çalışır.
 *
 * Yaptığı işlemleri bellekte tutar: bir siparişi ödeyip sonra
 * `status()` sorarsanız gerçekten `paid` döner, iade ederseniz
 * `refunded` olur. Böylece akışın tamamı bankaya bağlanmadan denenebilir.
 *
 * Varsayılan olarak **her işlem başarılıdır**. Hata yollarını denemek
 * isterseniz başarı oranını düşürün:
 *
 *     config(['anadolupay.fake.success_rate' => 0]);
 */
class FakeGateway implements PaymentGatewayInterface, SupportsCancellation, SupportsPreAuthorization, SupportsStatusQuery
{
    /**
     * Bellekte tutulan siparişler.
     *
     * @var array<string, array{status: string, amount: Money, payment_id: string, pre_auth: bool}>
     */
    protected array $orders = [];

    public function createPayment(CreatePaymentData $data): PaymentResponse
    {
        if (! $this->shouldSucceed()) {
            throw new PaymentFailedException(
                message: 'Sahte ödeme başarısız oldu.',
                context: ['order_id' => $data->orderId, 'amount' => $data->money()->toDecimalString()],
            );
        }

        $paymentId = 'fake_pay_'.bin2hex(random_bytes(6));

        $this->orders[$data->orderId] = [
            'status' => $data->preAuthorization
                ? StatusResponse::STATUS_PRE_AUTHORIZED
                : StatusResponse::STATUS_PAID,
            'amount' => $data->money(),
            'payment_id' => $paymentId,
            'pre_auth' => $data->preAuthorization,
        ];

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

    public function verify(VerifyPaymentData $data): VerificationResponse
    {
        $orderId = isset($data->payload['order_id']) ? (string) $data->payload['order_id'] : null;
        $paymentId = $data->payload['payment_id'] ?? ($orderId !== null ? ($this->orders[$orderId]['payment_id'] ?? null) : null);

        return new VerificationResponse(
            success: true,
            paymentId: is_string($paymentId) ? $paymentId : 'fake_pay_'.bin2hex(random_bytes(6)),
            status: 'success',
            raw: $data->payload,
        );
    }

    public function refund(RefundPaymentData $data): RefundResponse
    {
        if (! $this->shouldSucceed()) {
            throw new PaymentFailedException(
                message: 'Sahte iade başarısız oldu.',
                context: ['payment_id' => $data->paymentId],
            );
        }

        $this->markOrder($data->paymentId, StatusResponse::STATUS_REFUNDED);

        $refundId = 'fake_ref_'.bin2hex(random_bytes(6));

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

    public function cancel(RefundPaymentData $data): RefundResponse
    {
        $this->markOrder($data->paymentId, StatusResponse::STATUS_CANCELLED);

        return new RefundResponse(
            success: true,
            refundId: 'fake_void_'.bin2hex(random_bytes(6)),
            raw: ['payment_id' => $data->paymentId],
        );
    }

    public function preAuthorize(CreatePaymentData $data): PaymentResponse
    {
        return $this->createPayment($data->asPreAuthorization());
    }

    public function capture(CapturePaymentData $data): PaymentResponse
    {
        $order = $this->orders[$data->orderId] ?? null;

        if ($order === null || ! $order['pre_auth']) {
            throw new PaymentFailedException(
                message: "Kapatılacak bir ön provizyon bulunamadı: '{$data->orderId}'.",
                context: ['order_id' => $data->orderId],
            );
        }

        $this->orders[$data->orderId]['status'] = StatusResponse::STATUS_PAID;
        $this->orders[$data->orderId]['pre_auth'] = false;

        if (($amount = $data->money()) !== null) {
            $this->orders[$data->orderId]['amount'] = $amount;
        }

        return new PaymentResponse(
            success: true,
            paymentId: $order['payment_id'],
            raw: ['order_id' => $data->orderId],
        );
    }

    public function status(string $orderId, array $context = []): StatusResponse
    {
        $order = $this->orders[$orderId] ?? null;

        if ($order === null) {
            return StatusResponse::notFound($orderId);
        }

        return new StatusResponse(
            found: true,
            status: $order['status'],
            orderId: $orderId,
            paymentId: $order['payment_id'],
            amount: $order['amount'],
            raw: $order + ['amount' => $order['amount']->toDecimalString()],
        );
    }

    /**
     * Bellekteki siparişleri temizler.
     *
     * Testler arasında durumun sızmaması için çağırın.
     */
    public function flush(): void
    {
        $this->orders = [];
    }

    /**
     * Sipariş numarası veya ödeme numarasıyla eşleşen kaydı işaretler.
     */
    protected function markOrder(string $reference, string $status): void
    {
        if (isset($this->orders[$reference])) {
            $this->orders[$reference]['status'] = $status;

            return;
        }

        foreach ($this->orders as $orderId => $order) {
            if ($order['payment_id'] === $reference) {
                $this->orders[$orderId]['status'] = $status;

                return;
            }
        }
    }

    /**
     * Yapılandırılmış başarı oranına göre işlemin sonucunu belirler.
     *
     * Varsayılan 100'dür: testlerin rastgele kırılmaması için sahte
     * geçidin öngörülebilir olması gerekir. Hata yollarını denemek
     * isteyen uygulama oranı düşürebilir.
     */
    protected function shouldSucceed(): bool
    {
        $rate = (int) config('anadolupay.fake.success_rate', 100);

        if ($rate >= 100) {
            return true;
        }

        if ($rate <= 0) {
            return false;
        }

        return random_int(1, 100) <= $rate;
    }
}
