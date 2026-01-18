<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay\Contracts;

use Voxyfy\AnadoluPay\DTO\CreatePaymentData;
use Voxyfy\AnadoluPay\DTO\PaymentResponse;
use Voxyfy\AnadoluPay\DTO\RefundPaymentData;
use Voxyfy\AnadoluPay\DTO\RefundResponse;
use Voxyfy\AnadoluPay\DTO\VerificationResponse;
use Voxyfy\AnadoluPay\DTO\VerifyPaymentData;
use Voxyfy\AnadoluPay\Exceptions\PaymentFailedException;
use Voxyfy\AnadoluPay\Exceptions\UnsupportedOperationException;

/**
 * Ödeme Geçidi Arayüzü (Payment Gateway Interface)
 *
 * Tüm ödeme geçidi driver'larının implement etmesi gereken temel sözleşmeyi tanımlar.
 * Bu interface, farklı Türk ödeme sağlayıcılarıyla (iyzico, PayTR, Param, Sipay vb.)
 * etkileşim için birleşik ve tutarlı bir API sağlar.
 *
 * Her gateway implementasyonu, sağlayıcıya özel iş mantığını dahili olarak yönetirken
 * bu standartlaştırılmış arayüzü uygulamaya sunmalıdır.
 */
interface PaymentGatewayInterface
{
    /**
     * Ödeme sağlayıcısı ile yeni bir ödeme işlemi başlatır.
     *
     * Bu metot, yapılandırılmış ödeme sağlayıcısına bir ödeme isteği gönderir.
     * Sağlayıcı türüne ve ödeme yöntemine bağlı olarak, yanıt doğrudan bir
     * başarı/başarısızlık durumu veya 3D Secure doğrulaması için bir
     * yönlendirme URL'i içerebilir.
     *
     * @param  CreatePaymentData  $data  Ödeme oluşturma verileri:
     *                                   - amount: (float) Ödeme tutarı
     *                                   - currency: (string) ISO para birimi kodu
     *                                   - orderId: (string) Benzersiz sipariş referansı
     *                                   - customer: (array) Müşteri bilgileri
     *                                   - successUrl: (string) Başarılı ödeme yönlendirme URL'i
     *                                   - failUrl: (string) Başarısız ödeme yönlendirme URL'i
     *                                   - metadata: (array) Ek veriler
     * @return PaymentResponse Ödeme işlemi sonucu:
     *                         - success: (bool) İşlem başarılı mı
     *                         - paymentId: (string|null) Sağlayıcı ödeme ID'si
     *                         - redirectUrl: (string|null) Yönlendirme URL'i
     *                         - errorMessage: (string|null) Hata mesajı
     *                         - raw: (array) Sağlayıcının ham yanıtı
     *
     * @throws PaymentFailedException Ödeme işlemi başarısız olduğunda
     */
    public function createPayment(CreatePaymentData $data): PaymentResponse;

    /**
     * Ödeme sağlayıcısından gelen callback/webhook verilerini doğrular.
     *
     * Bu metot, 3D Secure yönlendirmesi sonrası veya webhook bildirimi ile
     * gelen ödeme sonuç verilerini doğrular. İmza kontrolü ve işlem durumu
     * kontrolü bu metot içinde gerçekleştirilir.
     *
     * @param  VerifyPaymentData  $data  Doğrulama verileri:
     *                                   - payload: (array) Callback/webhook verisi
     *                                   - headers: (array) HTTP başlıkları
     *                                   - rawBody: (string|null) Ham istek gövdesi
     * @return VerificationResponse Doğrulama sonucu:
     *                              - success: (bool) Doğrulama başarılı mı
     *                              - paymentId: (string|null) Ödeme ID'si
     *                              - status: (string) Normalleştirilmiş durum
     *                              - raw: (array) Sağlayıcının ham yanıtı
     *
     * @throws PaymentFailedException Doğrulama başarısız olduğunda
     */
    public function verify(VerifyPaymentData $data): VerificationResponse;

    /**
     * Tamamlanmış bir ödeme için iade işlemi başlatır.
     *
     * Bu metot, daha önce başarıyla tamamlanmış bir ödeme için tam veya
     * kısmi iade işlemi gerçekleştirir.
     *
     * @param  RefundPaymentData  $data  İade verileri:
     *                                   - paymentId: (string) Orijinal ödeme ID'si
     *                                   - amount: (float|null) İade tutarı (null = tam iade)
     *                                   - reason: (string|null) İade nedeni
     * @return RefundResponse İade işlemi sonucu:
     *                        - success: (bool) İade başarılı mı
     *                        - refundId: (string|null) Sağlayıcı iade ID'si
     *                        - errorMessage: (string|null) Hata mesajı
     *                        - raw: (array) Sağlayıcının ham yanıtı
     *
     * @throws PaymentFailedException İade işlemi başarısız olduğunda
     * @throws UnsupportedOperationException Sağlayıcı iade desteklemiyorsa
     */
    public function refund(RefundPaymentData $data): RefundResponse;
}
