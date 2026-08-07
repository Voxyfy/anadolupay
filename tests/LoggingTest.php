<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Psr\Log\AbstractLogger;
use Voxyfy\AnadoluPay\DTO\RefundPaymentData;
use Voxyfy\AnadoluPay\Gateways\Bank\AssecoGateway;
use Voxyfy\AnadoluPay\Support\Bank\BankHttpClient;
use Voxyfy\AnadoluPay\Support\Bank\SensitiveDataScrubber;

/**
 * Log kayıtlarını toplayan sahte logger.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    public function log($level, Stringable|string $message, array $context = []): void
    {
        $this->records[] = [
            'level' => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }

    /** Tüm kayıtların düz metin gösterimi. */
    public function dump(): string
    {
        return json_encode($this->records, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
    }
}

// Luhn geçerli bir test kartı; maskelemenin çalıştığını doğrulamak için.
const TEST_PAN = '4155650100416111';

describe('hassas veri maskeleme', function () {
    beforeEach(function () {
        $this->scrubber = new SensitiveDataScrubber;
    });

    it('kart numarasının ortasını maskeler', function () {
        expect($this->scrubber->maskPan(TEST_PAN))->toBe('415565******6111');
    });

    it('kart numarasını Luhn ile tanır', function () {
        expect($this->scrubber->looksLikeCardNumber(TEST_PAN))->toBeTrue()
            ->and($this->scrubber->looksLikeCardNumber('4155 6501 0041 6111'))->toBeTrue();
    });

    it('kart numarası olmayan uzun sayıları maskelemez', function () {
        // PosNet sipariş numaraları 20 haneye sıfırla doldurulur; bunlar
        // kart numarası değildir ve loglarda okunabilir kalmalıdır.
        expect($this->scrubber->looksLikeCardNumber('00000000000000000123'))->toBeFalse()
            // Luhn'dan geçmeyen 16 haneli bir sayı
            ->and($this->scrubber->looksLikeCardNumber('4155650100416112'))->toBeFalse()
            ->and($this->scrubber->looksLikeCardNumber('ORDER-1'))->toBeFalse();
    });

    it('XML gövdesindeki kart numarasını ve CVV’yi gizler', function () {
        $scrubbed = $this->scrubber->scrubBody(
            '<CC5Request><Number>'.TEST_PAN.'</Number>'
            .'<Cvv2Val>123</Cvv2Val><Password>gizli</Password>'
            .'<OrderId>ORDER-1</OrderId><Total>1.99</Total></CC5Request>'
        );

        expect($scrubbed)
            ->not->toContain(TEST_PAN)
            ->toContain('415565******6111')
            ->toContain('<Cvv2Val>[gizlendi]</Cvv2Val>')
            ->toContain('<Password>[gizlendi]</Password>')
            // Hata ayıklama için gereken alanlar korunmalı
            ->toContain('<OrderId>ORDER-1</OrderId>')
            ->toContain('<Total>1.99</Total>');
    });

    it('JSON gövdesindeki hassas alanları gizler', function () {
        $scrubbed = $this->scrubber->scrubBody(
            '{"cardNumber":"'.TEST_PAN.'","cvv":"123","orderId":"ORDER-1"}'
        );

        expect($scrubbed)
            ->not->toContain(TEST_PAN)
            ->toContain('"cvv":"[gizlendi]"')
            ->toContain('"orderId":"ORDER-1"');
    });

    it('form gövdesindeki hassas alanları gizler', function () {
        $scrubbed = $this->scrubber->scrubBody('pan='.TEST_PAN.'&cvv=123&oid=ORDER-1');

        expect($scrubbed)
            ->not->toContain(TEST_PAN)
            ->toContain('cvv=[gizlendi]')
            ->toContain('oid=ORDER-1');
    });

    it('iç içe dizideki hassas alanları gizler', function () {
        $scrubbed = $this->scrubber->scrubArray([
            'Order' => ['OrderID' => 'ORDER-1'],
            'Card' => ['Number' => TEST_PAN, 'CVV2' => '123'],
            'Terminal' => ['ID' => 'T1', 'Password' => 'gizli'],
        ]);

        expect($scrubbed['Card']['Number'])->toBe('415565******6111')
            ->and($scrubbed['Card']['CVV2'])->toBe('[gizlendi]')
            ->and($scrubbed['Terminal']['Password'])->toBe('[gizlendi]')
            ->and($scrubbed['Terminal']['ID'])->toBe('T1')
            ->and($scrubbed['Order']['OrderID'])->toBe('ORDER-1');
    });

    it('kart numarası olmayan Number alanına dokunmaz', function () {
        // Asseco provizyon isteğinde 'Number' alanı MD değerini taşır.
        $scrubbed = $this->scrubber->scrubArray(['Number' => 'MD-ABC-123']);

        expect($scrubbed['Number'])->toBe('MD-ABC-123');
    });
});

