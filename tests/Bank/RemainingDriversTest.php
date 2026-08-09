<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Voxyfy\AnadoluPay\DTO\CapturePaymentData;
use Voxyfy\AnadoluPay\DTO\CreatePaymentData;
use Voxyfy\AnadoluPay\DTO\RecurringPlan;
use Voxyfy\AnadoluPay\DTO\RefundPaymentData;
use Voxyfy\AnadoluPay\DTO\StatusResponse;
use Voxyfy\AnadoluPay\DTO\VerifyPaymentData;
use Voxyfy\AnadoluPay\Exceptions\PaymentFailedException;
use Voxyfy\AnadoluPay\Gateways\Bank\PayFlexGateway;
use Voxyfy\AnadoluPay\Gateways\Bank\PosNetV1Gateway;
use Voxyfy\AnadoluPay\Gateways\Provider\AkbankPosGateway;
use Voxyfy\AnadoluPay\Gateways\Provider\ParamGateway;
use Voxyfy\AnadoluPay\Gateways\Provider\ToslaGateway;
use Voxyfy\AnadoluPay\Support\Money;
use Voxyfy\AnadoluPay\Tests\Support\BankTestConfig;
use Voxyfy\AnadoluPay\Tests\Support\CallsProtected;

describe('PosNet V1 (Albaraka)', function () {
    beforeEach(function () {
        $this->gateway = BankTestConfig::make(PosNetV1Gateway::class, [
            'extra' => ['posnet_id' => 'PN-1'],
        ]);
    });

    it('3D formunu MAC ile imzalar', function () {
        $fields = $this->gateway->createPayment(BankTestConfig::order(amount: 199.90))->formFields;

        expect($fields['MerchantNo'])->toBe('MERCHANT1')
            ->and($fields['PosnetID'])->toBe('PN-1')
            ->and($fields['TransactionType'])->toBe('Sale')
            // Tutar kuruş, sipariş numarası 20 haneye sıfırla doldurulur
            ->and($fields['Amount'])->toBe('19990')
            ->and($fields['OrderId'])->toBe('0000000000000ORDER-1')
            // Para birimi alfabetik kısaltma
            ->and($fields['CurrencyCode'])->toBe('TL')
            ->and($fields['UseOOS'])->toBe('0')
            ->and($fields['Mac'])->toMatch('#^[A-Za-z0-9+/]{43}=$#');
    });

    it('3D Host modelinde kart göndermez ve UseOOS=1 yapar', function () {
        $fields = $this->gateway->createPayment(
            BankTestConfig::order(paymentModel: CreatePaymentData::MODEL_3D_HOST)
        )->formFields;

        expect($fields['UseOOS'])->toBe('1')
            ->and($fields)->not->toHaveKey('CardNo')
            ->and($fields['MacParams'])->toBe('MerchantNo:TerminalNo:Amount');
    });

    it('posnet_id tanımlı değilse açık hata verir', function () {
        BankTestConfig::make(PosNetV1Gateway::class)->createPayment(BankTestConfig::order());
    })->throws(PaymentFailedException::class);

    it('çok uzun sipariş numarasını sessizce kesmez', function () {
        $this->gateway->createPayment(BankTestConfig::order())->formFields;

        $long = new CreatePaymentData(
            amount: 1.0,
            currency: 'TRY',
            orderId: str_repeat('X', 25),
            customer: [],
            successUrl: 'https://shop.test/ok',
            failUrl: 'https://shop.test/fail',
            card: BankTestConfig::card(),
        );

        $this->gateway->createPayment($long);
    })->throws(PaymentFailedException::class);

    it('provizyonu Sale ucuna gönderir', function () {
        Http::fake(['bank.test/*' => Http::response([
            'ServiceResponseData' => ['ResponseCode' => '00'],
            'ReferenceCode' => 'REF-1',
        ])]);

        $gateway = BankTestConfig::make(PosNetV1Gateway::class, [
            'extra' => ['posnet_id' => 'PN-1'],
            'verify_hash' => false,
        ]);

        $result = $gateway->verify(new VerifyPaymentData([
            'MdStatus' => '1',
            'OrderId' => '00000000000000ORDER-1',
            'SecureTransactionId' => 'STX-1',
            'CAVV' => 'CAVV-1',
            'ECI' => '05',
            'MD' => 'MD-1',
            'Amount' => '19990',
        ]));

        expect($result->success)->toBeTrue()
            ->and($result->paymentId)->toBe('REF-1');

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/Sale')
            && str_contains($request->body(), '"CavvData":"CAVV-1"'));
    });

    it('iadeyi Return, iptali Reverse ucuna gönderir', function () {
        Http::fake(['bank.test/*' => Http::response(['ServiceResponseData' => ['ResponseCode' => '00']])]);

        $this->gateway->refund(new RefundPaymentData('ORDER-1', 49.90));
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/Return'));

        $this->gateway->cancel(new RefundPaymentData('ORDER-1'));
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/Reverse'));
    });

    it('durum sorgusunu TransactionInquiry ucuna gönderir', function () {
        Http::fake(['bank.test/*' => Http::response([
            'ServiceResponseData' => ['ResponseCode' => '00'],
            'TransactionData' => [['TransactionType' => 'Sale', 'Amount' => 19990, 'ReferenceCode' => 'REF-1']],
        ])]);

        $status = $this->gateway->status('ORDER-1');

        expect($status->isPaid())->toBeTrue()
            ->and($status->amount?->minorUnits)->toBe(19990)
            ->and($status->paymentId)->toBe('REF-1');

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/TransactionInquiry'));
    });
});

