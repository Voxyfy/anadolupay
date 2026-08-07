<?php

declare(strict_types=1);

use Voxyfy\AnadoluPay\AnadoluPay;
use Voxyfy\AnadoluPay\DTO\CardData;
use Voxyfy\AnadoluPay\DTO\PaymentResponse;
use Voxyfy\AnadoluPay\Exceptions\DriverNotFoundException;
use Voxyfy\AnadoluPay\Exceptions\PaymentFailedException;
use Voxyfy\AnadoluPay\Gateways\Bank\AbstractBankGateway;
use Voxyfy\AnadoluPay\Gateways\Bank\AssecoGateway;
use Voxyfy\AnadoluPay\Gateways\Bank\GarantiGateway;
use Voxyfy\AnadoluPay\Support\Bank\BankConfig;
use Voxyfy\AnadoluPay\Support\Bank\Currency;
use Voxyfy\AnadoluPay\Support\Bank\Xml;

it('banka preset anahtarından driver çözümler', function () {
    config()->set('anadolupay.banks.demo', [
        'gateway' => AssecoGateway::class,
        'merchant_id' => 'MERCHANT1',
        'endpoints' => ['payment_api' => 'https://bank.test/api'],
    ]);

    $gateway = app(AnadoluPay::class)->driver('demo');

    expect($gateway)->toBeInstanceOf(AssecoGateway::class)
        ->and($gateway->config()->merchantId)->toBe('MERCHANT1');
});

it('aynı driver’ı iki kez çözümlemez', function () {
    config()->set('anadolupay.banks.demo', [
        'gateway' => GarantiGateway::class,
        'endpoints' => ['payment_api' => 'https://bank.test/api'],
    ]);

    $manager = app(AnadoluPay::class);

    expect($manager->driver('demo'))->toBe($manager->driver('demo'));
});

it('tanımsız driver için mevcut anahtarları listeler', function () {
    config()->set('anadolupay.banks.garanti', ['gateway' => GarantiGateway::class]);

    try {
        app(AnadoluPay::class)->driver('yok-boyle-bir-banka');
    } catch (DriverNotFoundException $exception) {
        expect($exception->context['available_drivers'])
            ->toContain('garanti')
            ->toContain('fake');

        return;
    }

    $this->fail('DriverNotFoundException bekleniyordu.');
});

it('varsayılan yapılandırmadaki tüm banka preset’leri çözümlenebilir', function () {
    $banks = array_keys(config('anadolupay.banks', []));

    expect($banks)->not->toBeEmpty();

    foreach ($banks as $bank) {
        expect(app(AnadoluPay::class)->driver($bank))
            ->toBeInstanceOf(AbstractBankGateway::class);
    }
});

it('eksik uç nokta için açıklayıcı hata verir', function () {
    $config = new BankConfig(bank: 'demo');

    $config->endpoint('payment_api');
})->throws(PaymentFailedException::class, "'demo' bankası için 'payment_api' uç noktası tanımlı değil.");

it('eksik kimlik alanlarını tek seferde raporlar', function () {
    $config = new BankConfig(bank: 'demo', merchantId: 'M1');

    try {
        $config->require(['merchantId', 'terminalId', 'secretKey']);
    } catch (PaymentFailedException $exception) {
        expect($exception->getMessage())
            ->toContain('terminalId')
            ->toContain('secretKey')
            ->not->toContain('merchantId,');

        return;
    }

    $this->fail('PaymentFailedException bekleniyordu.');
});

it('para birimlerini ISO 4217 sayısal koda çevirir', function () {
    expect(Currency::numeric('TRY'))->toBe('949')
        ->and(Currency::numeric('try'))->toBe('949')
        ->and(Currency::numeric('USD'))->toBe('840')
        ->and(Currency::numeric('EUR'))->toBe('978');
});

it('desteklenmeyen para biriminde hata verir', function () {
    Currency::numeric('XYZ');
})->throws(PaymentFailedException::class);

it('iç içe diziyi XML’e çevirir', function () {
    $xml = Xml::encode([
        'Terminal' => ['ID' => 'T1', 'MerchantID' => 'M1'],
        'Order' => ['OrderID' => 'ORDER-1'],
        'Bos' => null,
    ], 'GVPSRequest');

    expect($xml)
        ->toContain('<GVPSRequest>')
        ->toContain('<Terminal><ID>T1</ID><MerchantID>M1</MerchantID></Terminal>')
        ->toContain('<OrderID>ORDER-1</OrderID>')
        // null değerler gövdeye yazılmaz
        ->not->toContain('<Bos');
});

