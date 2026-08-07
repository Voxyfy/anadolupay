<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay\Contracts;

use Voxyfy\AnadoluPay\DTO\CapturePaymentData;
use Voxyfy\AnadoluPay\DTO\CreatePaymentData;
use Voxyfy\AnadoluPay\DTO\PaymentResponse;

/**
 * Ön Provizyon Alabilen Driver
 *
 * Ön provizyon, karttaki tutarı bloke eder ama tahsil etmez. Nihai tutar
 * belli olduğunda `capture()` ile tahsilat yapılır; iptal edilirse bloke
 * çözülür.
 *
 * Bloke süresiz değildir — bankaya göre değişmekle birlikte tipik olarak
 * 1-30 gün içinde kapatılmazsa kendiliğinden düşer. Kapatılmamış bloke
 * müşterinin limitini gereksiz meşgul eder.
 *
 * Her sağlayıcı desteklemez; Kuveyt Türk ve PayTR sunmaz.
 */
interface SupportsPreAuthorization
{
    /**
     * Ön provizyon alır (tutar bloke edilir, tahsil edilmez).
     *
     * Akış normal ödemeyle aynıdır: 3D modellerinde form döner, müşteri
     * bankada doğrulama yapar ve dönüş `verify()` ile tamamlanır.
     */
    public function preAuthorize(CreatePaymentData $data): PaymentResponse;

    /**
     * Ön provizyonu kapatır ve tutarı tahsil eder.
     */
    public function capture(CapturePaymentData $data): PaymentResponse;
}
