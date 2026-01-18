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
 * bu standartlaştırılmış arayüzü uygulamaya sunmalıdır. Bu sayede uygulama kodu,
 * hangi ödeme sağlayıcısının kullanıldığından bağımsız olarak çalışabilir.
 *
 * Temel özellikler:
 * - Ödeme oluşturma (3D Secure dahil)
 * - Ödeme doğrulama (callback/webhook)
 * - İade işlemleri (tam/kısmi)
 *
 * @see CreatePaymentData Ödeme oluşturma için gerekli veri yapısı
 * @see PaymentResponse Ödeme işlemi sonuç yapısı
 * @see VerifyPaymentData Doğrulama için gerekli veri yapısı
 * @see VerificationResponse Doğrulama sonuç yapısı
 * @see RefundPaymentData İade için gerekli veri yapısı
 * @see RefundResponse İade işlemi sonuç yapısı
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
     * CreatePaymentData içermesi gereken alanlar:
     * - amount: (float) Ödeme tutarı (örn: 99.90)
     * - currency: (string) ISO 4217 para birimi kodu (örn: 'TRY', 'USD', 'EUR')
     * - order_id: (string) Sisteminizdeki benzersiz sipariş tanımlayıcısı
     * - description: (string|null) Ödeme açıklaması/ürün bilgisi
     * - customer: (CustomerData) Müşteri bilgileri
     *   - name: (string) Müşteri adı soyadı
     *   - email: (string) E-posta adresi
     *   - phone: (string|null) Telefon numarası
     *   - ip_address: (string) Müşteri IP adresi
     * - card: (CardData|null) Kredi kartı bilgileri (varsa)
     *   - holder_name: (string) Kart üzerindeki isim
     *   - number: (string) Kart numarası
     *   - expire_month: (string) Son kullanma ayı (MM)
     *   - expire_year: (string) Son kullanma yılı (YY veya YYYY)
     *   - cvv: (string) Güvenlik kodu
     * - billing_address: (AddressData|null) Fatura adresi
     * - shipping_address: (AddressData|null) Teslimat adresi
     * - callback_url: (string) Ödeme sonucu bildirim URL'i (webhook)
     * - return_url: (string) 3D Secure sonrası yönlendirme URL'i
     * - cancel_url: (string|null) İptal durumunda yönlendirme URL'i
     * - locale: (string) Dil kodu (örn: 'tr', 'en')
     * - installment: (int) Taksit sayısı (1 = peşin)
     * - metadata: (array) Ek bilgiler (sağlayıcıya özel)
     *
     * PaymentResponse içeriği:
     * - success: (bool) İşlemin başarılı başlatılıp başlatılmadığı
     * - transaction_id: (string|null) Sağlayıcının işlem tanımlayıcısı
     * - status: (PaymentStatus) Ödeme durumu enum değeri
     * - redirect_url: (string|null) 3D Secure yönlendirme URL'i
     * - html_content: (string|null) 3D Secure form HTML içeriği
     * - requires_redirect: (bool) Yönlendirme gerekip gerekmediği
     * - provider_response: (array) Sağlayıcının ham yanıtı
     * - error_code: (string|null) Hata kodu (başarısızlık durumunda)
     * - error_message: (string|null) Hata mesajı (başarısızlık durumunda)
     *
     * @param  CreatePaymentData  $data  Ödeme oluşturmak için gerekli tüm bilgileri
     *                                   içeren veri transfer nesnesi
     * @return PaymentResponse Ödeme işleminin sonucunu içeren yanıt nesnesi.
     *                         3D Secure gerektiren işlemlerde redirect_url
     *                         veya html_content dolu olacaktır.
     *
     * @throws PaymentFailedException Aşağıdaki durumlarda fırlatılır:
     *                                - Geçersiz kart bilgileri
     *                                - Yetersiz bakiye
     *                                - Kart limiti aşıldı
     *                                - Banka tarafından reddedildi
     *                                - Dolandırıcılık şüphesi
     *                                - Sağlayıcı API hatası
     *                                - Ağ/bağlantı sorunları
     *                                - Doğrulama hataları
     *
     * @example
     * ```php
     * $paymentData = new CreatePaymentData(
     *     amount: 150.00,
     *     currency: 'TRY',
     *     orderId: 'ORD-2024-001',
     *     customer: new CustomerData(
     *         name: 'Ahmet Yılmaz',
     *         email: 'ahmet@example.com',
     *         ipAddress: '192.168.1.1'
     *     ),
     *     card: new CardData(
     *         holderName: 'AHMET YILMAZ',
     *         number: '4111111111111111',
     *         expireMonth: '12',
     *         expireYear: '2025',
     *         cvv: '123'
     *     ),
     *     callbackUrl: 'https://example.com/payment/callback',
     *     returnUrl: 'https://example.com/payment/return'
     * );
     *
     * $response = $gateway->createPayment($paymentData);
     *
     * if ($response->requiresRedirect) {
     *     return redirect($response->redirectUrl);
     * }
     * ```
     */
    public function createPayment(CreatePaymentData $data): PaymentResponse;

    /**
     * Ödeme sağlayıcısından gelen callback/webhook verilerini doğrular.
     *
     * Bu metot, 3D Secure yönlendirmesi sonrası veya webhook bildirimi ile
     * gelen ödeme sonuç verilerini doğrular. İmza kontrolü, tutar doğrulaması
     * ve işlem durumu kontrolü bu metot içinde gerçekleştirilir.
     *
     * ÖNEMLİ: Bu metot, ödeme sağlayıcısından gelen verilerin gerçekliğini
     * ve bütünlüğünü doğrulamak için kritik öneme sahiptir. Sahte callback
     * isteklerini tespit etmek için mutlaka imza doğrulaması yapılmalıdır.
     *
     * VerifyPaymentData içermesi gereken alanlar:
     * - payload: (array) Callback/webhook ile gelen ham veri
     * - headers: (array) HTTP istek başlıkları (imza doğrulaması için)
     * - signature: (string|null) Sağlayıcının gönderdiği imza değeri
     * - transaction_id: (string|null) Doğrulanacak işlem ID'si
     * - order_id: (string|null) Orijinal sipariş ID'si
     * - expected_amount: (float|null) Beklenen tutar (tutar doğrulaması için)
     * - raw_body: (string|null) Ham istek gövdesi (imza hesaplaması için)
     *
     * VerificationResponse içeriği:
     * - verified: (bool) Doğrulamanın başarılı olup olmadığı
     * - transaction_id: (string) Sağlayıcının işlem tanımlayıcısı
     * - order_id: (string) Orijinal sipariş ID'si
     * - status: (PaymentStatus) Ödemenin son durumu
     * - amount: (float) Ödenen tutar
     * - currency: (string) Para birimi
     * - paid_at: (DateTimeInterface|null) Ödeme tamamlanma zamanı
     * - card_summary: (string|null) Maskelenmiş kart numarası (örn: 411111****1111)
     * - installment_count: (int) Taksit sayısı
     * - provider_response: (array) Sağlayıcının ham yanıtı
     * - failure_reason: (string|null) Başarısızlık nedeni
     *
     * @param  VerifyPaymentData  $data  Doğrulama için gerekli callback/webhook
     *                                   verilerini içeren veri transfer nesnesi
     * @return VerificationResponse Doğrulama sonucunu ve ödeme detaylarını
     *                              içeren yanıt nesnesi
     *
     * @throws PaymentFailedException Aşağıdaki durumlarda fırlatılır:
     *                                - İmza doğrulaması başarısız
     *                                - İşlem bulunamadı
     *                                - Tutar uyuşmazlığı
     *                                - Geçersiz callback verisi
     *                                - Sağlayıcı API hatası
     *                                - İşlem zaman aşımına uğradı
     *
     * @example
     * ```php
     * // Controller'da callback işleme
     * public function handleCallback(Request $request): Response
     * {
     *     $verifyData = new VerifyPaymentData(
     *         payload: $request->all(),
     *         headers: $request->headers->all(),
     *         signature: $request->header('X-Signature'),
     *         rawBody: $request->getContent()
     *     );
     *
     *     try {
     *         $result = $gateway->verify($verifyData);
     *
     *         if ($result->verified && $result->status === PaymentStatus::COMPLETED) {
     *             // Siparişi onayla
     *             $order->markAsPaid($result->transactionId);
     *             return response('OK', 200);
     *         }
     *
     *         // Ödeme başarısız
     *         $order->markAsFailed($result->failureReason);
     *         return response('FAILED', 200);
     *
     *     } catch (PaymentFailedException $e) {
     *         Log::error('Ödeme doğrulama hatası', [
     *             'error' => $e->getMessage(),
     *             'payload' => $request->all()
     *         ]);
     *         return response('ERROR', 500);
     *     }
     * }
     * ```
     */
    public function verify(VerifyPaymentData $data): VerificationResponse;

    /**
     * Tamamlanmış bir ödeme için iade işlemi başlatır.
     *
     * Bu metot, daha önce başarıyla tamamlanmış bir ödeme için tam veya
     * kısmi iade işlemi gerçekleştirir. İade tutarı belirtilmezse veya
     * orijinal tutara eşitse tam iade, daha düşükse kısmi iade yapılır.
     *
     * ÖNEMLİ: Tüm ödeme sağlayıcıları iade işlemini desteklemez. Bazı
     * sağlayıcılar sadece tam iade desteklerken, bazıları kısmi iadeyi
     * de destekler. Desteklenmeyen bir işlem talep edildiğinde
     * UnsupportedOperationException fırlatılır.
     *
     * RefundPaymentData içermesi gereken alanlar:
     * - payment_id: (string) İade edilecek orijinal ödemenin işlem ID'si
     * - amount: (float|null) İade tutarı (null = tam iade)
     * - currency: (string|null) Para birimi (genellikle orijinal ile aynı)
     * - reason: (string|null) İade nedeni açıklaması
     * - reference: (string|null) Dahili iade referans numaranız
     * - metadata: (array) Ek bilgiler
     *
     * RefundResponse içeriği:
     * - success: (bool) İade işleminin başarılı olup olmadığı
     * - refund_id: (string) Sağlayıcının iade işlem ID'si
     * - transaction_id: (string) Orijinal ödeme işlem ID'si
     * - amount: (float) İade edilen tutar
     * - currency: (string) Para birimi
     * - status: (RefundStatus) İade durumu enum değeri
     * - refunded_at: (DateTimeInterface|null) İade tamamlanma zamanı
     * - provider_response: (array) Sağlayıcının ham yanıtı
     * - error_code: (string|null) Hata kodu (başarısızlık durumunda)
     * - error_message: (string|null) Hata mesajı (başarısızlık durumunda)
     *
     * @param  RefundPaymentData  $data  İade işlemi için gerekli bilgileri
     *                                   içeren veri transfer nesnesi
     * @return RefundResponse İade işleminin sonucunu içeren yanıt nesnesi
     *
     * @throws PaymentFailedException Aşağıdaki durumlarda fırlatılır:
     *                                - Orijinal işlem bulunamadı
     *                                - İade tutarı orijinal tutarı aşıyor
     *                                - İşlem zaten iade edilmiş
     *                                - İade süresi dolmuş
     *                                - Sağlayıcı API hatası
     *                                - Yetersiz bakiye (satıcı hesabında)
     * @throws UnsupportedOperationException Aşağıdaki durumlarda fırlatılır:
     *                                       - Sağlayıcı iade desteklemiyor
     *                                       - Kısmi iade desteklenmiyor
     *                                       - Bu ödeme yöntemi için iade yapılamaz
     *
     * @example
     * ```php
     * // Tam iade
     * $refundData = new RefundPaymentData(
     *     paymentId: 'TXN-123456',
     *     reason: 'Müşteri ürünü iade etti'
     * );
     *
     * try {
     *     $result = $gateway->refund($refundData);
     *
     *     if ($result->success) {
     *         Log::info('İade başarılı', ['refund_id' => $result->refundId]);
     *     }
     * } catch (UnsupportedOperationException $e) {
     *     // Bu sağlayıcı iade desteklemiyor
     *     Log::warning('İade desteklenmiyor: ' . $e->getMessage());
     * }
     *
     * // Kısmi iade
     * $partialRefund = new RefundPaymentData(
     *     paymentId: 'TXN-123456',
     *     amount: 50.00,
     *     reason: 'Kısmi ürün iadesi'
     * );
     *
     * $result = $gateway->refund($partialRefund);
     * ```
     */
    public function refund(RefundPaymentData $data): RefundResponse;
}
