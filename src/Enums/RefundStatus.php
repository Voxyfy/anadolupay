<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay\Enums;

/**
 * İade Durumu Enum
 *
 * Bir iade işleminin yaşam döngüsü boyunca alabileceği
 * tüm olası durumları tanımlar.
 */
enum RefundStatus: string
{
    /**
     * İade talebi alındı, henüz işleme alınmadı.
     */
    case PENDING = 'pending';

    /**
     * İade işleniyor.
     * Banka/sağlayıcı tarafından değerlendiriliyor.
     */
    case PROCESSING = 'processing';

    /**
     * İade başarıyla tamamlandı.
     * Para müşterinin hesabına iade edildi.
     */
    case COMPLETED = 'completed';

    /**
     * İade başarısız oldu.
     */
    case FAILED = 'failed';

    /**
     * İade iptal edildi.
     */
    case CANCELLED = 'cancelled';

    /**
     * Durumun başarılı bir iadeyi temsil edip etmediğini kontrol eder.
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
            self::COMPLETED => 'Tamamlandı',
            self::FAILED => 'Başarısız',
            self::CANCELLED => 'İptal Edildi',
        };
    }
}
