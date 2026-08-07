<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay\Contracts;

use Voxyfy\AnadoluPay\DTO\RefundPaymentData;
use Voxyfy\AnadoluPay\DTO\RefundResponse;

/**
 * İşlem İptali Yapabilen Driver
 *
 * İptal ile iade farklı işlemlerdir: iptal yalnızca **gün sonu
 * kapanmadan önce** yapılabilir, işlemi hiç olmamış gibi siler ve
 * genellikle komisyon alınmaz. Gün sonundan sonra tek seçenek iadedir.
 *
 * Bu yüzden iptal edilebilir bir işlem için iade kullanmak satıcıya
 * gereksiz komisyon maliyeti çıkarır.
 *
 * Her sağlayıcı iptal sunmaz; örneğin PayTR yalnızca iade destekler.
 * Yeteneği `instanceof` ile kontrol edin.
 */
interface SupportsCancellation
{
    /**
     * Gün sonu öncesi işlemi iptal eder.
     *
     * Bazı bankalar iptali sipariş numarasıyla değil kendi işlem
     * referanslarıyla eşler; bu referansı `metadata` ile geçin.
     */
    public function cancel(RefundPaymentData $data): RefundResponse;
}
