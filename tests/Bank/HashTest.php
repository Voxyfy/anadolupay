<?php

declare(strict_types=1);

use Voxyfy\AnadoluPay\Gateways\Bank\AssecoGateway;
use Voxyfy\AnadoluPay\Gateways\Bank\GarantiGateway;
use Voxyfy\AnadoluPay\Gateways\Bank\InterPosGateway;
use Voxyfy\AnadoluPay\Gateways\Bank\KuveytPosGateway;
use Voxyfy\AnadoluPay\Gateways\Bank\PayForGateway;
use Voxyfy\AnadoluPay\Gateways\Bank\PosNetGateway;
use Voxyfy\AnadoluPay\Gateways\Bank\PosNetV1Gateway;
use Voxyfy\AnadoluPay\Gateways\Provider\AkbankPosGateway;
use Voxyfy\AnadoluPay\Gateways\Provider\ParamGateway;
use Voxyfy\AnadoluPay\Gateways\Provider\PayTrGateway;
use Voxyfy\AnadoluPay\Gateways\Provider\ToslaGateway;
use Voxyfy\AnadoluPay\Tests\Support\BankTestConfig;
use Voxyfy\AnadoluPay\Tests\Support\CallsProtected;

/**
 * İmza algoritmaları bu paketin en kritik parçasıdır: tek karakterlik bir
 * sapma bankanın işlemi reddetmesine yol açar. Bu yüzden her altyapının
 * imzası sabit girdilerle üretilmiş bir özet değerine kilitlenir.
 */
it('Asseco/NestPay ver3 imzasını üretir', function () {
    $gateway = BankTestConfig::make(AssecoGateway::class);

    $hash = CallsProtected::call($gateway, 'createHash', [
        'hashAlgorithm' => 'ver3',
        'clientid' => 'MERCHANT1',
        'storetype' => '3d',
        'amount' => '1.99',
        'oid' => 'ORDER-1',
        'okUrl' => 'https://shop.test/basarili',
        'failUrl' => 'https://shop.test/hata',
        'rnd' => 'FIXEDRND',
        'lang' => 'tr',
        'currency' => '949',
        'taksit' => '',
        'TranType' => 'Auth',
        'pan' => '4155650100416111',
        'Ecom_Payment_Card_ExpDate_Month' => '12',
        'Ecom_Payment_Card_ExpDate_Year' => '30',
        'cv2' => '123',
    ]);

    expect($hash)->toBe('DSDzpfa2hX5XSbXP2mwFbc+nt06xL3Q1PGD+id3T4VaXcIMAodg4j6UsVzfW5lg8Vuvk/PgKvQnoQ7eOyH+pbg==');
});

it('Asseco imzası hash ve encoding alanlarını dışarıda bırakır', function () {
    $gateway = BankTestConfig::make(AssecoGateway::class);

    $base = ['clientid' => 'MERCHANT1', 'oid' => 'ORDER-1'];

    expect(CallsProtected::call($gateway, 'createHash', $base))
        ->toBe(CallsProtected::call($gateway, 'createHash', $base + [
            'hash' => 'onemsiz',
            'encoding' => 'ISO-8859-9',
            'nationalidno' => '11111111111',
        ]));
});

it('Garanti güvenlik verisi ve 3D imzasını üretir', function () {
    $gateway = BankTestConfig::make(GarantiGateway::class);

    expect(CallsProtected::call($gateway, 'securityData', 'sales'))
        ->toBe('390D2F7144307B8871FC3835F5F04E6E5CBA4D48');

    $hash = CallsProtected::call($gateway, 'create3dHash', [
        'terminalid' => 'TERMINAL1',
        'orderid' => 'ORDER-1',
        'txnamount' => '199',
        'txncurrencycode' => '949',
        'successurl' => 'https://shop.test/basarili',
        'errorurl' => 'https://shop.test/hata',
        'txntype' => 'sales',
        'txninstallmentcount' => '',
    ]);

    expect($hash)->toBe('5158C1469C6FBA5E23C38DA872D2293511CC49F665192FCFB8253BAFFCB890EE4FF4A1E392EE850A67932141E2FC5FF3408D751A411FAFDC9B6E6B3DFFE4FA8B');
});

