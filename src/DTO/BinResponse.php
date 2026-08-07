<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay\DTO;

/**
 * BIN Sorgu Yanıtı
 *
 * Kart numarasının ilk 6-8 hanesi (BIN), kartı çıkaran bankayı, markayı
 * ve tipini belirler. Ödeme sayfasında taksit seçeneklerini kart girildiği
 * anda göstermek için kullanılır.
 *
 * BIN sorgusu kart numarasının tamamını gerektirmez ve gerektirmemelidir:
 * yalnızca ilk hanelerini gönderin.
 */
final readonly class BinResponse
{
    /**
     * @param  bool  $found  BIN tanındı mı
     * @param  string|null  $bankName  Kartı çıkaran banka
     * @param  string|null  $brand  Kart markası (visa, mastercard, troy, amex)
     * @param  string|null  $type  Kart tipi (credit, debit, prepaid)
     * @param  bool|null  $commercial  Ticari kart mı
     * @param  bool|null  $domestic  Yurt içi kart mı
     * @param  array<string, mixed>  $raw  Sağlayıcının ham yanıtı
     */
    public function __construct(
        public bool $found,
        public ?string $bankName = null,
        public ?string $brand = null,
        public ?string $type = null,
        public ?bool $commercial = null,
        public ?bool $domestic = null,
        public array $raw = [],
    ) {}

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function notFound(array $raw = []): self
    {
        return new self(found: false, raw: $raw);
    }

    public function isCredit(): bool
    {
        return $this->type === 'credit';
    }

    public function isDebit(): bool
    {
        return $this->type === 'debit';
    }
}
