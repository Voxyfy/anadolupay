<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay\Contracts;

use Voxyfy\AnadoluPay\Exceptions\PaymentFailedException;
use Voxyfy\AnadoluPay\Exceptions\UnsupportedOperationException;

/**
 * Ödeme Geçidi Arayüzü (Interface)
 *
 * Tüm ödeme geçidi driver'larının implement etmesi gereken sözleşmeyi tanımlar.
 * Bu interface, farklı Türk ödeme sağlayıcılarıyla etkileşim için birleşik bir
 * API sağlar ve implementasyonlar arasında tutarlılık sağlar.
 *
 * Tüm gateway implementasyonları, sağlayıcıya özel mantığı dahili olarak
 * yönetirken bu standartlaştırılmış arayüzü uygulamaya sunmalıdır.
 */
interface PaymentGatewayInterface
{
    /**
     * Yeni bir ödeme işlemi oluşturur.
     *
     * Yapılandırılmış ödeme sağlayıcısı ile bir ödeme isteği başlatır.
     * Veri dizisi yapısı, ödeme türüne (kredi kartı, banka havalesi vb.)
     * ve sağlayıcının gereksinimlerine göre değişebilir.
     *
     * Yaygın veri alanları:
     * - 'amount': float|int Ödeme tutarı (zorunlu)
     * - 'currency': string ISO 4217 para birimi kodu (örn: 'TRY', 'USD')
     * - 'order_id': string|int Benzersiz sipariş/işlem tanımlayıcısı
     * - 'description': string Ödeme açıklaması
     * - 'customer': array Müşteri bilgileri (ad, e-posta, telefon vb.)
     * - 'card': array Kredi kartı detayları (gerekli ise)
     * - 'billing_address': array Fatura adresi bilgileri
     * - 'shipping_address': array Teslimat adresi bilgileri
     * - 'callback_url': string Ödeme bildirimleri için URL
     * - 'return_url': string Ödeme sonrası yönlendirme URL'i
     *
     * @param  array<string, mixed>  $data  İşlem detaylarını içeren ödeme verisi.
     *                                      Zorunlu ve opsiyonel alanlar gateway
     *                                      implementasyonuna bağlıdır.
     * @return array<string, mixed> Ödeme yanıtı:
     *                              - 'success': bool Ödemenin başlatılıp başlatılmadığı
     *                              - 'transaction_id': string Sağlayıcının işlem ID'si
     *                              - 'status': string Ödeme durumu
     *                              - 'redirect_url': string|null 3D Secure yönlendirme URL'i
     *                              - 'raw_response': array Sağlayıcının orijinal yanıtı
     *
     * @throws PaymentFailedException Doğrulama hataları, sağlayıcı hataları veya
     *                                bağlantı sorunları nedeniyle ödeme işlenemediğinde
     *
     * @example
     * $sonuc = $gateway->createPayment([
     *     'amount' => 100.00,
     *     'currency' => 'TRY',
     *     'order_id' => 'SIPARIS-123',
     *     'customer' => [
     *         'name' => 'Ahmet Yılmaz',
     *         'email' => 'ahmet@example.com',
     *     ],
     * ]);
     */
    public function createPayment(array $data): array;

    /**
     * Bir ödeme işlemini doğrular.
     *
     * Ödeme sağlayıcısı ile bir ödeme işleminin durumunu ve gerçekliğini
     * onaylar. Bu metot, callback aldıktan sonra veya ödeme durumunu
     * kontrol ederken çağrılmalıdır.
     *
     * @param  string  $transactionId  createPayment() sırasında ödeme sağlayıcısı
     *                                 tarafından döndürülen veya callback
     *                                 bildirimlerinde alınan benzersiz işlem tanımlayıcısı.
     * @param  array<string, mixed>  $data  Belirli sağlayıcılar tarafından gerekli
     *                                      olabilecek ek doğrulama verisi:
     *                                      - 'hash': string Callback'ten gelen doğrulama hash'i
     *                                      - 'order_id': string Orijinal sipariş ID'si
     *                                      - 'amount': float Doğrulama için beklenen tutar
     * @return array<string, mixed> Doğrulama yanıtı:
     *                              - 'verified': bool Ödemenin doğrulanıp doğrulanmadığı
     *                              - 'status': string Mevcut ödeme durumu
     *                              (completed, pending, failed vb.)
     *                              - 'transaction_id': string Sağlayıcının işlem ID'si
     *                              - 'amount': float Doğrulanmış ödeme tutarı
     *                              - 'currency': string Ödeme para birimi
     *                              - 'paid_at': string|null Ödeme tamamlanma zamanı
     *                              - 'raw_response': array Sağlayıcının orijinal yanıtı
     *
     * @throws PaymentFailedException Geçersiz işlem, sağlayıcı hataları veya
     *                                bağlantı sorunları nedeniyle doğrulama
     *                                başarısız olduğunda
     *
     * @example
     * $sonuc = $gateway->verify('TXN-123456', [
     *     'hash' => 'callback_dogrulama_hash',
     * ]);
     *
     * if ($sonuc['verified'] && $sonuc['status'] === 'completed') {
     *     // Ödeme onaylandı, siparişi tamamla
     * }
     */
    public function verify(string $transactionId, array $data = []): array;

    /**
     * Bir ödeme işlemi için iade işlemi yapar.
     *
     * Daha önce tamamlanmış bir ödeme için tam veya kısmi iade başlatır.
     * Tüm ödeme sağlayıcıları kısmi iadeyi desteklemez; belirli
     * yetenekler için sağlayıcı dokümantasyonuna bakın.
     *
     * @param  string  $transactionId  İade edilecek orijinal ödemenin
     *                                 benzersiz işlem tanımlayıcısı.
     * @param  array<string, mixed>  $data  İade verisi:
     *                                      - 'amount': float|null İade tutarı. null veya
     *                                      belirtilmezse tam iade yapılır.
     *                                      - 'reason': string|null İade nedeni
     *                                      - 'reference': string|null Dahili iade referansınız
     * @return array<string, mixed> İade yanıtı:
     *                              - 'success': bool İadenin işlenip işlenmediği
     *                              - 'refund_id': string Sağlayıcının iade ID'si
     *                              - 'transaction_id': string Orijinal işlem ID'si
     *                              - 'amount': float İade edilen tutar
     *                              - 'status': string İade durumu
     *                              - 'raw_response': array Sağlayıcının orijinal yanıtı
     *
     * @throws PaymentFailedException Geçersiz işlem, yetersiz bakiye, sağlayıcı
     *                                hataları veya bağlantı sorunları nedeniyle
     *                                iade işlenemediğinde
     * @throws UnsupportedOperationException Ödeme sağlayıcısı veya belirli ödeme
     *                                       yöntemi iadeleri desteklemediğinde
     *
     * @example
     * // Tam iade
     * $sonuc = $gateway->refund('TXN-123456');
     *
     * // Kısmi iade
     * $sonuc = $gateway->refund('TXN-123456', [
     *     'amount' => 50.00,
     *     'reason' => 'Müşteri kısmi iade talep etti',
     * ]);
     */
    public function refund(string $transactionId, array $data = []): array;
}
