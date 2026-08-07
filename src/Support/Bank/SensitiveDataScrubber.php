<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay\Support\Bank;

/**
 * Hassas Veri Temizleyici
 *
 * Banka istek ve yanıtlarını loglamadan önce kart numarası, CVV ve
 * kimlik bilgilerini maskeler. Log kayıtları PCI-DSS kapsamında kart
 * verisi barındıramaz; bu sınıf loglamanın kendisinin bir güvenlik
 * açığına dönüşmesini engeller.
 *
 * İki katmanlı çalışır:
 *   1. Anahtar adına göre (yapılandırılmış diziler için).
 *   2. Değerin biçimine göre (ham XML/JSON/form gövdeleri için) — kart
 *      numarası gibi görünen her dizgi, anahtarı ne olursa olsun maskelenir.
 */
final class SensitiveDataScrubber
{
    /** Değeri tamamen gizlenen alanlar. */
    private const REDACTED_KEYS = [
        'cvv', 'cvv2', 'cv2', 'cvc', 'cvc2', 'cardcvv2', 'cvv2val', 'kk_cvc',
        'password', 'userpass', 'merchantpassword', 'client_password',
        'clientpassword', 'hashpassword', 'secret_key', 'secretkey',
        'guid', 'apipass', 'apikey', 'api_key', 'merchant_key', 'merchant_salt',
    ];

    /** Değeri kart numarasıysa maskelenen alanlar. */
    private const PAN_KEYS = [
        'pan', 'cardnumber', 'card_number', 'cardno', 'card_no', 'ccno',
        'kk_no', 'creditcard', 'credit_card', 'number', 'cc_number',
    ];

    /** Gizlenen değerlerin yerine yazılan işaret. */
    private const REDACTED = '[gizlendi]';

    /**
     * İç içe diziyi temizler.
     *
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public function scrubArray(array $data): array
    {
        $scrubbed = [];

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $scrubbed[$key] = $this->scrubArray($value);

                continue;
            }

            if (! is_scalar($value)) {
                $scrubbed[$key] = $value;

                continue;
            }

            $scrubbed[$key] = $this->scrubValue((string) $key, (string) $value);
        }

        return $scrubbed;
    }

    /**
     * Ham gövdeyi (XML, JSON veya form-encoded) temizler.
     *
     * Gövde zaten kodlanmış olduğu için anahtar bazlı temizleme yapılamaz;
     * bunun yerine kart numarası biçimindeki tüm diziler maskelenir ve
     * bilinen hassas alanlar biçimden bağımsız olarak gizlenir.
     */
    public function scrubBody(string $body): string
    {
        if ($body === '') {
            return $body;
        }

        $body = $this->redactKnownKeys($body);

        return $this->maskCardNumbers($body);
    }

    /**
     * Kart numarasını maskeler: ilk 6 ve son 4 hane korunur.
     */
    public function maskPan(string $pan): string
    {
        $digits = preg_replace('/\D/', '', $pan) ?? '';
        $length = strlen($digits);

        if ($length < 12) {
            return str_repeat('*', $length);
        }

        return substr($digits, 0, 6).str_repeat('*', $length - 10).substr($digits, -4);
    }

    /**
     * Bir dizginin kart numarası olup olmadığını Luhn algoritmasıyla belirler.
     *
     * Yalnızca hane sayısına bakmak yetmez: PosNet'in 20 haneye doldurulmuş
     * sipariş numaraları veya tutar alanları da uzun sayı dizileridir.
     * Luhn kontrolü bu yanlış pozitifleri eler.
     */
    public function looksLikeCardNumber(string $value): bool
    {
        $value = trim($value);

        // Kart numaraları boşluk veya tire ile gruplanmış olabilir; başka
        // karakter içeren bir değer kart numarası değildir.
        if (preg_match('/^[\d\s-]+$/', $value) !== 1) {
            return false;
        }

        $digits = preg_replace('/\D/', '', $value) ?? '';
        $length = strlen($digits);

        if ($length < 13 || $length > 19) {
            return false;
        }

        return $this->passesLuhn($digits);
    }

    private function scrubValue(string $key, string $value): string
    {
        $normalized = strtolower($key);

        if (in_array($normalized, self::REDACTED_KEYS, true)) {
            return self::REDACTED;
        }

        if (in_array($normalized, self::PAN_KEYS, true) && $this->looksLikeCardNumber($value)) {
            return $this->maskPan($value);
        }

        // Anahtarı tanımasak bile kart numarası biçimindeki değeri maskeleriz.
        if ($this->looksLikeCardNumber($value)) {
            return $this->maskPan($value);
        }

        return $value;
    }

    /**
     * Bilinen hassas alanları XML, JSON ve form gövdelerinde gizler.
     */
    private function redactKnownKeys(string $body): string
    {
        $keys = implode('|', array_map(preg_quote(...), self::REDACTED_KEYS));

        $patterns = [
            // <Cvv>123</Cvv>
            '#<('.$keys.')>[^<]*</\1>#i' => '<$1>'.self::REDACTED.'</$1>',
            // "cvv":"123" veya "cvv": "123"
            '#"('.$keys.')"\s*:\s*"[^"]*"#i' => '"$1":"'.self::REDACTED.'"',
            // cvv=123&
            '#\b('.$keys.')=[^&\s]*#i' => '$1='.self::REDACTED,
        ];

        foreach ($patterns as $pattern => $replacement) {
            $body = preg_replace($pattern, $replacement, $body) ?? $body;
        }

        return $body;
    }

    /**
     * Gövdedeki kart numarası biçimindeki tüm sayı dizilerini maskeler.
     */
    private function maskCardNumbers(string $body): string
    {
        return preg_replace_callback(
            '/\b\d{13,19}\b/',
            fn (array $matches): string => $this->passesLuhn($matches[0])
                ? $this->maskPan($matches[0])
                : $matches[0],
            $body,
        ) ?? $body;
    }

    /**
     * Luhn (mod 10) kontrolü.
     */
    private function passesLuhn(string $digits): bool
    {
        if ($digits === '' || preg_match('/^\d+$/', $digits) !== 1) {
            return false;
        }

        $sum = 0;
        $double = false;

        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $digit = (int) $digits[$i];

            if ($double) {
                $digit *= 2;

                if ($digit > 9) {
                    $digit -= 9;
                }
            }

            $sum += $digit;
            $double = ! $double;
        }

        return $sum % 10 === 0;
    }
}
