<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Voxyfy\AnadoluPay\DTO\CapturePaymentData;
use Voxyfy\AnadoluPay\DTO\CreatePaymentData;
use Voxyfy\AnadoluPay\DTO\RefundPaymentData;
use Voxyfy\AnadoluPay\Gateways\Bank\GarantiGateway;
use Voxyfy\AnadoluPay\Tests\Support\BankTestConfig;

/**
 * Bayi (alt üye işyeri) yapılandırmalı Garanti terminalleri her finansal
 * istekte bayi kodunu zorunlu tutar; gitmezse banka işlemi 0809 ile reddeder.
 * Bu testler alanın hangi akışlarda gövdeye girdiğini, imzayı bozmadığını ve
 * bayi olmayan terminallerde hiç görünmediğini kilitler.
 */
function garanti(?string $subMerchantId = null, ?string $path = null): GarantiGateway
{
    $extra = [];

    if ($subMerchantId !== null) {
        $extra['sub_merchant_id'] = $subMerchantId;
    }

    if ($path !== null) {
        $extra['sub_merchant_id_path'] = $path;
    }

    return BankTestConfig::make(GarantiGateway::class, $extra === [] ? [] : ['extra' => $extra]);
}

/** Bankanın başarılı provizyon yanıtı. */
function garantiResponse(): string
{
    return '<GVPSResponse><Transaction><Response><Code>00</Code></Response>'
        .'<RetrefNum>REF-1</RetrefNum></Transaction></GVPSResponse>';
}

describe('Garanti bayi kodu', function () {
    beforeEach(function () {
        Http::fake(['bank.test/*' => Http::response(garantiResponse())]);
    });

    it('3D’siz provizyona bayi kodunu Terminal düğümünde ekler', function () {
        garanti('BAYI-42')->createPayment(
            BankTestConfig::order(paymentModel: CreatePaymentData::MODEL_NON_SECURE)
        );

        // Banka dokümanı alanı Terminal düğümünde bekliyor; varsayılan yol bu.
        Http::assertSent(function ($request) {
            $terminal = (string) preg_replace('/.*<Terminal>(.*)<\/Terminal>.*/s', '$1', $request->body());

            return str_contains($terminal, '<SubMerchantID>BAYI-42</SubMerchantID>');
        });
    });

    it('provizyon kapamaya bayi kodunu ekler', function () {
        garanti('BAYI-42')->capture(new CapturePaymentData(
            'ORDER-1',
            149.90,
            metadata: ['ref_ret_num' => 'REF-1'],
        ));

        Http::assertSent(fn ($request) => str_contains($request->body(), '<SubMerchantID>BAYI-42</SubMerchantID>'));
    });

    it('iptal ve iade isteklerine bayi kodunu ekler', function () {
        $refund = new RefundPaymentData('ORDER-1', 19.90, metadata: ['ref_ret_num' => 'REF-1']);

        garanti('BAYI-42')->cancel($refund);
        garanti('BAYI-42')->refund($refund);

        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => str_contains($request->body(), '<SubMerchantID>BAYI-42</SubMerchantID>'));
    });

    it('durum sorgusuna bayi kodunu ekler', function () {
        garanti('BAYI-42')->status('ORDER-1');

        Http::assertSent(fn ($request) => str_contains($request->body(), '<SubMerchantID>BAYI-42</SubMerchantID>'));
    });

    it('3D form alanlarına bayi kodunu ekler', function () {
        $fields = garanti('BAYI-42')->createPayment(BankTestConfig::order())->formFields;

        expect($fields['submerchantid'])->toBe('BAYI-42')
            // Alan imzaya girmez: bayi kodu olan ve olmayan terminalin hash'i aynı.
            ->and($fields['secure3dhash'])
            ->toBe(garanti()->createPayment(BankTestConfig::order())->formFields['secure3dhash']);
    });

    it('XML imzasını değiştirmez', function () {
        $order = BankTestConfig::order(paymentModel: CreatePaymentData::MODEL_NON_SECURE);

        garanti('BAYI-42')->createPayment($order);
        garanti()->createPayment($order);

        $hashes = collect(Http::recorded())
            ->map(fn ($pair) => (string) preg_replace('/.*<HashData>(.*)<\/HashData>.*/s', '$1', $pair[0]->body()))
            ->unique();

        expect($hashes)->toHaveCount(1);
    });

    it('bayi kodu tanımlı değilken alanı hiç göndermez', function () {
        garanti()->createPayment(BankTestConfig::order(paymentModel: CreatePaymentData::MODEL_NON_SECURE));

        Http::assertSent(fn ($request) => ! str_contains($request->body(), 'SubMerchantID'));
    });

    it('boş bırakılmış bayi kodunu tanımsız sayar', function () {
        garanti('')->createPayment(BankTestConfig::order(paymentModel: CreatePaymentData::MODEL_NON_SECURE));

        Http::assertSent(fn ($request) => ! str_contains($request->body(), 'SubMerchantID'));
    });

    it('alanı yapılandırılan düğüme taşır', function () {
        garanti('BAYI-42', 'SubMerchantID')->createPayment(
            BankTestConfig::order(paymentModel: CreatePaymentData::MODEL_NON_SECURE)
        );

        Http::assertSent(function ($request) {
            $terminal = (string) preg_replace('/.*<Terminal>(.*)<\/Terminal>.*/s', '$1', $request->body());

            return str_contains($request->body(), '<SubMerchantID>BAYI-42</SubMerchantID>')
                && ! str_contains($terminal, 'SubMerchantID');
        });
    });
});