it('XML’i iç içe diziye çevirir', function () {
    $decoded = Xml::decode(
        '<CC5Response><ProcReturnCode>00</ProcReturnCode>'
        .'<Extra><TRXDATE>20260101</TRXDATE></Extra></CC5Response>'
    );

    expect($decoded['ProcReturnCode'])->toBe('00')
        ->and($decoded['Extra']['TRXDATE'])->toBe('20260101');
});

it('geçersiz XML için hata verir', function () {
    Xml::decode('<bozuk');
})->throws(PaymentFailedException::class);

it('kart verisini farklı anahtar adlarından okur', function () {
    $card = CardData::fromArray([
        'card_number' => '4155 6501 0041 6111',
        'expire_month' => '1',
        'expire_year' => '2030',
        'cvc' => '123',
        'holder_name' => 'AHMET YILMAZ',
    ]);

    expect($card->number)->toBe('4155650100416111')
        ->and($card->expireMonth)->toBe('01')
        ->and($card->expireYearShort())->toBe('30')
        ->and($card->expireYearLong())->toBe('2030')
        ->and($card->expiry('my'))->toBe('0130')
        ->and($card->expiry('ym'))->toBe('3001')
        ->and($card->expiry('m/y'))->toBe('01/30')
        ->and($card->expiry('Ym'))->toBe('203001');
});

it('kart numarasını maskeler', function () {
    expect(CardData::fromArray([
        'number' => '4155650100416111',
        'expire_month' => '12',
        'expire_year' => '30',
        'cvv' => '123',
    ])->masked())->toBe('415565******6111');
});

it('eksik kart alanı için hata verir', function () {
    CardData::fromArray(['number' => '4155650100416111']);
})->throws(PaymentFailedException::class);

it('3D formunu otomatik gönderilen HTML olarak üretir', function () {
    $response = new PaymentResponse(
        success: true,
        formAction: 'https://bank.test/3d',
        formFields: ['oid' => 'ORDER-1', 'hash' => 'a"b<c'],
    );

    $html = $response->toHtmlForm();

    expect($html)
        ->toContain('action="https://bank.test/3d"')
        ->toContain('name="oid" value="ORDER-1"')
        // Alan değerleri kaçırılmalı, aksi halde form kırılır
        ->toContain('value="a&quot;b&lt;c"')
        ->toContain('.submit()');
});

it('banka hazır HTML döndürdüğünde onu olduğu gibi kullanır', function () {
    $response = new PaymentResponse(
        success: true,
        htmlContent: '<html>banka sayfası</html>',
    );

    expect($response->requiresForm())->toBeTrue()
        ->and($response->toHtmlForm())->toBe('<html>banka sayfası</html>');
});

it('sayısal para birimi kodunu alfabetik koda çevirir', function () {
    expect(Currency::alphabetic('949'))->toBe('TRY')
        // Kuveyt Türk kodu sıfırla doldurulmuş gönderir
        ->and(Currency::alphabetic('0949'))->toBe('TRY')
        ->and(Currency::alphabetic('840'))->toBe('USD')
        // Tanınmayan kod ham haliyle korunur, hata fırlatılmaz
        ->and(Currency::alphabetic('999'))->toBe('999');
});

it('XML özniteliklerini @ önekiyle yazar ve okur', function () {
    // Param SOAP istekleri ad alanını öznitelik olarak taşır; encode ve
    // decode simetrik olmazsa istek hiç oluşturulamaz.
    $xml = Xml::encode(['@xmlns' => 'https://turkpos.com.tr/', 'Siparis_ID' => 'ORDER-1'], 'TP_WMD_UCD', withDeclaration: false);

    expect($xml)->toBe('<TP_WMD_UCD xmlns="https://turkpos.com.tr/"><Siparis_ID>ORDER-1</Siparis_ID></TP_WMD_UCD>');
});

it('ad alanlı XML’i çözümler', function () {
    // SOAP yanıtlarında gövdenin tamamı `soap:` ad alanındadır; atlanırsa
    // yanıt boş görünür.
    $decoded = Xml::decode(
        '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">'
        .'<soap:Body><Sonuc>1</Sonuc></soap:Body></soap:Envelope>'
    );

    expect($decoded)->toBe(['Body' => ['Sonuc' => '1']]);
});
