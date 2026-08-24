<?php

declare(strict_types=1);

use Voxyfy\AnadoluPay\Support\Bank\Currency;
use Voxyfy\AnadoluPay\Support\Bank\OrderStatus;

/**
 * Paylaşılan veri katmanına uygunluk.
 *
 * `data/` altındaki dosyalar github.com/Voxyfy/anadolupay-data
 * deposunun kopyasıdır ve Node ile Go paketlerindeki aynı adlı
 * dosyalarla birebir aynıdır; bkz. `data/README.md`. Bu testler
 * paketin yerel tablolarının o dosyalardan sapmasını engeller.
 *
 * Veri yükseltildiğinde dosyalar kopyalanır, bu testler kırılır ve
 * yerel tablolarla birlikte aşağıdaki sürüm sabiti de güncellenir.
 * Sabitin elle yükseltilmesi zorunlu: böylece veri değişikliği fark
 * edilmeden geçemez.
 */
const DATA_VERSION = '1.0.0';

/**
 * @return array<string, mixed>
 */
function sharedData(string $name): array
{
    $decoded = json_decode(
        (string) file_get_contents(dirname(__DIR__).'/data/'.$name),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    /** @var array<string, mixed> $decoded */
    return $decoded;
}

/**
 * Paylaşılan veriden sözlükleri, anahtarları sıralı biçimde okur.
 *
 * @return array<string, array<string, string>>
 */
function sharedDictionaries(): array
{
    /** @var array<string, array{note: string, codes: array<string, string>}> $providers */
    $providers = sharedData('order-status.json')['providers'];

    $dictionaries = [];

    foreach ($providers as $name => $provider) {
        $codes = $provider['codes'];
        ksort($codes);

        $dictionaries[$name] = $codes;
    }

    ksort($dictionaries);

    return $dictionaries;
}

/**
 * Bu paketin uyguladığı sözlükler, paylaşılan verideki adlarıyla.
 *
 * PHP sayısal dizgi anahtarlarını tam sayıya çevirir; JSON tarafında
 * aynı anahtarlar dizgidir. Karşılaştırma yapılabilmesi için anahtarlar
 * dizgiye normalleştirilir.
 *
 * @return array<string, array<string, string>>
 */
function localDictionaries(): array
{
    $dictionaries = [
        'NESTPAY' => OrderStatus::NESTPAY,
        'GARANTI' => OrderStatus::GARANTI,
        'BOA' => OrderStatus::BOA,
        'TOSLA' => OrderStatus::TOSLA,
        'PARAM' => OrderStatus::PARAM,
        'AKBANK' => OrderStatus::AKBANK,
        'IYZICO' => OrderStatus::IYZICO,
        'CRAFTGATE' => OrderStatus::CRAFTGATE,
        'MOKA' => OrderStatus::MOKA,
        'PARATIKA' => OrderStatus::PARATIKA,
    ];

    $normalized = [];

    foreach ($dictionaries as $name => $codes) {
        $stringKeyed = [];

        foreach ($codes as $code => $status) {
            $stringKeyed[(string) $code] = $status;
        }

        ksort($stringKeyed);

        $normalized[$name] = $stringKeyed;
    }

    ksort($normalized);

    return $normalized;
}

describe('paylaşılan veri: para birimleri', function () {
    it('beklenen veri sürümünü taşır', function () {
        expect(sharedData('currencies.json')['version'])->toBe(DATA_VERSION);
    });

    it('paylaşılan tabloyla birebir aynı', function () {
        /** @var array<string, string> $numeric */
        $numeric = sharedData('currencies.json')['numeric'];

        $local = [];

        foreach (Currency::supported() as $code) {
            $local[$code] = Currency::numeric($code);
        }

        ksort($local);
        ksort($numeric);

        expect($local)->toBe($numeric);
    });
});

describe('paylaşılan veri: durum sözlükleri', function () {
    it('beklenen veri sürümünü taşır', function () {
        expect(sharedData('order-status.json')['version'])->toBe(DATA_VERSION);
    });

    /*
     * Tüm sözlükler tek karşılaştırmada denetlenir: sapma olduğunda
     * çıktı hangi sağlayıcının hangi kodunun ayrıştığını doğrudan
     * gösterir.
     */
    it('paylaşılan veriyle birebir aynı', function () {
        expect(localDictionaries())->toBe(sharedDictionaries());
    });

    it('her kod paylaşılan veride bildirilen bir duruma eşleniyor', function () {
        $data = sharedData('order-status.json');

        /** @var list<string> $statuses */
        $statuses = $data['statuses'];

        $unknown = [];

        foreach (sharedDictionaries() as $name => $codes) {
            foreach ($codes as $code => $status) {
                if (! in_array($status, $statuses, true)) {
                    $unknown[] = "{$name}.{$code} => {$status}";
                }
            }
        }

        expect($unknown)->toBe([]);
    });
});
