<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay\DTO;

/**
 * İade Verisi DTO
 *
 * Bir ödemenin tamamen veya kısmen iade edilmesi için
 * gerekli verileri içerir. Kısmi iade desteği sunar.
 *
 * @property-read string $transactionId Orijinal işlem ID'si
 * @property-read float $amount İade tutarı
 * @property-read string $currency Para birimi
 * @property-read string|null $reason İade nedeni
 * @property-read string|null $refundId Benzersiz iade tanımlayıcısı
 * @property-read array $metadata Ek veriler
 */
readonly class RefundPaymentData
{
    /**
     * Yeni bir RefundPaymentData instance'ı oluşturur.
     *
     * @param  string  $transactionId  Orijinal işlemin sağlayıcı ID'si (zorunlu)
     * @param  float  $amount  İade tutarı (zorunlu)
     * @param  string  $currency  Para birimi (varsayılan: 'TRY')
     * @param  string|null  $reason  İade nedeni
     * @param  string|null  $refundId  Sistem tarafından oluşturulan iade ID'si
     * @param  array<string, mixed>  $metadata  Sağlayıcıya özel ek veriler
     */
    public function __construct(
        public string $transactionId,
        public float $amount,
        public string $currency = 'TRY',
        public ?string $reason = null,
        public ?string $refundId = null,
        public array $metadata = [],
    ) {}

    /**
     * Array'den RefundPaymentData oluşturur.
     *
     * @param  array<string, mixed>  $data  İade verileri
     */
    public static function fromArray(array $data): self
    {
        return new self(
            transactionId: $data['transaction_id'] ?? $data['transactionId'],
            amount: (float) $data['amount'],
            currency: $data['currency'] ?? 'TRY',
            reason: $data['reason'] ?? null,
            refundId: $data['refund_id'] ?? $data['refundId'] ?? null,
            metadata: $data['metadata'] ?? [],
        );
    }

    /**
     * RefundPaymentData'yı array'e dönüştürür.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'transaction_id' => $this->transactionId,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'reason' => $this->reason,
            'refund_id' => $this->refundId,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * Tutarı kuruş/cent cinsinden döndürür.
     * Bazı sağlayıcılar tutarı kuruş olarak bekler.
     *
     * @return int Kuruş cinsinden tutar
     */
    public function getAmountInMinorUnits(): int
    {
        return (int) round($this->amount * 100);
    }

    /**
     * İade nedeninin belirtilip belirtilmediğini kontrol eder.
     */
    public function hasReason(): bool
    {
        return $this->reason !== null && $this->reason !== '';
    }
}
