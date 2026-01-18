<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay\DTO;

use Voxyfy\AnadoluPay\Enums\RefundStatus;

/**
 * İade Yanıtı DTO
 *
 * İade işleminin sonucunu içerir. Başarılı iadelerde
 * iade referans bilgilerini, başarısız iadelerde
 * hata detaylarını barındırır.
 *
 * @property-read bool $success İşlemin başarılı olup olmadığı
 * @property-read RefundStatus $status İade durumu
 * @property-read string|null $refundId Sağlayıcının iade ID'si
 * @property-read string|null $transactionId Orijinal işlem ID'si
 * @property-read float|null $amount İade tutarı
 * @property-read string|null $currency Para birimi
 * @property-read array $providerResponse Sağlayıcının ham yanıtı
 * @property-read string|null $errorCode Hata kodu
 * @property-read string|null $errorMessage Hata mesajı
 */
readonly class RefundResponse
{
    /**
     * Yeni bir RefundResponse instance'ı oluşturur.
     *
     * @param  bool  $success  İşlem başarılı mı
     * @param  RefundStatus  $status  İade durumu
     * @param  string|null  $refundId  Sağlayıcının iade tanımlayıcısı
     * @param  string|null  $transactionId  Orijinal işlem ID'si
     * @param  float|null  $amount  İade tutarı
     * @param  string|null  $currency  Para birimi
     * @param  array<string, mixed>  $providerResponse  Sağlayıcının orijinal yanıtı
     * @param  string|null  $errorCode  Hata kodu (başarısızlık durumunda)
     * @param  string|null  $errorMessage  Hata mesajı (başarısızlık durumunda)
     */
    public function __construct(
        public bool $success,
        public RefundStatus $status,
        public ?string $refundId = null,
        public ?string $transactionId = null,
        public ?float $amount = null,
        public ?string $currency = null,
        public array $providerResponse = [],
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
    ) {}

    /**
     * Başarılı bir iade yanıtı oluşturur.
     *
     * @param  string  $refundId  İade ID'si
     * @param  string  $transactionId  Orijinal işlem ID'si
     * @param  float  $amount  İade tutarı
     * @param  string  $currency  Para birimi
     * @param  array<string, mixed>  $providerResponse  Sağlayıcı yanıtı
     */
    public static function success(
        string $refundId,
        string $transactionId,
        float $amount,
        string $currency = 'TRY',
        array $providerResponse = [],
    ): self {
        return new self(
            success: true,
            status: RefundStatus::COMPLETED,
            refundId: $refundId,
            transactionId: $transactionId,
            amount: $amount,
            currency: $currency,
            providerResponse: $providerResponse,
        );
    }

    /**
     * Başarısız bir iade yanıtı oluşturur.
     *
     * @param  string  $errorMessage  Hata mesajı
     * @param  string|null  $errorCode  Hata kodu
     * @param  string|null  $transactionId  Orijinal işlem ID'si (varsa)
     * @param  array<string, mixed>  $providerResponse  Sağlayıcı yanıtı
     */
    public static function failed(
        string $errorMessage,
        ?string $errorCode = null,
        ?string $transactionId = null,
        array $providerResponse = [],
    ): self {
        return new self(
            success: false,
            status: RefundStatus::FAILED,
            transactionId: $transactionId,
            errorCode: $errorCode,
            errorMessage: $errorMessage,
            providerResponse: $providerResponse,
        );
    }

    /**
     * Beklemede olan bir iade yanıtı oluşturur.
     * Bazı sağlayıcılar iadeyi asenkron olarak işler.
     *
     * @param  string  $refundId  İade ID'si
     * @param  string  $transactionId  Orijinal işlem ID'si
     * @param  float  $amount  İade tutarı
     * @param  string  $currency  Para birimi
     * @param  array<string, mixed>  $providerResponse  Sağlayıcı yanıtı
     */
    public static function pending(
        string $refundId,
        string $transactionId,
        float $amount,
        string $currency = 'TRY',
        array $providerResponse = [],
    ): self {
        return new self(
            success: true,
            status: RefundStatus::PENDING,
            refundId: $refundId,
            transactionId: $transactionId,
            amount: $amount,
            currency: $currency,
            providerResponse: $providerResponse,
        );
    }

    /**
     * RefundResponse'u array'e dönüştürür.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'status' => $this->status->value,
            'refund_id' => $this->refundId,
            'transaction_id' => $this->transactionId,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'provider_response' => $this->providerResponse,
            'error_code' => $this->errorCode,
            'error_message' => $this->errorMessage,
        ];
    }

    /**
     * İadenin tamamlanıp tamamlanmadığını kontrol eder.
     */
    public function isCompleted(): bool
    {
        return $this->status === RefundStatus::COMPLETED;
    }

    /**
     * İadenin beklemede olup olmadığını kontrol eder.
     */
    public function isPending(): bool
    {
        return $this->status === RefundStatus::PENDING || $this->status === RefundStatus::PROCESSING;
    }
}