describe('Tosla (AkÖde)', function () {
    beforeEach(function () {
        $this->gateway = BankTestConfig::make(ToslaGateway::class);
    });

    it('önce 3D oturumu açıp sonra form üretir', function () {
        Http::fake(['bank.test/*' => Http::response(['ThreeDSessionId' => 'SESS-1'])]);

        $response = $this->gateway->createPayment(BankTestConfig::order(amount: 199.90));

        expect($response->requiresForm())->toBeTrue()
            ->and($response->formAction)->toBe('https://bank.test/3d')
            ->and($response->formFields['ThreeDSessionId'])->toBe('SESS-1')
            ->and($response->formFields['CardNo'])->toBe('4155650100416111')
            ->and($response->formFields['ExpireDate'])->toBe('12/30');

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/threeDPayment')
            // Tosla tutarı kuruş cinsinden tam sayı olarak bekler
            && str_contains($request->body(), '"amount":19990'));
    });

    it('oturum açılamazsa açık hata verir', function () {
        Http::fake(['bank.test/*' => Http::response(['Message' => 'Geçersiz üye işyeri'])]);

        $this->gateway->createPayment(BankTestConfig::order());
    })->throws(PaymentFailedException::class, 'Geçersiz üye işyeri');

    it('3D Host modelinde yönlendirme URL’i döner', function () {
        Http::fake(['bank.test/*' => Http::response(['ThreeDSessionId' => 'SESS-2'])]);

        $response = $this->gateway->createPayment(
            BankTestConfig::order(paymentModel: CreatePaymentData::MODEL_3D_HOST)
        );

        expect($response->requiresForm())->toBeFalse()
            ->and($response->redirectUrl)->toBe('https://bank.test/3dhost/SESS-2');
    });

    it('ön provizyonu threeDPreAuth ucuna gönderir', function () {
        Http::fake(['bank.test/*' => Http::response(['ThreeDSessionId' => 'SESS-3'])]);

        $this->gateway->preAuthorize(BankTestConfig::order());

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/threeDPreAuth'));
    });

    it('dönüşte provizyon istemez; sonucu doğrudan eşler', function () {
        Http::fake();

        $gateway = BankTestConfig::make(ToslaGateway::class, ['verify_hash' => false]);

        $result = $gateway->verify(new VerifyPaymentData([
            'BankResponseCode' => '00',
            'OrderId' => 'ORDER-1',
            'TransactionId' => 'TX-1',
        ]));

        expect($result->success)->toBeTrue()
            ->and($result->paymentId)->toBe('TX-1');

        Http::assertNothingSent();
    });

    it('durum kodunu Tosla sözlüğüyle normalleştirir', function () {
        Http::fake(['bank.test/*' => Http::response([
            'RequestStatus' => 3, 'Amount' => 19990, 'TransactionId' => 'TX-1',
        ])]);

        // 3 = kısmi iade
        expect($this->gateway->status('ORDER-1')->isRefunded())->toBeTrue();
    });

    it('iade ve iptali ayrı uçlara gönderir', function () {
        Http::fake(['bank.test/*' => Http::response(['BankResponseCode' => '00', 'TransactionId' => 'RF-1'])]);

        $this->gateway->refund(new RefundPaymentData('ORDER-1', 49.90));
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/refund')
            && str_contains($request->body(), '"amount":4990'));

        $this->gateway->cancel(new RefundPaymentData('ORDER-1'));
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/void'));
    });

    /*
     * Alan adları gerçek Tosla yanıtından alınmıştır; önceki test uydurma
     * bir şema (`Installments` / `Count` / `TotalAmount`) varsaydığı için
     * yeşil kalıyor ama sorgu üretimde hiç sonuç döndürmüyordu.
     */
    it('BIN’siz taksit sorgusunda tutarları okur', function () {
        Http::fake(['bank.test/*' => Http::response([
            'IsExist' => true,
            'InstallmentOptions' => [
                ['Installment' => 1, 'Title' => 'Tek Çekim', 'Amount' => 10000, 'Currency' => 949],
                ['Installment' => 2, 'Title' => '2 Taksit', 'Amount' => 10101, 'Currency' => 949],
            ],
            'Code' => 0,
        ])]);

        $options = $this->gateway->installmentOptions(Money::fromMinorUnits(10000));

        expect($options)->toHaveCount(2)
            ->and($options[1]->count)->toBe(2)
            // Tutar kuruş cinsindendir.
            ->and($options[1]->totalPrice?->minorUnits)->toBe(10101);

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/GetInstallmentOptions'));
    });

    it('BIN’li sorguda komisyon oranlarını T2/T3 anahtarlarından çözer', function () {
        Http::fake(['bank.test/*' => Http::response([
            'CardPrefix' => 454671,
            'BankName' => 'Ziraat Bankası',
            'CommissionPackages' => [[
                'InstallmentRate' => [
                    'T2' => ['Rate' => 1.78, 'Constant' => 0.0],
                    'T3' => ['Rate' => 1.99, 'Constant' => 0.0],
                ],
                'BankCommission' => 1.44,
            ]],
            'Code' => 0,
        ])]);

        $options = $this->gateway->installmentOptions(Money::fromMinorUnits(10000), '4546711234567894');

        expect($options)->toHaveCount(2)
            ->and($options[0]->count)->toBe(2)
            ->and($options[0]->commissionRate)->toBe(1.78)
            ->and($options[0]->bankName)->toBe('Ziraat Bankası');

        // Tosla BIN'i altı haneli sayı olarak ister.
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/GetCommissionAndInstallmentInfo')
            && str_contains($request->body(), '"bin":454671'));
    });
});

