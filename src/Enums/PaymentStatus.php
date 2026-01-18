<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay\Enums;

/**
 * Ödeme Durumu Enum
 *
 * Bir ödeme işleminin yaşam döngüsü boyunca alabileceği
 * tüm olası durumları tanımlar. Bu enum, ödeme durumlarını
 * standartlaştırarak farklı sağlayıcılar arasında tutarlılık sağlar.
 */
enum PaymentStatus: string
{
    /**
     * Ödeme başlatıldı, henüz işleme alınmadı.
     * Müşteri ödeme sayfasına yönlendirilmeden önceki durum.
     */
    case PENDING = 'pending';

    /**
     * Ödeme işleniyor.
     * 3D Secure doğrulaması veya banka onayı bekleniyor.
     */
    case PROCESSING = 'processing';

    /**
     * 3D Secure doğrulaması bekleniyor.
     * Müşteri banka sayfasında doğrulama yapıyor.
     */
    case AWAITING_3DS = 'awaiting_3ds';

    /**
     * Ödeme başarıyla tamamlandı.
     * Para müşterinin hesabından çekildi.
     */
    case COMPLETED = 'completed';

    /**
     * Ödeme başarısız oldu.
     * Kart reddedildi, yetersiz bakiye vb.
     */
    case FAILED = 'failed';

    /**
     * Ödeme müşteri tarafından iptal edildi.
     * 3D Secure sayfasından geri dönüldü.
     */
    case CANCELLED = 'cancelled';

    /**
     * Ödeme süresi doldu.
     * Belirlenen süre içinde tamamlanmadı.
     */
    case EXPIRED = 'expired';

    /**
     * Ödeme tamamen iade edildi.
     */
    case REFUNDED = 'refunded';

    /**
     * Ödeme kısmen iade edildi.
     */
    case PARTIALLY_REFUNDED = 'partially_refunded';

    /**
     * Ödeme itiraz edildi (chargeback).
     * Müşteri bankaya itiraz başvurusu yaptı.
     */
    case DISPUTED = 'disputed';

    /**
     * Durumun başarılı bir ödemeyi temsil edip etmediğini kontrol eder.
     */
    public function isSuccessful(): bool
    {
        return $this === self::COMPLETED;
    }

    /**
     * Durumun nihai (değişmeyecek) olup olmadığını kontrol eder.
     */
    public function isFinal(): bool
    {
        return in_array($this, [
            self::COMPLETED,
            self::FAILED,
            self::CANCELLED,
            self::EXPIRED,
            self::REFUNDED,
        ], true);
    }

    /**
     * Durumun beklemede olup olmadığını kontrol eder.
     */
    public function isPending(): bool
    {
        return in_array($this, [
            self::PENDING,
            self::PROCESSING,
            self::AWAITING_3DS,
        ], true);
    }

    /**
     * Durumun iade edilmiş olup olmadığını kontrol eder.
     */
    public function isRefunded(): bool
    {
        return in_array($this, [
            self::REFUNDED,
            self::PARTIALLY_REFUNDED,
        ], true);
    }

    /**
     * Durumun Türkçe açıklamasını döndürür.
     */
    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Beklemede',
            self::PROCESSING => 'İşleniyor',
            self::AWAITING_3DS => '3D Secure Bekleniyor',
            self::COMPLETED => 'Tamamlandı',
            self::FAILED => 'Başarısız',
            self::CANCELLED => 'İptal Edildi',
            self::EXPIRED => 'Süresi Doldu',
            self::REFUNDED => 'İade Edildi',
            self::PARTIALLY_REFUNDED => 'Kısmen İade Edildi',
            self::DISPUTED => 'İtiraz Edildi',
        };
    }
}
