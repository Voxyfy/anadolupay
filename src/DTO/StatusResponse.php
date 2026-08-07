<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay\DTO;

use Voxyfy\AnadoluPay\Support\Money;

/**
 * Sipariş Durumu Yanıtı
 *
 * Durum sorgusunun asıl işi, bankaların birbirine benzemeyen durum
 * kodlarını (`Approved`, `1`, `SUCCESS`, `Basarili`, `00`) tek bir
 * sözlüğe indirgemektir. Ham yanıt `raw` içinde korunur.
 *
 * Bu sorgu, zaman aşımı gibi belirsiz durumları kapatmanın tek yoludur:
 * bir `TransportException` aldığınızda ödemenin gerçekleşip
 * gerçekleşmediğini yalnızca bankaya sorarak öğrenebilirsiniz.
 */
readonly class StatusResponse
{
    /** Ödeme alındı. */
    public const STATUS_PAID = 'paid';

    /** Banka işlemi reddetti. */
    public const STATUS_FAILED = 'failed';

    /** İşlem başladı ancak sonuçlanmadı (3D doğrulaması bekleniyor gibi). */
    public const STATUS_PENDING = 'pending';

    /** Gün sonu öncesi iptal edildi. */
    public const STATUS_CANCELLED = 'cancelled';

    /** Tamamen veya kısmen iade edildi. */
    public const STATUS_REFUNDED = 'refunded';

    /** Ön provizyon alındı, kapama bekliyor. */
    public const STATUS_PRE_AUTHORIZED = 'pre_authorized';

    /** Banka bir durum döndürdü ancak eşlenemedi. */
    public const STATUS_UNKNOWN = 'unknown';

    /**
     * @param  bool  $found  Banka bu sipariş numarasını tanıdı mı
     * @param  string  $status  Normalleştirilmiş durum; self::STATUS_* sabitlerinden biri
     * @param  string|null  $orderId  Satıcı sipariş numarası
     * @param  string|null  $paymentId  Bankanın işlem referansı
     * @param  Money|null  $amount  İşlem tutarı
     * @param  Money|null  $refundedAmount  İade edilmiş tutar (banka bildiriyorsa)
     * @param  int|null  $installment  Taksit sayısı
     * @param  string|null  $transactionTime  Bankanın bildirdiği işlem zamanı (ham biçimde)
     * @param  string|null  $maskedCardNumber  Maskeli kart numarası
     * @param  string|null  $errorMessage  Başarısız işlemlerde bankanın açıklaması
     * @param  array<string, mixed>  $raw  Bankanın ham yanıtı
     */
    public function __construct(
        public bool $found,
        public string $status,
        public ?string $orderId = null,
        public ?string $paymentId = null,
        public ?Money $amount = null,
        public ?Money $refundedAmount = null,
        public ?int $installment = null,
        public ?string $transactionTime = null,
        public ?string $maskedCardNumber = null,
        public ?string $errorMessage = null,
        public array $raw = [],
    ) {}

    /**
     * Banka bu sipariş numarasını tanımadı.
     *
     * Ödeme başlatılmadan önce zaman aşımı alınmışsa beklenen sonuç budur:
     * işlem bankaya hiç ulaşmamıştır.
     */
    public static function notFound(?string $orderId = null, array $raw = []): self
    {
        return new self(
            found: false,
            status: self::STATUS_UNKNOWN,
            orderId: $orderId,
            raw: $raw,
        );
    }

    /**
     * Para tahsil edildi mi?
     *
     * Ön provizyon `false` döndürür: tutar bloke edilmiştir ama henüz
     * tahsil edilmemiştir.
     */
    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function isRefunded(): bool
    {
        return $this->status === self::STATUS_REFUNDED;
    }

    /**
     * İşlem sonuçlanmış mı? (beklemede değil)
     */
    public function isSettled(): bool
    {
        return $this->found && ! $this->isPending();
    }
}
