<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay\Support\Bank;

use DOMDocument;
use DOMElement;
use SimpleXMLElement;
use Throwable;
use Voxyfy\AnadoluPay\Exceptions\PaymentFailedException;

/**
 * XML Yardımcısı
 *
 * Banka sanal POS'larının çoğu istek/yanıt gövdesini XML olarak taşır.
 * Bu sınıf iç içe dizileri XML'e, XML'i de dizilere çevirir.
 */
final class Xml
{
    /**
     * İç içe diziden XML belgesi üretir.
     *
     * Sayısal anahtarlı listeler, üst elemanın adıyla tekrar eden
     * kardeş elemanlara dönüştürülür.
     *
     * @param  array<array-key, mixed>  $data
     * @param  string  $root  Kök elemanın adı (örn: CC5Request)
     * @param  string  $encoding  Belge kodlaması (örn: ISO-8859-9, UTF-8)
     * @param  bool  $withDeclaration  XML bildirimi (<?xml …?>) eklensin mi
     */
    public static function encode(
        array $data,
        string $root,
        string $encoding = 'UTF-8',
        bool $withDeclaration = true,
    ): string {
        $document = new DOMDocument('1.0', $encoding);
        $document->formatOutput = false;

        $rootElement = $document->createElement($root);
        $document->appendChild($rootElement);

        self::appendChildren($document, $rootElement, $data);

        $xml = $withDeclaration
            ? $document->saveXML()
            : $document->saveXML($rootElement);

        if ($xml === false) {
            throw new PaymentFailedException('XML isteği oluşturulamadı.');
        }

        // DOMDocument çıktısı her zaman UTF-8'dir; banka ISO-8859-9 beklerse
        // gövdeyi bildirilen kodlamaya dönüştürmemiz gerekir.
        if (strtoupper($encoding) !== 'UTF-8') {
            $converted = @iconv('UTF-8', $encoding.'//TRANSLIT', $xml);

            if ($converted !== false) {
                $xml = $converted;
            }
        }

        return $xml;
    }

    /**
     * XML belgesini iç içe diziye çevirir.
     *
     * @return array<string, mixed>
     *
     * @throws PaymentFailedException Gövde geçerli XML değilse
     */
    public static function decode(string $xml): array
    {
        $xml = trim($xml);

        if ($xml === '') {
            throw new PaymentFailedException('Banka boş yanıt döndü.');
        }

        $previous = libxml_use_internal_errors(true);

        try {
            $element = new SimpleXMLElement($xml, LIBXML_NOCDATA | LIBXML_NOERROR | LIBXML_NOWARNING);
        } catch (Throwable $exception) {
            throw new PaymentFailedException(
                message: 'Banka geçersiz XML yanıtı döndü.',
                context: ['body' => mb_substr($xml, 0, 2000), 'error' => $exception->getMessage()],
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $decoded = self::elementToArray($element);

        return is_array($decoded) ? $decoded : ['value' => $decoded];
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private static function appendChildren(DOMDocument $document, DOMElement $parent, array $data): void
    {
        foreach ($data as $key => $value) {
            if ($value === null) {
                continue;
            }

            if (is_array($value) && $value !== [] && array_is_list($value)) {
                // Sayısal anahtarlı liste: aynı isimde tekrar eden elemanlar.
                foreach ($value as $item) {
                    self::appendChild($document, $parent, (string) $key, $item);
                }

                continue;
            }

            self::appendChild($document, $parent, (string) $key, $value);
        }
    }

    private static function appendChild(DOMDocument $document, DOMElement $parent, string $name, mixed $value): void
    {
        // Sayısal anahtarlar geçerli eleman adı olamaz; üst elemanın adını kullanırız.
        if (is_numeric($name)) {
            $name = $parent->tagName.'Item';
        }

        if (is_array($value)) {
            $child = $document->createElement($name);
            $parent->appendChild($child);
            self::appendChildren($document, $child, $value);

            return;
        }

        if (is_bool($value)) {
            $value = $value ? 'true' : 'false';
        }

        $child = $document->createElement($name);
        $child->appendChild($document->createTextNode((string) $value));
        $parent->appendChild($child);
    }

    /**
     * @return array<string, mixed>|string
     */
    private static function elementToArray(SimpleXMLElement $element): array|string
    {
        $children = [];

        foreach ($element->children() as $name => $child) {
            $value = self::elementToArray($child);

            if (isset($children[$name])) {
                if (! is_array($children[$name]) || ! array_is_list($children[$name])) {
                    $children[$name] = [$children[$name]];
                }

                $children[$name][] = $value;

                continue;
            }

            $children[$name] = $value;
        }

        foreach ($element->attributes() ?? [] as $name => $value) {
            $children['@'.$name] = (string) $value;
        }

        if ($children === []) {
            return trim((string) $element);
        }

        $text = trim((string) $element);

        if ($text !== '') {
            $children['#text'] = $text;
        }

        return $children;
    }
}
