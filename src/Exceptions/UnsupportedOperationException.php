<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay\Exceptions;

use Exception;

/**
 * Desteklenmeyen İşlem Exception
 *
 * Belirli bir ödeme geçidi driver'ı tarafından desteklenmeyen bir işlem
 * gerçekleştirilmeye çalışıldığında fırlatılır. Tüm ödeme sağlayıcıları
 * tüm işlemleri desteklemez (örn: bazıları iade veya kısmi iade desteklemez).
 *
 * Bu exception, operasyonel hatalar ile belirli ödeme sağlayıcılarının
 * özellik sınırlamalarını ayırt etmeye yardımcı olur.
 *
 * Yaygın senaryolar:
 * - İade desteklemeyen bir sağlayıcıda iade yapılmaya çalışılması
 * - Sadece tam iade desteklenirken kısmi iade istenmesi
 * - Gateway tarafından desteklenmeyen bir ödeme yöntemi kullanılması
 * - Desteklemeyen bir sağlayıcıda tekrarlayan ödeme yapılmaya çalışılması
 *
 * @example
 * try {
 *     $gateway->refund($transactionId, ['amount' => 50.00]);
 * } catch (UnsupportedOperationException $e) {
 *     // Bu sağlayıcı kısmi iadeyi desteklemiyor olabilir
 *     // Tam iade veya alternatif çözüm sun
 *     Log::warning('Kısmi iade desteklenmiyor: ' . $e->getMessage());
 * }
 */
class UnsupportedOperationException extends Exception
{
    /**
     * Ödeme geçidi driver'ının adı.
     */
    protected ?string $driverName = null;

    /**
     * Desteklenmeyen işlemin adı.
     */
    protected ?string $operationName = null;

    /**
     * Yeni bir Desteklenmeyen İşlem exception instance'ı oluşturur.
     *
     * @param  string  $message  Desteklenmeyen işlemi ve onu desteklemeyen
     *                           gateway'i açıklayan exception mesajı
     * @param  int  $code  Exception kodu (varsayılan: 0)
     * @param  \Throwable|null  $previous  Exception zincirleme için önceki throwable
     */
    public function __construct(
        string $message = 'Bu işlem ödeme geçidi tarafından desteklenmiyor.',
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * İşlemi desteklemeyen driver adını ayarlar.
     *
     * @param  string  $driverName  Ödeme geçidi driver'ının adı
     * @return static Metot zincirleme için exception instance'ı döndürür
     */
    public function setDriverName(string $driverName): static
    {
        $this->driverName = $driverName;

        return $this;
    }

    /**
     * İşlemi desteklemeyen driver adını döndürür.
     *
     * @return string|null Driver adı, ayarlanmamışsa null
     */
    public function getDriverName(): ?string
    {
        return $this->driverName;
    }

    /**
     * Desteklenmeyen işlemin adını ayarlar.
     *
     * @param  string  $operationName  Başarısız olan işlemin adı
     *                                 (örn: 'refund', 'partial_refund', 'recurring')
     * @return static Metot zincirleme için exception instance'ı döndürür
     */
    public function setOperationName(string $operationName): static
    {
        $this->operationName = $operationName;

        return $this;
    }

    /**
     * Desteklenmeyen işlemin adını döndürür.
     *
     * @return string|null İşlem adı, ayarlanmamışsa null
     */
    public function getOperationName(): ?string
    {
        return $this->operationName;
    }
}
