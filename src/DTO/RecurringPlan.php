<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay\DTO;

use DateTimeInterface;
use Voxyfy\AnadoluPay\Exceptions\PaymentFailedException;

/**
 * Tekrarlayan Ödeme Planı
 *
 * Abonelik ve düzenli tahsilat senaryolarında, ilk ödemeyle birlikte
 * bankaya kaç kere ve hangi aralıkla çekim yapılacağı bildirilir. Banka
 * sonraki çekimleri kendisi başlatır; kart bilgisini tekrar göndermezsiniz.
 *
 * Bu, taksitten farklıdır: taksitte tek bir tutar bankaca bölünür,
 * tekrarlayan ödemede her dönem yeni bir çekim yapılır.
 */
final readonly class RecurringPlan
{
    public const FREQUENCY_DAY = 'day';

    public const FREQUENCY_WEEK = 'week';

    public const FREQUENCY_MONTH = 'month';

    public const FREQUENCY_YEAR = 'year';

    /**
     * @param  int  $interval  Frekans çarpanı (2 + month = iki ayda bir)
     * @param  string  $frequency  self::FREQUENCY_* sabitlerinden biri
     * @param  int  $paymentCount  Toplam çekim sayısı
     * @param  DateTimeInterface|null  $startDate  İlk tekrarın tarihi
     * @param  DateTimeInterface|null  $endDate  Planın bitiş tarihi (PayFlex zorunlu tutar)
     *
     * @throws PaymentFailedException Frekans geçersizse
     */
    public function __construct(
        public int $interval,
        public string $frequency,
        public int $paymentCount,
        public ?DateTimeInterface $startDate = null,
        public ?DateTimeInterface $endDate = null,
    ) {
        if (! in_array($frequency, self::frequencies(), true)) {
            throw new PaymentFailedException(
                message: sprintf("Geçersiz tekrar frekansı: '%s'.", $frequency),
                context: ['supported' => self::frequencies()],
            );
        }

        if ($interval < 1 || $paymentCount < 1) {
            throw new PaymentFailedException(
                message: 'Tekrar aralığı ve çekim sayısı en az 1 olmalıdır.',
                context: ['interval' => $interval, 'payment_count' => $paymentCount],
            );
        }
    }

    /**
     * @return list<string>
     */
    public static function frequencies(): array
    {
        return [self::FREQUENCY_DAY, self::FREQUENCY_WEEK, self::FREQUENCY_MONTH, self::FREQUENCY_YEAR];
    }

    /**
     * Frekansı bankanın beklediği koda çevirir.
     *
     * @param  array<string, string>  $dictionary  Bankaya özel sözlük
     *
     * @throws PaymentFailedException Banka bu frekansı desteklemiyorsa
     */
    public function frequencyCode(array $dictionary): string
    {
        if (! isset($dictionary[$this->frequency])) {
            throw new PaymentFailedException(
                message: sprintf("Banka '%s' tekrar frekansını desteklemiyor.", $this->frequency),
                context: ['supported' => array_keys($dictionary)],
            );
        }

        return $dictionary[$this->frequency];
    }
}