describe('istek loglama', function () {
    it('istek ve yanıtı kart verisi maskelenmiş olarak loglar', function () {
        Http::fake([
            'bank.test/*' => Http::response('<CC5Response><ProcReturnCode>00</ProcReturnCode></CC5Response>'),
        ]);

        $logger = new RecordingLogger;
        $client = new BankHttpClient(logger: $logger, bank: 'akbank');

        $client->postXml('https://bank.test/api', [
            'Number' => TEST_PAN,
            'Cvv2Val' => '123',
            'OrderId' => 'ORDER-1',
        ], 'CC5Request');

        $dump = $logger->dump();

        expect($logger->records)->toHaveCount(2)
            ->and($dump)->not->toContain(TEST_PAN)
            ->and($dump)->not->toContain('123<')
            ->and($dump)->toContain('415565******6111')
            ->and($dump)->toContain('ORDER-1')
            ->and($logger->records[0]['context']['bank'])->toBe('akbank')
            ->and($logger->records[1]['context']['status'])->toBe(200)
            ->and($logger->records[1]['context'])->toHaveKey('duration_ms');
    });

    it('başarısız HTTP yanıtını uyarı seviyesinde loglar', function () {
        Http::fake(['bank.test/*' => Http::response('sunucu hatası', 500)]);

        $logger = new RecordingLogger;
        $client = new BankHttpClient(logger: $logger, bank: 'garanti');

        try {
            $client->postXml('https://bank.test/api', ['OrderId' => 'ORDER-1'], 'GVPSRequest');
        } catch (Throwable) {
            // Hata bekleniyor; buradaki ilgi konusu log kaydı.
        }

        expect($logger->records[1]['level'])->toBe('warning')
            ->and($logger->records[1]['context']['status'])->toBe(500);
    });

    it('logger verilmediğinde hiçbir şey loglamaz', function () {
        Http::fake(['bank.test/*' => Http::response('<r><ProcReturnCode>00</ProcReturnCode></r>')]);

        $client = new BankHttpClient;

        // Logger yokluğunda istek yine de gönderilmeli.
        expect($client->postXml('https://bank.test/api', ['a' => 'b'], 'r'))
            ->toBe(['ProcReturnCode' => '00']);
    });

    it('form isteklerinde kart alanlarını maskeler', function () {
        Http::fake(['bank.test/*' => Http::response('ProcReturnCode=00')]);

        $logger = new RecordingLogger;
        $client = new BankHttpClient(logger: $logger, bank: 'denizbank');

        $client->postForm('https://bank.test/api', [
            'Pan' => TEST_PAN,
            'Cvv2' => '123',
            'OrderId' => 'ORDER-1',
        ]);

        expect($logger->dump())
            ->not->toContain(TEST_PAN)
            ->toContain('ORDER-1');
    });
});

describe('driver loglaması', function () {
    it('yapılandırma kapalıyken logger bağlamaz', function () {
        config()->set('anadolupay.logging.enabled', false);

        $gateway = AssecoGateway::forBank('akbank', [
            'merchant_id' => 'MERCHANT1',
            'username' => 'apiuser',
            'password' => 'apipass',
            'endpoints' => ['payment_api' => 'https://bank.test/api'],
        ]);

        Http::fake(['bank.test/*' => Http::response('<r><ProcReturnCode>00</ProcReturnCode></r>')]);

        // Loglama kapalıyken istek sorunsuz tamamlanmalı.
        expect($gateway->refund(new RefundPaymentData('ORDER-1'))->success)->toBeTrue();
    });
});