describe('Param', function () {
    beforeEach(function () {
        $this->gateway = BankTestConfig::make(ParamGateway::class);
    });

    it('3D isteğini SOAP zarfıyla gönderir ve HTML döndürür', function () {
        Http::fake(['bank.test/*' => Http::response(
            '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body>'
            .'<TP_WMD_UCDResponse><TP_WMD_UCDResult><Sonuc>1</Sonuc>'
            .'<UCD_HTML>&lt;html&gt;3d&lt;/html&gt;</UCD_HTML>'
            .'</TP_WMD_UCDResult></TP_WMD_UCDResponse></soap:Body></soap:Envelope>'
        )]);

        $response = $this->gateway->createPayment(BankTestConfig::order(amount: 199.90));

        expect($response->success)->toBeTrue()
            ->and($response->htmlContent)->toBe('<html>3d</html>');

        Http::assertSent(function ($request) {
            $body = $request->body();

            return str_contains($body, '<soap:Envelope')
                && str_contains($body, '<TP_WMD_UCD')
                // Param tutarı virgüllü ondalıkla bekler
                && str_contains($body, '<Islem_Tutar>199,90</Islem_Tutar>')
                && str_contains($body, '<Islem_Hash>');
        });
    });

    it('3D Pay modelinde Pos_Odeme çağırır ve URL’e yönlendirir', function () {
        Http::fake(['bank.test/*' => Http::response(
            '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body>'
            .'<Pos_OdemeResponse><Pos_OdemeResult><Sonuc>1</Sonuc>'
            .'<UCD_URL>https://param.test/3d/abc</UCD_URL>'
            .'</Pos_OdemeResult></Pos_OdemeResponse></soap:Body></soap:Envelope>'
        )]);

        $response = $this->gateway->createPayment(
            BankTestConfig::order(paymentModel: CreatePaymentData::MODEL_3D_PAY)
        );

        expect($response->redirectUrl)->toBe('https://param.test/3d/abc');

        Http::assertSent(fn ($request) => str_contains($request->body(), '<Pos_Odeme'));
    });

    it('banka HTML döndürmezse hata verir', function () {
        Http::fake(['bank.test/*' => Http::response(
            '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body>'
            .'<TP_WMD_UCDResponse><TP_WMD_UCDResult><Sonuc>0</Sonuc>'
            .'<Sonuc_Str>Kart hatalı</Sonuc_Str></TP_WMD_UCDResult>'
            .'</TP_WMD_UCDResponse></soap:Body></soap:Envelope>'
        )]);

        $this->gateway->createPayment(BankTestConfig::order());
    })->throws(PaymentFailedException::class, 'Kart hatalı');

    it('ön provizyonu farklı SOAP metoduyla gönderir', function () {
        Http::fake(['bank.test/*' => Http::response(
            '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body>'
            .'<TP_Islem_Odeme_OnProv_WMDResponse><TP_Islem_Odeme_OnProv_WMDResult>'
            .'<UCD_HTML>&lt;html&gt;&lt;/html&gt;</UCD_HTML></TP_Islem_Odeme_OnProv_WMDResult>'
            .'</TP_Islem_Odeme_OnProv_WMDResponse></soap:Body></soap:Envelope>'
        )]);

        $this->gateway->preAuthorize(BankTestConfig::order());

        Http::assertSent(fn ($request) => str_contains($request->body(), '<TP_Islem_Odeme_OnProv_WMD'));
    });

    it('dönüş imzasını doğrular', function () {
        Http::fake();

        $payload = ['islemGUID' => 'G-1', 'md' => 'MD-1', 'mdStatus' => '0', 'orderId' => 'ORDER-1'];
        $payload['islemHash'] = base64_encode(hash('sha1', 'G-1MD-10ORDER-1SECRETKEY', true));

        $result = $this->gateway->verify(new VerifyPaymentData($payload));

        // mdStatus=0 → başarısız, provizyon istenmez
        expect($result->success)->toBeFalse();
        Http::assertNothingSent();
    });

    it('iade ve iptali Durum alanıyla ayırır', function () {
        Http::fake(['bank.test/*' => Http::response(
            '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body>'
            .'<TP_Islem_Iptal_Iade_Kismi2Response><TP_Islem_Iptal_Iade_Kismi2Result>'
            .'<Sonuc>1</Sonuc><Dekont_ID>D-1</Dekont_ID>'
            .'</TP_Islem_Iptal_Iade_Kismi2Result></TP_Islem_Iptal_Iade_Kismi2Response>'
            .'</soap:Body></soap:Envelope>'
        )]);

        $this->gateway->refund(new RefundPaymentData('ORDER-1', 49.90));
        Http::assertSent(fn ($request) => str_contains($request->body(), '<Durum>IADE</Durum>'));

        $this->gateway->cancel(new RefundPaymentData('ORDER-1'));
        Http::assertSent(fn ($request) => str_contains($request->body(), '<Durum>IPTAL</Durum>'));
    });
});

