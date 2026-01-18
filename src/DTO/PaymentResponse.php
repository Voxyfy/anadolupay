<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay\DTO;

use Voxyfy\AnadoluPay\Enums\PaymentStatus;

/**
 * Ödeme Yanıtı DTO
 *
 * Ödeme işlemi başlatma isteğinin sonucunu içerir.
 * 3D Secure gerektiren işlemlerde yönlendirme bilgilerini,
 * başarısız işlemlerde hata detaylarını barındırır.
 *
 * @property-read bool $success İşlemin başarılı olup olmadığı
 * @property-read PaymentStatus $status Ödeme durumu
 * @property-read string|null $transactionId Sağlayıcının işlem ID'si
 * @property-read string|null $redirectUrl 3D Secure yönlendirme URL'i
 * @property-read string|null $htmlContent 3D Secure form HTML'i
 * @property-read bool $requiresRedirect Yönlendirme gerekip gerekmediği
 * @property-read array $providerResponse Sağlayıcının ham yanıtı
 * @property-read string|null $errorCode Hata kodu
 * @property-read string|null $errorMessage Hata mesajı
 */
readonly class PaymentResponse
{
    /**
     * Yeni bir PaymentResponse instance'ı oluşturur.
     *
     * @param bool $success İşlem başarılı mı
     * @param PaymentStatus $status Ödeme durumu
     * @param string|null $transactionId Sağlayıcının işlem tanımlayıcısı
     * @param string|null $redirectUrl 3D Secure yönlendirme URL'i
     * @param string|null $htmlContent 3D Secure form HTML içeriği
     * @param bool $requiresRedirect Yönlendirme gerekli mi
     * @param array<string, mixed> $providerResponse Sağlayıcının orijinal yanıtı
     * @param string|null $errorCode Hata kodu (başarısızlık durumunda)
     * @param string|null $errorMessage Hata mesajı (başarısızlık durumunda)
     */
    public function __construct(
        public bool $success,
        public PaymentStatus $status,
        public ?string $transactionId = null,
        public ?string $redirectUrl = null,
        public ?string $htmlContent = null,
        public bool $requiresRedirect = false,
        public array $providerResponse = [],
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
    ) {}

    /**
     * Başarılı bir yanıt oluşturur (3D Secure yönlendirmesi ile).
     *
     * @param string $transactionId İşlem ID'si
     * @param string $redirectUrl Yönlendirme URL'i
     * @param array<string, mixed> $providerResponse Sağlayıcı yanıtı
     * @return self
     */
    public static function redirect(
        string $transactionId,
        string $redirectUrl,
        array $providerResponse = [],
    ): self {
        return new self(
            success: true,
            status: PaymentStatus::AWAITING_3DS,
            transactionId: $transactionId,
            redirectUrl: $redirectUrl,
            requiresRedirect: true,
            providerResponse: $providerResponse,
        );
    }

    /**
     * Başarılı bir yanıt oluşturur (HTML form ile).
     *
     * @param string $transactionId İşlem ID'si
     * @param string $htmlContent Form HTML içeriği
     * @param array<string, mixed> $providerResponse Sağlayıcı yanıtı
     * @return self
     */
    public static function html(
        string $transactionId,
        string $htmlContent,
        array $providerResponse = [],
    ): self {
        return new self(
            success: true,
            status: PaymentStatus::AWAITING_3DS,
            transactionId: $transactionId,
            htmlContent: $htmlContent,
            requiresRedirect: true,
            providerResponse: $providerResponse,
        );
    }

    /**
     * Doğrudan başarılı bir ödeme yanıtı oluşturur.
     *
     * @param string $transactionId İşlem ID'si
     * @param array<string, mixed> $providerResponse Sağlayıcı yanıtı
     * @return self
     */
    public static function success(
        string $transactionId,
        array $providerResponse = [],
    ): self {
        return new self(
            success: true,
            status: PaymentStatus::COMPLETED,
            transactionId: $transactionId,
            providerResponse: $providerResponse,
        );
    }

    /**
     * Başarısız bir yanıt oluşturur.
     *
     * @param string $errorMessage Hata mesajı
     * @param string|null $errorCode Hata kodu
     * @param array<string, mixed> $providerResponse Sağlayıcı yanıtı
     * @return self
     */
    public static function failed(
        string $errorMessage,
        ?string $errorCode = null,
        array $providerResponse = [],
    ): self {
        return new self(
            success: false,
            status: PaymentStatus::FAILED,
            errorCode: $errorCode,
            errorMessage: $errorMessage,
            providerResponse: $providerResponse,
        );
    }

    /**
     * PaymentResponse'u array'e dönüştürür.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'status' => $this->status->value,
            'transaction_id' => $this->transactionId,
            'redirect_url' => $this->redirectUrl,
            'html_content' => $this->htmlContent,
            'requires_redirect' => $this->requiresRedirect,
            'provider_response' => $this->providerResponse,
            'error_code' => $this->errorCode,
            'error_message' => $this->errorMessage,
        ];
    }
}
