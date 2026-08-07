<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay\Gateways;

use Illuminate\Support\Facades\Cache;
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
 * Yaptığı işlemleri önbellekte tutar: bir siparişi ödeyip sonra
 * `status()` sorarsanız gerçekten `paid` döner, iade ederseniz
 * `refunded` olur. Böylece akışın tamamı bankaya bağlanmadan denenebilir.
 *
 * Kayıtların önbellekte tutulmasının sebebi, 3D akışının birden fazla
 * HTTP isteğine yayılmasıdır: ödeme bir istekte başlar, banka dönüşü
 * başka bir istekte gelir. Nesne özelliğinde tutulan durum bu sınırı
 * geçemez.
 *
 * Varsayılan olarak **her işlem başarılıdır**. Hata yollarını denemek
 * isterseniz başarı oranını düşürün:
 *
 *     config(['anadolupay.fake.success_rate' => 0]);
 */
class FakeGateway implements PaymentGatewayInterface, SupportsCancellation, SupportsPreAuthorization, SupportsStatusQuery
{
    /** Siparişlerin tutulduğu önbellek anahtarı. */
    protected const CACHE_KEY = 'anadolupay:fake:orders';

    /** Kayıtların önbellekte kalma süresi (saniye). */
    protected const CACHE_TTL = 3600;

    public function createPayment(CreatePaymentData $data): PaymentResponse
    {
        if (! $this->shouldSucceed()) {
            throw new PaymentFailedException(
                message: 'Sahte ödeme başarısız oldu.',
                context: ['order_id' => $data->orderId, 'amount' => $data->money()->toDecimalString()],
            );
        }

        $paymentId = 'fake_pay_'.bin2hex(random_bytes(6));

        $this->putOrder($data->orderId, [
            'status' => $data->preAuthorization
                ? StatusResponse::STATUS_PRE_AUTHORIZED
                : StatusResponse::STATUS_PAID,
            'minor_units' => $data->money()->minorUnits,
            'currency' => $data->currency,
            'payment_id' => $paymentId,
            'pre_auth' => $data->preAuthorization,
        ]);

        return new PaymentResponse(
            success: true,
            paymentId: $paymentId,
            raw: [
                'payment_id' => $paymentId,
                'order_id' => $data->orderId,
                'amount' => $data->money()->toDecimalString(),
                'currency' => $data->currency,
            ],
            // Dış bir adrese yönlendirmek yerine kendi 3D sayfamızı üretiyoruz:
            // böylece akış internet bağlantısı olmadan, tamamen yerelde denenebilir.
            htmlContent: $data->successUrl !== null
                ? $this->simulated3dPage($data, $paymentId)
                : null,
        );
    }

    public function verify(VerifyPaymentData $data): VerificationResponse
    {
        $orderId = isset($data->payload['order_id']) ? (string) $data->payload['order_id'] : null;
        $paymentId = $data->payload['payment_id']
            ?? ($orderId !== null ? ($this->orders()[$orderId]['payment_id'] ?? null) : null);

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
        $order = $this->orders()[$data->orderId] ?? null;

        if ($order === null || ! $order['pre_auth']) {
            throw new PaymentFailedException(
                message: "Kapatılacak bir ön provizyon bulunamadı: '{$data->orderId}'.",
                context: ['order_id' => $data->orderId],
            );
        }

        $order['status'] = StatusResponse::STATUS_PAID;
        $order['pre_auth'] = false;

        if (($amount = $data->money()) !== null) {
            $order['minor_units'] = $amount->minorUnits;
        }

        $this->putOrder($data->orderId, $order);

        return new PaymentResponse(
            success: true,
            paymentId: $order['payment_id'],
            raw: ['order_id' => $data->orderId],
        );
    }

    public function status(string $orderId, array $context = []): StatusResponse
    {
        $order = $this->orders()[$orderId] ?? null;

        if ($order === null) {
            return StatusResponse::notFound($orderId);
        }

        $amount = Money::fromMinorUnits($order['minor_units'], $order['currency']);

        return new StatusResponse(
            found: true,
            status: $order['status'],
            orderId: $orderId,
            paymentId: $order['payment_id'],
            amount: $amount,
            raw: $order + ['amount' => $amount->toDecimalString()],
        );
    }