describe('PayFlex (VakıfBank / Ziraat)', function () {
    beforeEach(function () {
        $this->gateway = BankTestConfig::make(PayFlexGateway::class);
    });

    it('MPI kaydından ACS bilgilerini alıp forma çevirir', function () {
        // Gerçek yanıt zarfı: kök eleman IPaySecure.
        Http::fake(['bank.test/*' => Http::response(
            '<IPaySecure><Message><VERes><Status>Y</Status>'
            .'<PaReq>PAREQ-1</PaReq><ACSUrl>https://acs.test/auth</ACSUrl>'
            .'<TermUrl>https://shop.test/ok</TermUrl><MD>MD-1</MD>'
            .'</VERes></Message><MessageErrorCode>200</MessageErrorCode></IPaySecure>'
        )]);

        $response = $this->gateway->createPayment(BankTestConfig::order(amount: 199.90));

        // Form bankaya değil kartı çıkaran bankanın ACS adresine gider.
        expect($response->formAction)->toBe('https://acs.test/auth')
            ->and($response->formFields['PaReq'])->toBe('PAREQ-1')
            ->and($response->formFields['MD'])->toBe('MD-1');

        // Enrollment servisi `prmstr` içinde XML değil düz form alanı bekler;
        // XML gönderildiğinde alanları hiç okumadan "2030 Invalid expire date"
        // döner. VakıfBank sandbox'ında canlı istekle doğrulanmıştır.
        Http::assertSent(function ($request) {
            $body = urldecode($request->body());

            return ! str_contains($body, 'prmstr=')
                && str_contains($body, 'PurchaseAmount=199.90')
                && str_contains($body, 'ExpiryDate=3012');
        });
    });

    /**
     * VakıfBank'ın BKM "GO Güvenli Öde" kurulumunda PaReq, klasik bir 3DS
     * bloğu değil; kendi kendini gönderen bir HTML sayfasının base64'üdür.
     * Doğrulama sayfası ACSUrl'de değil, o sayfanın form hedefindedir —
     * ACSUrl'e POST edildiğinde banka "400 Hatalı İstek" sayfası döndürür.
     */
    it('PaReq bir HTML yönlendirme sayfasıysa içindeki formu kullanır', function () {
        $inner = "<html><body><form id='initThreeDFlow' action='https://go.test/troy/approve' method='post'>"
            ."<input type='hidden' name='goreq' value='GOREQ-1'/></form></body></html>";

        Http::fake(['bank.test/*' => Http::response(
            '<IPaySecure><Message><VERes><Status>Y</Status>'
            .'<PaReq>'.base64_encode($inner).'</PaReq>'
            .'<ACSUrl>https://acs.test/startThreeDFlow</ACSUrl><MD>MD-1</MD>'
            .'</VERes></Message></IPaySecure>'
        )]);

        $response = $this->gateway->createPayment(BankTestConfig::order());

        expect($response->formAction)->toBe('https://go.test/troy/approve')
            ->and($response->formFields)->toBe(['goreq' => 'GOREQ-1']);
    });

    it('PaReq klasik bir blokken ACSUrl kullanılmaya devam eder', function () {
        Http::fake(['bank.test/*' => Http::response(
            '<IPaySecure><Message><VERes><Status>Y</Status><PaReq>eJxVUttuwjAM</PaReq>'
            .'<ACSUrl>https://acs.test/auth</ACSUrl><TermUrl>https://shop.test/ok</TermUrl><MD>MD-1</MD>'
            .'</VERes></Message></IPaySecure>'
        )]);

        $response = $this->gateway->createPayment(BankTestConfig::order());

        expect($response->formAction)->toBe('https://acs.test/auth')
            ->and(array_keys($response->formFields))->toBe(['PaReq', 'TermUrl', 'MD']);
    });

    it('alt bayi tanımlı değilse MerchantType alanını hiç göndermez', function () {
        Http::fake(['bank.test/*' => Http::response(
            '<IPaySecure><Message><VERes><Status>Y</Status><ACSUrl>https://acs.test/a</ACSUrl>'
            .'</VERes></Message></IPaySecure>'
        )]);

        $this->gateway->createPayment(BankTestConfig::order());

        // Banka yalnızca 1 ve 2 değerlerini tanır; eski varsayılan "0" geçersizdi.
        Http::assertSent(fn ($request) => ! str_contains(urldecode($request->body()), 'MerchantType'));
    });

    it('kart 3D programına dahil değilse açık hata verir', function () {
        Http::fake(['bank.test/*' => Http::response(
            '<VposEnrollmentResponse><Message><VERes><Status>N</Status></VERes></Message></VposEnrollmentResponse>'
        )]);

        $this->gateway->createPayment(BankTestConfig::order());
    })->throws(PaymentFailedException::class, 'Kart 3-D Secure programına dâhil değil.');

    it('provizyon için sipariş bağlamı zorunludur', function () {
        $this->gateway->verify(new VerifyPaymentData(['Status' => 'Y', 'Cavv' => 'C-1']));
    })->throws(PaymentFailedException::class);

    it('sipariş bağlamıyla provizyonu tamamlar', function () {
        Http::fake(['bank.test/*' => Http::response(
            '<VposResponse><ResultCode>0000</ResultCode><TransactionId>TX-1</TransactionId></VposResponse>'
        )]);

        $result = $this->gateway->verify(new VerifyPaymentData(
            payload: ['Status' => 'Y', 'Cavv' => 'CAVV-1', 'Eci' => '05', 'VerifyEnrollmentRequestId' => 'VER-1'],
            order: [
                'id' => 'ORDER-1',
                'amount' => 199.90,
                'currency' => 'TRY',
                'card' => ['number' => '4155650100416111', 'expire_month' => '12', 'expire_year' => '30', 'cvv' => '123'],
            ],
        ));

        expect($result->success)->toBeTrue()
            ->and($result->paymentId)->toBe('TX-1');

        Http::assertSent(fn ($request) => str_contains(urldecode($request->body()), '<TransactionType>Sale</TransactionType>')
            && str_contains(urldecode($request->body()), '<CAVV>CAVV-1</CAVV>'));
    });

    /**
     * 3D provizyonunda banka işlemi `MpiTransactionId` üzerinden bulur; kart
     * zorunlu değildir ve bazı kurulumlar gönderilmesini `1127` ile reddeder.
     * Kart istenmemesi, PAN'ı istekler arasında saklama zorunluluğunu kaldırır.
     */
    it('kart verilmemişse provizyonu kartsız gönderir', function () {
        Http::fake(['bank.test/*' => Http::response(
            '<VposResponse><ResultCode>0000</ResultCode><TransactionId>TX-1</TransactionId></VposResponse>'
        )]);

        $result = $this->gateway->verify(new VerifyPaymentData(
            payload: ['Status' => 'Y', 'Cavv' => 'CAVV-1', 'Eci' => '05', 'VerifyEnrollmentRequestId' => 'VER-1'],
            order: ['id' => 'ORDER-1'],
        ));

        expect($result->success)->toBeTrue();

        Http::assertSent(function ($request) {
            $body = urldecode($request->body());

            return str_contains($body, '<MpiTransactionId>VER-1</MpiTransactionId>')
                && ! str_contains($body, '<Pan>')
                && ! str_contains($body, '<Cvv>')
                && ! str_contains($body, '<CurrencyAmount>');
        });
    });

    it('3D doğrulaması başarısızsa provizyon istemez', function () {
        Http::fake();

        $result = $this->gateway->verify(new VerifyPaymentData(['Status' => 'N']));

        expect($result->success)->toBeFalse();
        Http::assertNothingSent();
    });

    it('kapama transaction_id olmadan çalışmaz', function () {
        $this->gateway->capture(new CapturePaymentData('ORDER-1', 99.90));
    })->throws(PaymentFailedException::class);

    /**
     * Search yanıtında tek bir durum alanı yoktur; durum bayraklardan
     * türetilir. Gövde, VakıfBank sandbox'ından alınan gerçek yanıttır.
     */
    it('durumu Search bayraklarından türetir', function (array $fields, string $expected) {
        $rows = '';

        foreach ([...[
            'PaymentTransactionId' => '10000611422',
            'TransactionType' => 'Sale',
            'Amount' => '10.50',
            'OrderId' => 'ORDER-1',
            'TransactionId' => 'TX-1',
            'PanMasked' => '43550840****0001',
            'HostDate' => '20260809234631',
            'ResultCode' => '0000',
        ], ...$fields] as $key => $value) {
            $rows .= "<{$key}>{$value}</{$key}>";
        }

        Http::fake(['bank.test/*' => Http::response(
            '<SearchResponse><ResponseInfo><ResponseCode>0000</ResponseCode></ResponseInfo>'
            ."<TransactionSearchResultInfo><TransactionSearchResultInfo>{$rows}"
            .'</TransactionSearchResultInfo></TransactionSearchResultInfo></SearchResponse>'
        )]);

        $status = $this->gateway->status('ORDER-1');

        expect($status->found)->toBeTrue()
            ->and($status->status)->toBe($expected)
            ->and($status->amount?->toDecimalString())->toBe('10.50')
            // Maskeli kart `PanMasked` alanında döner.
            ->and($status->maskedCardNumber)->toBe('43550840****0001');
    })->with([
        'satış' => [['IsCanceled' => 'false', 'IsRefunded' => 'false'], StatusResponse::STATUS_PAID],
        'iptal' => [['IsCanceled' => 'true'], StatusResponse::STATUS_CANCELLED],
        'teknik iptal' => [['IsReversed' => 'true'], StatusResponse::STATUS_CANCELLED],
        'tam iade' => [['IsRefunded' => 'true', 'TotalRefundAmount' => '10.50'], StatusResponse::STATUS_REFUNDED],
        // Kısmî iade sonrası işlem hâlâ tahsil edilmiş sayılır.
        'kısmî iade' => [['IsRefunded' => 'true', 'TotalRefundAmount' => '4.00'], StatusResponse::STATUS_PAID],
        'kapanmamış ön provizyon' => [['TransactionType' => 'Auth', 'IsCaptured' => 'false'], StatusResponse::STATUS_PRE_AUTHORIZED],
        'kapanmış ön provizyon' => [['TransactionType' => 'Auth', 'IsCaptured' => 'true'], StatusResponse::STATUS_PAID],
    ]);

    it('durum sorgusunu ayrı sorgu ucuna gönderir', function () {
        Http::fake(['bank.test/query' => Http::response(
            '<SearchResponse><TransactionSearchResultInfo><TransactionSearchResultInfo>'
            .'<ResultCode>0000</ResultCode><TransactionId>TX-1</TransactionId>'
            .'<Amount>199.90</Amount>'
            .'</TransactionSearchResultInfo></TransactionSearchResultInfo></SearchResponse>'
        )]);

        $status = $this->gateway->status('ORDER-1');

        expect($status->isPaid())->toBeTrue()
            ->and($status->amount?->minorUnits)->toBe(19990);
    });

    it('tekrarlayan ödemede bitiş tarihi zorunludur', function () {
        $order = new CreatePaymentData(
            amount: 49.90,
            currency: 'TRY',
            orderId: 'ABONE-1',
            customer: [],
            successUrl: 'https://shop.test/ok',
            failUrl: 'https://shop.test/fail',
            card: BankTestConfig::card(),
            metadata: ['recurring' => new RecurringPlan(
                1, RecurringPlan::FREQUENCY_MONTH, 12
            )],
        );

        $this->gateway->createPayment($order);
    })->throws(PaymentFailedException::class, 'bitiş tarihi');

    it('durum sorgusu tanımadığı kodu unknown döner', function () {
        Http::fake(['bank.test/query' => Http::response('<SearchResponse></SearchResponse>')]);

        expect($this->gateway->status('YOK')->status)->toBe(StatusResponse::STATUS_UNKNOWN);
    });
    /*
     * Tosla `timeSpan` alanını GMT+3'te ve en fazla 1 saat farkla kabul
     * eder; aksi hâlde genel bir `998 Validasyon Hatası` döner. Uygulama
     * UTC'de çalışırken `date()` kullanmak üç saat geride bir damga
     * üretiyordu ve **her istek** reddediliyordu.
     */
    it('zaman damgasını uygulamanın saat diliminden bağımsız üretir', function () {
        $onceki = date_default_timezone_get();
        date_default_timezone_set('UTC');

        try {
            $damga = CallsProtected::call(BankTestConfig::make(ToslaGateway::class), 'timeSpan');
        } finally {
            date_default_timezone_set($onceki);
        }

        $istanbul = new DateTimeImmutable('now', new DateTimeZone('Europe/Istanbul'));

        expect($damga)->toMatch('/^\d{14}$/')
            // Aynı dakikada üretildiği için ilk 12 hane eşleşmeli.
            ->and(substr($damga, 0, 12))->toBe($istanbul->format('YmdHi'))
            // UTC damgası olmadığını açıkça doğrula.
            ->and(substr($damga, 0, 12))->not->toBe(gmdate('YmdHi'));
    });
    /*
     * Tosla'nın gerçek test ortamından 2026-08-09'da gelen 3D dönüşü.
     * `BankResponseMessage` boş geldiği için `null`dur — Tosla bu alanı
     * hash'e boş dizgi olarak katar; atlanırsa imza tutmaz.
     */
    it('gerçek 3D dönüşünün imzasını doğrular', function () {
        $payload = [
            'ClientId' => '1000000494',
            'OrderId' => 'TEST-XMIGIWCM42',
            'MdStatus' => '1',
            'ThreeDSessionId' => 'PBD27BF79FCC74434AA09E42D548770D056FC34022525435C8A726520E8549468',
            'BankResponseCode' => '00',
            'BankResponseMessage' => null,
            'RequestStatus' => '1',
            'HashParameters' => 'ClientId,ApiUser,OrderId,MdStatus,BankResponseCode,BankResponseMessage,RequestStatus',
            'Hash' => 'k/59H7yIOeZXMm/SxuA1+81g0lrWqPdE9lXZ2Z6kR+clnCYAey8iUBxJ2UsQD+dBsiuRGl0N+39FUfB5m07tNg==',
        ];

        $gateway = BankTestConfig::make(ToslaGateway::class, [
            'merchant_id' => '1000000494',
            'username' => 'POS_ENT_Test_001',
            'secret_key' => 'POS_ENT_Test_001!*!*',
        ]);

        expect(CallsProtected::call($gateway, 'checkCallbackHash', $payload))->toBeTrue();

        // Tek alan değişince reddedilmeli.
        $payload['MdStatus'] = '0';

        expect(CallsProtected::call($gateway, 'checkCallbackHash', $payload))->toBeFalse();
    });
});