it('Garanti iade işlemlerinde iade şifresini kullanır', function () {
    $gateway = BankTestConfig::make(GarantiGateway::class);

    expect(CallsProtected::call($gateway, 'securityData', 'refund'))
        ->not->toBe(CallsProtected::call($gateway, 'securityData', 'sales'));
});

it('PayFor 3D imzasını üretir', function () {
    $gateway = BankTestConfig::make(PayForGateway::class);

    $hash = CallsProtected::call($gateway, 'create3dHash', [
        'MbrId' => '5',
        'OrderId' => 'ORDER-1',
        'PurchAmount' => '1.99',
        'OkUrl' => 'https://shop.test/basarili',
        'FailUrl' => 'https://shop.test/hata',
        'TxnType' => 'Auth',
        'InstallmentCount' => '0',
        'Rnd' => 'FIXEDRND',
    ]);

    expect($hash)->toBe('YAvJPAqgu4scObX9HLyjhrevhE0=');
});

it('InterPos 3D imzasını üretir', function () {
    $gateway = BankTestConfig::make(InterPosGateway::class);

    $hash = CallsProtected::call($gateway, 'create3dHash', [
        'ShopCode' => 'MERCHANT1',
        'OrderId' => 'ORDER-1',
        'PurchAmount' => '1.99',
        'OkUrl' => 'https://shop.test/basarili',
        'FailUrl' => 'https://shop.test/hata',
        'TxnType' => 'Auth',
        'InstallmentCount' => '',
        'Rnd' => 'FIXEDRND',
    ]);

    expect($hash)->toBe('o30LiuDBcaClU75VGW4ujNaHT2Q=');
});

it('PosNet güvenlik verisi ve mac değerini üretir', function () {
    $gateway = BankTestConfig::make(PosNetGateway::class);

    expect(CallsProtected::call($gateway, 'securityData'))
        ->toBe('ViB1IVNGWJZGlJtW2BHxnAhIO9wnApXFaiuZmD3kxaQ=');

    $mac = CallsProtected::call($gateway, 'hash', implode(';', [
        '1', 'ORDER-1', '199', '949', 'MERCHANT1',
        CallsProtected::call($gateway, 'securityData'),
    ]));

    expect($mac)->toBe('DS/uzZJ9BS0zmxURBoqwgVUxtoVHwbdXvYwVtrXSN54=');
});

it('PosNet V1 3D mac değerini üretir', function () {
    $gateway = BankTestConfig::make(PosNetV1Gateway::class);

    $mac = CallsProtected::call($gateway, 'create3dMac', [
        'MerchantNo' => 'MERCHANT1',
        'TerminalNo' => 'TERMINAL1',
        'CardNo' => '4155650100416111',
        'Cvv' => '123',
        'ExpiredDate' => '3012',
        'Amount' => '199',
    ]);

    expect($mac)->toBe('fBmfB729AZl3NrC+15Y9GvkS5LxtC0ZKmfb+cYTsX/c=');
});

it('Kuveyt Türk imzasını üretir', function () {
    $gateway = BankTestConfig::make(KuveytPosGateway::class);

    $hash = CallsProtected::call($gateway, 'createHash', [
        'MerchantId' => 'MERCHANT1',
        'MerchantOrderId' => 'ORDER-1',
        'Amount' => '199',
        'OkUrl' => 'https://shop.test/basarili',
        'FailUrl' => 'https://shop.test/hata',
        'UserName' => 'apiuser',
    ]);

    expect($hash)->toBe('JXr+yCnD54hNidPXavaRc8nzSu0=');
});

