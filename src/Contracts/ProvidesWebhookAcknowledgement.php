<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay\Contracts;

/**
 * Bildirimi Onaylamak İçin Özel Yanıt İsteyen Driver
 *
 * Bazı sağlayıcılar webhook'un işlendiğini yalnızca belirli bir yanıt
 * gövdesiyle kabul eder. Beklenen gövde dönmezse bildirimi başarısız
 * sayıp tekrar tekrar gönderirler.
 *
 * En bilinen örneği PayTR'dir: gövdede düz metin `OK` bekler; JSON
 * dönerseniz bildirimi saatlerce yeniden dener.
 */
interface ProvidesWebhookAcknowledgement
{
    /**
     * Sağlayıcının beklediği ham yanıt gövdesi.
     *
     * @param  bool  $handled  Doğrulama hatasız tamamlandı mı
     */
    public function webhookAcknowledgement(bool $handled): string;

    /**
     * Yanıtın içerik tipi.
     */
    public function webhookAcknowledgementContentType(): string;
}
