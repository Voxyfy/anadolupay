<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay\DTO;

use Voxyfy\AnadoluPay\Support\Money;

/**
 * Taksit Seçeneği
 *
 * Bir taksit adedi için müşterinin ödeyeceği toplam ve aylık tutarı
 * taşır. Komisyon oranı bankaya ve karta göre değiştiği için toplam
 * tutar işlem tutarından yüksek olabilir.
 */
final readonly class InstallmentOption
{
    /**
     * @param  int  $count  Taksit adedi (1 = tek çekim)
     * @param  Money|null  $totalPrice  Müşterinin ödeyeceği toplam
     * @param  Money|null  $monthlyPrice  Aylık taksit tutarı
     * @param  float|null  $commissionRate  Komisyon oranı (yüzde)
     * @param  string|null  $bankName  Seçeneği sunan banka
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public int $count,
        public ?Money $totalPrice = null,
        public ?Money $monthlyPrice = null,
        public ?float $commissionRate = null,
        public ?string $bankName = null,
        public array $raw = [],
    ) {}

    /**
     * Tek çekim mi?
     */
    public function isSingle(): bool
    {
        return $this->count <= 1;
    }
}
