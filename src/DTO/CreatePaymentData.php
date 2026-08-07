<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay\DTO;

use Voxyfy\AnadoluPay\Support\Money;

/**
 * Ödeme Oluşturma Verisi DTO
 *
 * Ödeme sağlayıcılarından bağımsız, yeni bir ödeme işlemi
 * başlatmak için gerekli temel verileri içerir.
 *
 * Banka sanal POS driver'ları ek olarak `card`, `installment`,
 * `paymentModel` ve `ip` alanlarını kullanır.
 */
final readonly class CreatePaymentData
{
    /** 3D Secure: kimlik doğrulama sonrası ayrı bir provizyon isteği atılır. */
    public const MODEL_3D_SECURE = '3d';

    /** 3D Pay: kimlik doğrulama ve provizyon tek adımda banka tarafında yapılır. */
    public const MODEL_3D_PAY = '3d_pay';

    /** 3D Host: kart bilgileri de dahil tüm form banka tarafında toplanır. */
    public const MODEL_3D_HOST = '3d_host';

    /** Non-secure: 3D doğrulaması olmadan doğrudan provizyon. */
    public const MODEL_NON_SECURE = 'regular';

    /**
     * @param  float|Money  $amount  Ödeme tutarı. Kuruş kaybı olmaması için
     *                               `Money::fromMinorUnits(19990)` tercih edilir;
     *                               float (199.90) geriye dönük uyumluluk için
     *                               kabul edilir ve iki ondalık haneye yuvarlanır.
     * @param  string  $currency  ISO para birimi kodu (örn: TRY, USD)
     * @param  string  $orderId  Satıcı sistemindeki benzersiz sipariş referansı
     * @param  array<string, mixed>  $customer  Müşteri bilgileri
     * @param  string|null  $successUrl  Başarılı ödeme sonrası yönlendirme URL'i (opsiyonel)
     * @param  string|null  $failUrl  Başarısız ödeme sonrası yönlendirme URL'i (opsiyonel)
     * @param  array<string, mixed>  $metadata  Sağlayıcıya özel veya dahili ek veriler
     * @param  CardData|null  $card  Kart bilgileri (3D Host dışındaki tüm banka akışlarında zorunlu)
     * @param  int  $installment  Taksit sayısı (1 veya 0 = tek çekim)
     * @param  string  $paymentModel  Ödeme modeli; self::MODEL_* sabitlerinden biri
     * @param  string|null  $ip  Müşterinin IP adresi (birçok banka zorunlu tutar)
     * @param  string  $lang  Banka ödeme sayfasının dili (tr/en)
     * @param  bool  $preAuthorization  Ön provizyon mu? true ise tutar bloke
     *                                  edilir ama tahsil edilmez; tahsilat
     *                                  `capture()` ile yapılır.
     */
    public function __construct(
        public float|Money $amount,
        public string $currency,
        public string $orderId,
        public array $customer,
        public ?string $successUrl = null,
        public ?string $failUrl = null,
        public array $metadata = [],
        public ?CardData $card = null,
        public int $installment = 1,
        public string $paymentModel = self::MODEL_3D_SECURE,
        public ?string $ip = null,
        public string $lang = 'tr',
        public bool $preAuthorization = false,
    ) {}

    /**
     * Aynı veriden ön provizyon isteği üretir.
     *
     * DTO değişmez olduğu için mevcut nesne kopyalanır.
     */
    public function asPreAuthorization(): self
    {
        return new self(
            amount: $this->amount,
            currency: $this->currency,
            orderId: $this->orderId,
            customer: $this->customer,
            successUrl: $this->successUrl,
            failUrl: $this->failUrl,
            metadata: $this->metadata,
            card: $this->card,
            installment: $this->installment,
            paymentModel: $this->paymentModel,
            ip: $this->ip,
            lang: $this->lang,
            preAuthorization: true,
        );
    }

    /**
     * Tutarı kuruş cinsinden taşıyan `Money` nesnesi olarak döndürür.
     *
     * Driver'lar tutara her zaman buradan erişir; `$amount` alanına
     * doğrudan bakmazlar. Böylece float ile Money arasındaki fark
     * yalnızca tek bir yerde ele alınır.
     */
    public function money(): Money
    {
        return Money::of($this->amount, $this->currency);
    }

    /**
     * Kart bilgisini döndürür; `card` verilmemişse `customer['card']`
     * dizisinden üretmeyi dener.
     *
     * Bu geriye dönük uyumluluk içindir: paketin ilk sürümünde kart
     * bilgisi `customer['card']` altında taşınıyordu.
     */
    public function card(): ?CardData
    {
        if ($this->card instanceof CardData) {
            return $this->card;
        }

        $card = $this->customer['card'] ?? null;

        return is_array($card) ? CardData::fromArray($card) : null;
    }

    /**
     * Taksit sayısını normalize eder; tek çekim için 1 döndürür.
     */
    public function installments(): int
    {
        return $this->installment > 1 ? $this->installment : 1;
    }

    /**
     * Müşteri IP'sini döndürür; verilmemişse `customer['ip']`e düşer.
     */
    public function clientIp(): string
    {
        if ($this->ip !== null && $this->ip !== '') {
            return $this->ip;
        }

        $ip = $this->customer['ip'] ?? null;

        return is_string($ip) && $ip !== '' ? $ip : '127.0.0.1';
    }
}