it('PayTR ödeme token değerini üretir', function () {
    $gateway = BankTestConfig::make(PayTrGateway::class);

    $token = CallsProtected::call($gateway, 'paymentToken', [
        'merchant_id' => 'MERCHANT1',
        'user_ip' => '88.10.20.30',
        'merchant_oid' => 'ORDER-1',
        'email' => 'ahmet@example.com',
        'payment_amount' => '1.99',
        'payment_type' => 'card',
        'installment_count' => '0',
        'currency' => 'TL',
        'test_mode' => '0',
        'non_3d' => '0',
    ]);

    expect($token)->toBe('1xU1vgGHrQTDsRFNoLOtsJR/+X474ZrPM4Gt8CuWL70=');
});

it('Tosla istek imzasını üretir', function () {
    $gateway = BankTestConfig::make(ToslaGateway::class);

    $hash = CallsProtected::call($gateway, 'createHash', [
        'clientId' => 'MERCHANT1',
        'apiUser' => 'apiuser',
        'rnd' => 'FIXEDRND',
        'timeSpan' => '20260101120000',
    ]);

    expect($hash)->toBe('WO6HVmpwKCHou7wxbWYHvYJvKlRMYUVeOszWDyaPyZx9gYukW/97FPiVXUgh3sBbds3eRqaDKGIYEPNjkwQcaQ==');
});

it('Akbank POS 3D imzasını üretir', function () {
    $gateway = BankTestConfig::make(AkbankPosGateway::class);

    $hash = CallsProtected::call($gateway, 'create3dHash', [
        'paymentModel' => '3D',
        'txnCode' => '3000',
        'merchantSafeId' => 'MERCHANT1',
        'terminalSafeId' => 'TERMINAL1',
        'orderId' => 'ORDER-1',
        'lang' => 'TR',
        'amount' => '1.99',
        'currencyCode' => '949',
        'installCount' => '1',
        'okUrl' => 'https://shop.test/basarili',
        'failUrl' => 'https://shop.test/hata',
        'creditCard' => '4155650100416111',
        'expiredDate' => '1230',
        'cvv' => '123',
        'randomNumber' => 'FIXEDRND',
        'requestDateTime' => '2026-01-01T12:00:00.000',
    ]);

    expect($hash)->toBe('SPb2w1iTYeQYEL8PxT2hgS5+9gxfuHgpUfRdIR1exqTuHWMgx5c0HrXp6/fvncQ6SJozA1qUpOMcWukw7BaRkA==');
});

it('Param istek imzasını ISO-8859-9 üzerinden üretir', function () {
    $gateway = BankTestConfig::make(ParamGateway::class);

    $hash = CallsProtected::call($gateway, 'createHash', [
        'G' => ['CLIENT_CODE' => 'MERCHANT1'],
        'GUID' => 'SECRETKEY',
        'Taksit' => '1',
        'Islem_Tutar' => '1,99',
        'Toplam_Tutar' => '1,99',
        'Siparis_ID' => 'ORDER-1',
    ], 'TP_WMD_UCD');

    expect($hash)->toBe('IiUBJwBitFJWiH4x3jTSYjYM/NE=');
});