    /**
     * Bankanın 3D sayfasını taklit eden yerel bir sayfa üretir.
     *
     * Gerçek bankada müşteri SMS kodu girer; burada iki düğme vardır.
     * Sayfa, satıcının dönüş adresine gerçek bir POST yapar — böylece
     * `verify()` akışı da denenmiş olur.
     */
    protected function simulated3dPage(CreatePaymentData $data, string $paymentId): string
    {
        $callback = (string) $data->successUrl;
        $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

        return <<<HTML
            <!DOCTYPE html>
            <html lang="tr"><head><meta charset="UTF-8">
            <meta name="viewport" content="width=device-width,initial-scale=1">
            <title>Sahte Banka — 3D Secure</title>
            <style>
                body{font:15px/1.5 ui-sans-serif,system-ui,sans-serif;background:#f6f7f9;
                     display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}
                .box{background:#fff;border:1px solid #e4e6ea;border-radius:12px;padding:2rem;
                     max-width:400px;width:calc(100% - 2rem);text-align:center}
                h1{font-size:1.1rem;margin:0 0 .4rem}
                p{color:#6b7280;font-size:.88rem;margin:0 0 1.4rem}
                dl{display:grid;grid-template-columns:auto 1fr;gap:.3rem 1rem;text-align:left;
                   font-size:.85rem;margin:0 0 1.5rem}
                dt{color:#6b7280}dd{margin:0;font-family:ui-monospace,Menlo,monospace}
                button{width:100%;padding:.7rem;border:0;border-radius:8px;font:600 15px inherit;
                       cursor:pointer;margin-bottom:.5rem}
                .ok{background:#16a34a;color:#fff}.no{background:#e5e7eb;color:#374151}
            </style></head><body>
            <div class="box">
                <h1>Sahte Banka 3D Secure</h1>
                <p>Bu sayfa <code>fake</code> driver'ı tarafından üretildi. Gerçek bir banka
                   sayfası değildir; akışı yerelde denemek içindir.</p>
                <dl>
                    <dt>Sipariş</dt><dd>{$escape($data->orderId)}</dd>
                    <dt>Tutar</dt><dd>{$escape($data->money()->toDecimalString())} {$escape($data->currency)}</dd>
                    <dt>Kart</dt><dd>{$escape($data->card()?->masked() ?? '—')}</dd>
                </dl>
                <form method="POST" action="{$escape($callback)}">
                    <input type="hidden" name="order_id" value="{$escape($data->orderId)}">
                    <input type="hidden" name="payment_id" value="{$escape($paymentId)}">
                    <button class="ok" name="mdStatus" value="1" type="submit">Onayla</button>
                    <button class="no" name="mdStatus" value="0" type="submit">Reddet</button>
                </form>
            </div></body></html>
            HTML;
    }

    /**
     * Kaydedilmiş siparişleri temizler.
     *
     * Testler arasında durumun sızmaması için çağırın.
     */
    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Sipariş numarası veya ödeme numarasıyla eşleşen kaydı işaretler.
     */
    protected function markOrder(string $reference, string $status): void
    {
        $orders = $this->orders();

        if (isset($orders[$reference])) {
            $orders[$reference]['status'] = $status;
            $this->putOrder($reference, $orders[$reference]);

            return;
        }

        foreach ($orders as $orderId => $order) {
            if ($order['payment_id'] === $reference) {
                $order['status'] = $status;
                $this->putOrder($orderId, $order);

                return;
            }
        }
    }

    /**
     * Kaydedilmiş siparişleri okur.
     *
     * @return array<string, array{status: string, minor_units: int, currency: string, payment_id: string, pre_auth: bool}>
     */
    protected function orders(): array
    {
        $orders = Cache::get(self::CACHE_KEY, []);

        return is_array($orders) ? $orders : [];
    }

    /**
     * Bir siparişi kaydeder.
     *
     * @param  array<string, mixed>  $order
     */
    protected function putOrder(string $orderId, array $order): void
    {
        $orders = $this->orders();
        $orders[$orderId] = $order;

        Cache::put(self::CACHE_KEY, $orders, self::CACHE_TTL);
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
