<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay\DTO;

/**
 * Ödeme Yanıtı DTO
 *
 * Ödeme başlatma isteğinin sonucunu içerir.
 * Başarılı işlemlerde yönlendirme bilgilerini,
 * başarısız işlemlerde hata detaylarını barındırır.
 *
 * Türk banka sanal POS'larında 3D Secure akışı bir GET yönlendirmesi değil,
 * bankanın 3D geçidine yapılan bir HTML form POST'udur. Bu yüzden yanıt
 * ayrıca `formAction` + `formFields` alanlarını taşıyabilir.
 */
readonly class PaymentResponse
{
    /**
     * @param  bool  $success  Ödeme başlatmanın başarılı olup olmadığı
     * @param  string|null  $paymentId  Sağlayıcıdan alınan benzersiz ödeme tanımlayıcısı
     * @param  string|null  $redirectUrl  Müşteriyi ödemeyi tamamlamak için yönlendirme URL'i (GET akışı)
     * @param  string|null  $errorMessage  Başarısızlık durumunda okunabilir hata mesajı
     * @param  array<string, mixed>  $raw  Hata ayıklama için sağlayıcının ham yanıtı
     * @param  string|null  $formAction  3D Secure form POST hedefi (banka 3D geçidi)
     * @param  array<string, scalar>  $formFields  3D Secure formunun gizli alanları
     * @param  string  $formMethod  3D Secure formunun HTTP metodu
     * @param  string|null  $errorCode  Sağlayıcının hata kodu
     * @param  string|null  $htmlContent  Bankanın hazır döndüğü 3D HTML sayfası
     *                                    (Kuveyt Türk, iyzico gibi sağlayıcılar
     *                                    form alanları yerine tam sayfa döner)
     */
    public function __construct(
        public bool $success,
        public ?string $paymentId = null,
        public ?string $redirectUrl = null,
        public ?string $errorMessage = null,
        public array $raw = [],
        public ?string $formAction = null,
        public array $formFields = [],
        public string $formMethod = 'POST',
        public ?string $errorCode = null,
        public ?string $htmlContent = null,
    ) {}

    /**
     * Müşterinin bankaya POST edilmesi gereken bir 3D formu var mı?
     */
    public function requiresForm(): bool
    {
        return $this->htmlContent !== null
            || ($this->formAction !== null && $this->formFields !== []);
    }

    /**
     * 3D Secure formunu otomatik gönderilen bir HTML belgesi olarak üretir.
     *
     * Dönen HTML doğrudan tarayıcıya basılabilir:
     *
     *     return response($paymentResponse->toHtmlForm());
     *
     * @param  string  $formId  Oluşturulan form elemanının id'si
     */
    public function toHtmlForm(string $formId = 'anadolupay-3d-form'): string
    {
        // Banka hazır bir sayfa döndüyse onu olduğu gibi kullanırız.
        if ($this->htmlContent !== null) {
            return $this->htmlContent;
        }

        if (! $this->requiresForm()) {
            return '';
        }

        $inputs = '';

        foreach ($this->formFields as $name => $value) {
            $inputs .= sprintf(
                '<input type="hidden" name="%s" value="%s">',
                htmlspecialchars((string) $name, ENT_QUOTES, 'UTF-8'),
                htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'),
            );
        }

        $escapedId = htmlspecialchars($formId, ENT_QUOTES, 'UTF-8');

        return sprintf(
            '<!DOCTYPE html><html lang="tr"><head><meta charset="UTF-8">'
            .'<title>Yönlendiriliyorsunuz…</title></head>'
            .'<body onload="document.getElementById(\'%1$s\').submit();">'
            .'<form id="%1$s" method="%2$s" action="%3$s">%4$s'
            .'<noscript><button type="submit">Ödemeye devam et</button></noscript>'
            .'</form></body></html>',
            $escapedId,
            htmlspecialchars($this->formMethod, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars((string) $this->formAction, ENT_QUOTES, 'UTF-8'),
            $inputs,
        );
    }
}