describe('NestPay gerçek banka dönüşü', function () {
    /*
     * Ziraat'in NestPay test terminalinden 2026-08-09'da gelen gerçek 3D
     * dönüşü. Boş alanlar burada `null` — Laravel'in
     * `ConvertEmptyStringsToNull` middleware'inin bıraktığı hâl. Banka bu
     * alanları hash'e boş dizgi olarak kattığı için driver da öyle
     * saymalıdır; atlarsa imza hiçbir zaman tutmaz.
     */
    it('gerçek dönüşün ver3 hash’ini doğrular', function () {
        $payload = [
            'ACQBIN' => '454672',
            'amount' => '100',
            'callbackCall' => 'true',
            'cavv' => 'ABABBjRAAwAAACcQlJIhdUhABZc=',
            'cavvAlgorithm' => null,
            'checkout-id' => '190000300-56a6cc2b-f35c-41e9-bc70-b2b3c6546a4b',
            'clientid' => '190000300',
            'clientIp' => '88.231.133.14',
            'currency' => '949',
            'digest' => 'digest',
            'dsId' => '1',
            'eci' => '05',
            'Ecom_Payment_Card_ExpDate_Month' => '12',
            'Ecom_Payment_Card_ExpDate_Year' => '26',
            'ErrMsg' => null,
            'failUrl' => 'https://anadolupay-laravel.test/payment/callback',
            'HASH' => 'rnnuZX4e29FAwb/VZIcNAUyKEO7cuhjU0Pdu0ebWxkDq+xRBMua3Tn7f+DGz1OWEyaIq6cVI78M9TZNRV56wSQ==',
            'hashAlgorithm' => 'ver3',
            'iReqCode' => null,
            'iReqDetail' => null,
            'lang' => 'tr',
            'maskedCreditCard' => '4546 71** **** 7894',
            'MaskedPan' => '454671***7894',
            'md' => '454671:E49854A3405034CB5BD417C473652671B689BECD9DEB00D89991EFE378025021A83B7FFE86F4358B664750FB727ADE11:6393:',
            'mdErrorMsg' => 'Y-status/Challenge authentication via ACS: https://3ds-acs.test.modirum.com/mdpayacs/creq;token=377232591.1786302986.ZOH_KJOa__A',
            'mdStatus' => '1',
            'merchantID' => '190000300',
            'merchantName' => 'EMU Test',
            'oid' => 'TEST-VDCYPWVUBI',
            'okUrl' => 'https://anadolupay-laravel.test/payment/callback',
            'PAResSyntaxOK' => 'true',
            'paresTxStatus' => 'Y',
            'PAResVerified' => 'true',
            'payResults_dsId' => '1',
            'protocol' => '3DS2.2.0',
            'rnd' => 'vJmqS8eyrBJl5d/dwZqV',
            'sID' => '1',
            'storetype' => '3d',
            'taksit' => null,
            'TDS2_acsOperatorID' => '3DS_LOA_ACS_MOMD_020301_00793',
            'TDS2_acsReferenceNumber' => '3DS_LOA_ACS_MOMD_020301_00793',
            'TDS2_acsTransID' => '261e7dd8-0d4b-4d6c-b163-a7e4de688333',
            'TDS2_AResExtensions' => '[{"name":"Bridging","id":"A000000802-004","criticalityIndicator":false,"data":{"addData":{"authenticationMethod":["10"]},"version":"2.0"}}]',
            'TDS2_authenticationType' => '01',
            'TDS2_authTimestamp' => '202608091916',
            'TDS2_dsTransID' => 'd1835ce6-ee80-5c4d-8000-0000109491ed',
            'TDS2_RReqExtensions' => '[{"name":"Bridging","id":"A000000802-004","criticalityIndicator":false,"data":{"addData":{"authenticationMethod":["10"]},"version":"2.0"}}]',
            'TDS2_threeDSServerTransID' => 'e1a85018-d686-501f-8000-0000036e09f9',
            'TDS2_transStatus' => 'Y',
            'THREED_ID' => '26221TQTHAkhihr0055',
            'traceId' => '6a78d203138f7f11966455d30c78b187',
            'TRANID' => null,
            'TranType' => 'Auth',
            'tsl' => '1',
            'vendorCode' => null,
            'veresEnrolledStatus' => 'Y',
            'version' => '4.0',
            'xid' => 'wSpz43MzGED+TOlB4rJW4kNtIEs=',
        ];

        $gateway = BankTestConfig::make(AssecoGateway::class, [
            'merchant_id' => '190000300',
            'secret_key' => 'TEST1234',
        ]);

        expect(CallsProtected::call($gateway, 'checkCallbackHash', $payload))->toBeTrue();
    });

    it('değiştirilmiş dönüşü reddeder', function () {
        $payload = [
            'ACQBIN' => '454672',
            'amount' => '100',
            'callbackCall' => 'true',
            'cavv' => 'ABABBjRAAwAAACcQlJIhdUhABZc=',
            'cavvAlgorithm' => null,
            'checkout-id' => '190000300-56a6cc2b-f35c-41e9-bc70-b2b3c6546a4b',
            'clientid' => '190000300',
            'clientIp' => '88.231.133.14',
            'currency' => '949',
            'digest' => 'digest',
            'dsId' => '1',
            'eci' => '05',
            'Ecom_Payment_Card_ExpDate_Month' => '12',
            'Ecom_Payment_Card_ExpDate_Year' => '26',
            'ErrMsg' => null,
            'failUrl' => 'https://anadolupay-laravel.test/payment/callback',
            'HASH' => 'rnnuZX4e29FAwb/VZIcNAUyKEO7cuhjU0Pdu0ebWxkDq+xRBMua3Tn7f+DGz1OWEyaIq6cVI78M9TZNRV56wSQ==',
            'hashAlgorithm' => 'ver3',
            'iReqCode' => null,
            'iReqDetail' => null,
            'lang' => 'tr',
            'maskedCreditCard' => '4546 71** **** 7894',
            'MaskedPan' => '454671***7894',
            'md' => '454671:E49854A3405034CB5BD417C473652671B689BECD9DEB00D89991EFE378025021A83B7FFE86F4358B664750FB727ADE11:6393:',
            'mdErrorMsg' => 'Y-status/Challenge authentication via ACS: https://3ds-acs.test.modirum.com/mdpayacs/creq;token=377232591.1786302986.ZOH_KJOa__A',
            'mdStatus' => '1',
            'merchantID' => '190000300',
            'merchantName' => 'EMU Test',
            'oid' => 'TEST-VDCYPWVUBI',
            'okUrl' => 'https://anadolupay-laravel.test/payment/callback',
            'PAResSyntaxOK' => 'true',
            'paresTxStatus' => 'Y',
            'PAResVerified' => 'true',
            'payResults_dsId' => '1',
            'protocol' => '3DS2.2.0',
            'rnd' => 'vJmqS8eyrBJl5d/dwZqV',
            'sID' => '1',
            'storetype' => '3d',
            'taksit' => null,
            'TDS2_acsOperatorID' => '3DS_LOA_ACS_MOMD_020301_00793',
            'TDS2_acsReferenceNumber' => '3DS_LOA_ACS_MOMD_020301_00793',
            'TDS2_acsTransID' => '261e7dd8-0d4b-4d6c-b163-a7e4de688333',
            'TDS2_AResExtensions' => '[{"name":"Bridging","id":"A000000802-004","criticalityIndicator":false,"data":{"addData":{"authenticationMethod":["10"]},"version":"2.0"}}]',
            'TDS2_authenticationType' => '01',
            'TDS2_authTimestamp' => '202608091916',
            'TDS2_dsTransID' => 'd1835ce6-ee80-5c4d-8000-0000109491ed',
            'TDS2_RReqExtensions' => '[{"name":"Bridging","id":"A000000802-004","criticalityIndicator":false,"data":{"addData":{"authenticationMethod":["10"]},"version":"2.0"}}]',
            'TDS2_threeDSServerTransID' => 'e1a85018-d686-501f-8000-0000036e09f9',
            'TDS2_transStatus' => 'Y',
            'THREED_ID' => '26221TQTHAkhihr0055',
            'traceId' => '6a78d203138f7f11966455d30c78b187',
            'TRANID' => null,
            'TranType' => 'Auth',
            'tsl' => '1',
            'vendorCode' => null,
            'veresEnrolledStatus' => 'Y',
            'version' => '4.0',
            'xid' => 'wSpz43MzGED+TOlB4rJW4kNtIEs=',
        ];
        $payload['amount'] = '999';

        $gateway = BankTestConfig::make(AssecoGateway::class, [
            'merchant_id' => '190000300',
            'secret_key' => 'TEST1234',
        ]);

        expect(CallsProtected::call($gateway, 'checkCallbackHash', $payload))->toBeFalse();
    });
});
