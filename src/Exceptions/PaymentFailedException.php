<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay\Exceptions;

use Exception;

/**
 * Ödeme Başarısız Exception
 *
 * İşlem sırasında bir ödeme işlemi başarısız olduğunda fırlatılır.
 * Bu exception, ödeme oluşturma, doğrulama veya iade işlemleri
 * sırasında oluşan hataları kapsar ve başarısızlık hakkında
 * detaylı bilgi sağlar.
 *
 * Exception, ödeme sağlayıcısından gelen orijinal hata yanıtına
 * erişim sağlayan metotlar içerir, böylece detaylı hata yönetimi
 * ve loglama yapılabilir.
 *
 * Yaygın başarısızlık senaryoları:
 * - Kartta yetersiz bakiye
 * - Geçersiz kart bilgileri
 * - Kartı veren banka tarafından reddedilme
 * - Dolandırıcılık tespiti tetiklenmesi
 * - Ödeme sağlayıcısı ile ağ/bağlantı hataları
 * - Geçersiz işlem verisi
 * - Doğrulama/iade için süresi dolmuş veya geçersiz işlem
 *
 * @example
 * try {
 *     $sonuc = $gateway->createPayment($odemeVerisi);
 * } catch (PaymentFailedException $e) {
 *     $hataKodu = $e->getErrorCode();
 *     $saglaycıYanit = $e->getProviderResponse();
 *
 *     Log::error('Ödeme başarısız', [
 *         'mesaj' => $e->getMessage(),
 *         'kod' => $hataKodu,
 *         'saglayici_yanit' => $saglaycıYanit,
 *     ]);
 *
 *     // Hata koduna göre kullanıcı dostu mesaj göster
 *     return back()->withErrors(['odeme' => $e->getUserMessage()]);
 * }
 */
class PaymentFailedException extends Exception
{
    /**
     * Ödeme sağlayıcısı tarafından döndürülen hata kodu.
     */
    protected ?string $errorCode = null;

    /**
     * Ödeme sağlayıcısından gelen ham yanıt.
     *
     * @var array<string, mixed>
     */
    protected array $providerResponse = [];

    /**
     * Görüntülemeye uygun kullanıcı dostu hata mesajı.
     */
    protected ?string $userMessage = null;

    /**
     * Yeni bir Ödeme Başarısız exception instance'ı oluşturur.
     *
     * @param  string  $message  Başarısızlık nedenini açıklayan teknik exception mesajı
     * @param  int  $code  Exception kodu (varsayılan: 0)
     * @param  \Throwable|null  $previous  Exception zincirleme için önceki throwable
     */
    public function __construct(
        string $message = 'Ödeme işlenemedi.',
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Ödeme sağlayıcısından gelen hata kodunu ayarlar.
     *
     * @param  string  $errorCode  Sağlayıcıya özel hata kodu
     * @return static Metot zincirleme için exception instance'ı döndürür
     */
    public function setErrorCode(string $errorCode): static
    {
        $this->errorCode = $errorCode;

        return $this;
    }

    /**
     * Ödeme sağlayıcısından gelen hata kodunu döndürür.
     *
     * @return string|null Sağlayıcıya özel hata kodu, ayarlanmamışsa null
     */
    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    /**
     * Ödeme sağlayıcısından gelen ham yanıtı ayarlar.
     *
     * Hata ayıklama ve detaylı hata analizi için ödeme
     * sağlayıcısından gelen tam yanıtı saklar.
     *
     * @param  array<string, mixed>  $response  Sağlayıcının tam yanıt dizisi
     * @return static Metot zincirleme için exception instance'ı döndürür
     */
    public function setProviderResponse(array $response): static
    {
        $this->providerResponse = $response;

        return $this;
    }

    /**
     * Ödeme sağlayıcısından gelen ham yanıtı döndürür.
     *
     * @return array<string, mixed> Sağlayıcının tam yanıt dizisi
     */
    public function getProviderResponse(): array
    {
        return $this->providerResponse;
    }

    /**
     * Kullanıcı dostu hata mesajını ayarlar.
     *
     * Bu mesaj son kullanıcılara gösterilmeye uygun olmalı ve
     * hassas teknik detaylar veya sağlayıcıya özel kodlar içermemelidir.
     *
     * @param  string  $message  Görüntülemeye uygun kullanıcı dostu mesaj
     * @return static Metot zincirleme için exception instance'ı döndürür
     */
    public function setUserMessage(string $message): static
    {
        $this->userMessage = $message;

        return $this;
    }

    /**
     * Kullanıcı dostu hata mesajını döndürür.
     *
     * Son kullanıcılara gösterilmeye uygun bir mesaj döndürür.
     * Kullanıcı mesajı açıkça ayarlanmamışsa, genel bir mesaj döndürür.
     *
     * @return string Kullanıcı dostu hata mesajı
     */
    public function getUserMessage(): string
    {
        return $this->userMessage ?? 'Ödeme işlenemedi. Lütfen tekrar deneyin veya farklı bir ödeme yöntemi kullanın.';
    }

    /**
     * Sağlayıcı detaylarıyla yeni bir exception instance'ı oluşturur.
     *
     * Tek bir çağrıda tam yapılandırılmış exception oluşturmak için factory metodu.
     *
     * @param  string  $message  Teknik exception mesajı
     * @param  string|null  $errorCode  Sağlayıcıya özel hata kodu
     * @param  array<string, mixed>  $providerResponse  Ham sağlayıcı yanıtı
     * @param  string|null  $userMessage  Kullanıcı dostu mesaj
     * @param  \Throwable|null  $previous  Önceki throwable
     * @return static Tam yapılandırılmış exception instance'ı
     *
     * @example
     * throw PaymentFailedException::withDetails(
     *     'Kart, kartı veren banka tarafından reddedildi',
     *     'CARD_DECLINED',
     *     $apiYaniti,
     *     'Kartınız reddedildi. Lütfen farklı bir kart deneyin.'
     * );
     */
    public static function withDetails(
        string $message,
        ?string $errorCode = null,
        array $providerResponse = [],
        ?string $userMessage = null,
        ?\Throwable $previous = null
    ): static {
        $exception = new static($message, 0, $previous);

        if ($errorCode !== null) {
            $exception->setErrorCode($errorCode);
        }

        if (! empty($providerResponse)) {
            $exception->setProviderResponse($providerResponse);
        }

        if ($userMessage !== null) {
            $exception->setUserMessage($userMessage);
        }

        return $exception;
    }
}