describe('Akbank Sanal POS', function () {
    beforeEach(function () {
        $this->gateway = BankTestConfig::make(AkbankPosGateway::class);
    });

    /**
     * Akbank iade ve iptali banka referansıyla (rrn) değil sipariş
     * numarasıyla eşler; yanlışı gönderilirse `VPS-1007 Orjinal İşlem
     * bulunamadı` döner. Bu yüzden ödeme yanıtı `paymentId` olarak sipariş
     * numarasını verir — sonraki işlem doğrudan onunla yapılabilsin diye.
     * Akbank test ortamında canlı olarak doğrulandı.
     */
    it('ödeme yanıtında sipariş numarasını paymentId olarak verir', function () {
        Http::fake(['bank.test/*' => Http::response([
            'responseCode' => 'VPS-0000',
            'transaction' => ['rrn' => '622326253970', 'authCode' => '392237'],
        ])]);

        $response = $this->gateway->createPayment(
            BankTestConfig::order(paymentModel: CreatePaymentData::MODEL_NON_SECURE)
        );

        expect($response->success)->toBeTrue()
            ->and($response->paymentId)->toBe('ORDER-1');
    });

    it('iadede tutar verilmezse siparişten kalanı hesaplar', function () {
        Http::fake(['bank.test/*' => Http::sequence()
            // İşlem geçmişi: 11.00 satış, 4.00 iade → kalan 7.00
            ->push([
                'responseCode' => 'VPS-0000',
                'txnDetailList' => [
                    ['txnCode' => '1000', 'amount' => 11, 'responseCode' => 'VPS-0000'],
                    ['txnCode' => '1002', 'amount' => 4, 'responseCode' => 'VPS-0000'],
                    // Başarısız kayıt hesaba katılmamalı.
                    ['txnCode' => '1000', 'amount' => 50, 'responseCode' => 'VPS-1007'],
                ],
            ])
            ->push(['responseCode' => 'VPS-0000', 'transaction' => ['rrn' => 'R-1']]),
        ]);

        $result = $this->gateway->refund(new RefundPaymentData(paymentId: 'ORDER-1'));

        expect($result->success)->toBeTrue();

        // Tutarsız gönderilirse alan 0.00 gider ve banka "Hatalı Tutar" der.
        Http::assertSent(fn ($request) => ! str_contains($request->body(), '"amount":"0.00"'));
        Http::assertSent(fn ($request) => $request['txnCode'] !== '1002'
            || $request['transaction']['amount'] === '7.00');
    });

    it('kalan tutar yoksa açık hata verir', function () {
        Http::fake(['bank.test/*' => Http::response([
            'responseCode' => 'VPS-0000',
            'txnDetailList' => [
                ['txnCode' => '1000', 'amount' => 11, 'responseCode' => 'VPS-0000'],
                ['txnCode' => '1003', 'amount' => 11, 'responseCode' => 'VPS-0000'],
            ],
        ])]);

        $this->gateway->refund(new RefundPaymentData(paymentId: 'ORDER-1'));
    })->throws(PaymentFailedException::class);
});
