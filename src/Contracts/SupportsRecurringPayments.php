<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay\Contracts;

/**
 * Tekrarlayan Ödeme Destekleyen Driver
 *
 * Plan, ilk ödemeyle birlikte bankaya bildirilir; sonraki çekimleri banka
 * kendisi başlatır. Bu yüzden ayrı bir metot yerine, ödeme verisine
 * `metadata['recurring']` olarak bir `RecurringPlan` eklenir:
 *
 *     new CreatePaymentData(
 *         amount: 49.90,
 *         // …
 *         metadata: ['recurring' => new RecurringPlan(1, RecurringPlan::FREQUENCY_MONTH, 12)],
 *     );
 *
 * Bu arayüz yalnızca yeteneği bildirir; `instanceof` ile kontrol edin.
 * Asseco/NestPay, Garanti, PayFlex ve Akbank POS destekler.
 */
interface SupportsRecurringPayments
{
    /**
     * Bankanın desteklediği tekrar frekansları.
     *
     * @return list<string> RecurringPlan::FREQUENCY_* değerleri
     */
    public function supportedRecurringFrequencies(): array;
}
