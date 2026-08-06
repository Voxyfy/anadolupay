# AnadoluPay

[![Latest Version on Packagist](https://img.shields.io/packagist/v/voxyfy/anadolupay.svg?style=flat-square)](https://packagist.org/packages/voxyfy/anadolupay)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/voxyfy/anadolupay/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/voxyfy/anadolupay/actions?query=workflow%3Arun-tests+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/voxyfy/anadolupay.svg?style=flat-square)](https://packagist.org/packages/voxyfy/anadolupay)

Türk bankalarının sanal POS'ları ve ödeme kuruluşları için tek arayüzlü Laravel
ödeme soyutlaması. Her bankanın kendi hash algoritması, XML/JSON şeması ve 3D
Secure akışı vardır; AnadoluPay bunları tek bir `PaymentGatewayInterface`
arkasında toplar. Bankayı değiştirmek için yalnızca driver adını değiştirirsiniz.

Paket ödeme akışını yürütür ve yanıtları normalleştirir; arayüz render etme ve
iş kuralları (sipariş onayı, stok, fatura) uygulamada kalır.

## Gereksinimler

- PHP 8.2 veya üzeri
- Laravel 12.x veya 13.x

## Kurulum

```bash
composer require voxyfy/anadolupay
```

Laravel auto-discovery varsayılan olarak aktiftir; ek bir adım gerekmez.

```bash
php artisan vendor:publish --tag="anadolupay-config"
```

## Desteklenen bankalar ve altyapılar

Türkiye'deki sanal POS'lar birkaç ortak altyapı ailesine iner. Aşağıdaki her
satır `AnadoluPay::driver('<anahtar>')` ile çözümlenir.

### Bankalar

| Driver anahtarı | Banka | Altyapı | 3D Secure | 3D Pay | 3D Host | Non-secure | İade | İptal |
|---|---|---|:-:|:-:|:-:|:-:|:-:|:-:|
| `akbank` | Akbank | Asseco / Payten (NestPay) | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| `isbank` | Türkiye İş Bankası | Asseco / Payten | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| `ziraat` | Ziraat Bankası | Asseco / Payten | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| `halkbank` | Halkbank | Asseco / Payten | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| `qnb` | QNB Finansbank | Asseco / Payten | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| `teb` | TEB | Asseco / Payten | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| `sekerbank` | Şekerbank | Asseco / Payten | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| `garanti` | Garanti BBVA | Garanti VPOS (GVPS) | ✅ | ✅ | — | ✅ | ✅ | ✅ |
| `yapikredi` | Yapı Kredi | PosNet (XML) | ✅ | — | — | ✅ | ✅ | ✅ |
| `albaraka` | Albaraka Türk | PosNet V1 (JSON) | ✅ | — | ✅ | ✅ | ✅ | ✅ |
| `vakifbank` | VakıfBank | PayFlex V4 (MPI) | ✅ | — | — | ✅ | ✅ | ✅ |
| `ziraat-payflex` | Ziraat Bankası | PayFlex V4 (MPI) | ✅ | — | — | ✅ | ✅ | ✅ |
| `denizbank` | DenizBank | InterPos (Intertech) | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| `qnb-payfor` | QNB Finansbank / Enpara | PayFor | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| `ziraat-katilim` | Ziraat Katılım | PayFor | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| `kuveytturk` | Kuveyt Türk | BOA / TDV2.0 | ✅ | — | — | ✅ | — | — |
| `vakif-katilim` | Vakıf Katılım | BOA | ✅ | — | ✅ | ✅ | ✅ | ✅ |

### Ödeme kuruluşları

| Driver anahtarı | Kuruluş | Protokol | 3D Secure | 3D Pay | 3D Host | Non-secure | İade | İptal |
|---|---|---|:-:|:-:|:-:|:-:|:-:|:-:|
| `akbank-pos` | Akbank (yeni JSON API) | REST / JSON | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| `paytr` | PayTR | REST / form | — | ✅ | ✅ | ✅ | ✅ | — |
| `param` | Param (TURK Elektronik Para) | SOAP | ✅ | ✅ | — | ✅ | ✅ | ✅ |
| `tosla` | Tosla (AkÖde) | REST / JSON | — | ✅ | ✅ | ✅ | ✅ | ✅ |
| `iyzico` | iyzico | REST / JSON | ✅ | — | — | — | — | — |
| `fake` | — (geliştirme) | — | — | — | — | ✅ | ✅ | — |

Bir bankanın hem eski hem yeni altyapısı varsa ikisi ayrı anahtarla sunulur:
Akbank için `akbank` (NestPay) ve `akbank-pos` (yeni API), Ziraat için `ziraat`
(NestPay) ve `ziraat-payflex` (PayFlex), QNB için `qnb` (NestPay) ve
`qnb-payfor` (PayFor). Bankanızın hangisini tanımladığını sanal POS
sözleşmenizden teyit edin.

> **Sipay** bu sürümde yok. Protokolünün imza şeması güvenilir bir public
> kaynaktan doğrulanamadı; ödeme yoluna doğrulanmamış bir imza algoritması
> koymamak için implement edilmedi.

## Yapılandırma

Yalnızca kullandığınız bankanın değişkenlerini doldurmanız yeterlidir.
Alanların bankalara göre karşılığı:

| Config alanı | Bankadaki karşılığı |
|---|---|
| `merchant_id` | ClientId / MerchantId / ShopCode / merchantSafeId |
| `terminal_id` | TerminalId / TerminalNo / terminalSafeId |
| `username` | API kullanıcı adı (Name / UserCode / ProvUserID) |
| `password` | API şifresi |
| `secret_key` | 3D anahtarı (store key / hash key / GUID) |

Örnek `.env`:

```env
# Garanti BBVA
GARANTI_MERCHANT_ID=xxxxxxx
GARANTI_TERMINAL_ID=30690000
GARANTI_USERNAME=PROVAUT
GARANTI_PASSWORD=xxxxxxx
GARANTI_SECRET_KEY=xxxxxxx
GARANTI_REFUND_USERNAME=PROVRFN
GARANTI_REFUND_PASSWORD=xxxxxxx

# Akbank (NestPay)
AKBANK_MERCHANT_ID=xxxxxxx
AKBANK_USERNAME=xxxxxxx
AKBANK_PASSWORD=xxxxxxx
AKBANK_SECRET_KEY=xxxxxxx

# Yapı Kredi PosNet
YAPIKREDI_MERCHANT_ID=xxxxxxx
YAPIKREDI_TERMINAL_ID=xxxxxxx
YAPIKREDI_POSNET_ID=xxxxxxx
YAPIKREDI_SECRET_KEY=xxxxxxx
```

Tüm anahtarların listesi için yayınladığınız `config/anadolupay.php` dosyasına
bakın.

## Ödeme akışı

Türk banka sanal POS'larında 3D Secure bir GET yönlendirmesi değil, bankanın 3D
geçidine yapılan bir **form POST**'udur. Akış üç adımdır:

1. `createPayment()` imzalı form alanlarını üretir.
2. Form kullanıcının tarayıcısından bankaya POST edilir, kullanıcı doğrulama yapar.
3. Banka `successUrl`'inize POST eder; `verify()` hash'i doğrular ve gerekiyorsa
   provizyon isteğini gönderir.

### 1. Ödemeyi başlatma

```php
use Voxyfy\AnadoluPay\DTO\CardData;
use Voxyfy\AnadoluPay\DTO\CreatePaymentData;
use Voxyfy\AnadoluPay\Facades\AnadoluPay;

$data = new CreatePaymentData(
    amount: 199.90,
    currency: 'TRY',
    orderId: 'SIPARIS-123',
    customer: [
        'name' => 'Ahmet Yılmaz',
        'email' => 'ahmet@example.com',
        'phone' => '5551112233',
    ],
    successUrl: route('odeme.donus'),
    failUrl: route('odeme.donus'),
    card: new CardData(
        number: '5528790000000008',
        expireMonth: '12',
        expireYear: '2030',
        cvv: '123',
        holderName: 'Ahmet Yılmaz',
    ),
    installment: 1,
    paymentModel: CreatePaymentData::MODEL_3D_SECURE,
    ip: $request->ip(),
);

$response = AnadoluPay::driver('garanti')->createPayment($data);

// Bankaya otomatik POST eden HTML sayfası
return response($response->toHtmlForm());
```

`toHtmlForm()` kullanmak istemezseniz alanları kendiniz render edebilirsiniz:

```php
$response->formAction;  // bankanın 3D geçidi
$response->formMethod;  // 'POST'
$response->formFields;  // imzalı gizli alanlar
```

Kuveyt Türk, Vakıf Katılım ve Param gibi bazı sağlayıcılar form alanları yerine
hazır bir HTML sayfası döner; bu durumda `formFields` boştur ve içerik
`$response->htmlContent` içinde gelir. `toHtmlForm()` her iki durumu da doğru
şekilde ele alır, bu yüzden onu kullanmak en güvenlisidir.

### 2. Banka dönüşünü doğrulama

```php
use Voxyfy\AnadoluPay\DTO\VerifyPaymentData;

$result = AnadoluPay::driver('garanti')->verify(new VerifyPaymentData(
    payload: $request->all(),
    headers: $request->headers->all(),
    rawBody: $request->getContent(),
));

if ($result->success) {
    // $result->paymentId bankanın işlem referansıdır — saklayın,
    // iade/iptal için gerekecek.
}
```

`verify()` sırasıyla şunları yapar: dönüş hash'ini doğrular (eşleşmezse
`InvalidSignatureException`), 3D doğrulama durumunu (`mdStatus`) kontrol eder ve
klasik 3D Secure modelinde bankaya provizyon isteğini gönderir. 3D Pay / 3D Host
modellerinde provizyon banka tarafında tamamlandığı için ikinci istek atılmaz.

**Sipariş bağlamı gerektiren bankalar.** VakıfBank/Ziraat PayFlex, provizyon
isteğinde kart bilgisini ve sipariş tutarını yeniden ister; banka bunları
dönüşte göndermez. Bu bankalar için sipariş bağlamını siz sağlarsınız:

```php
$result = AnadoluPay::driver('vakifbank')->verify(new VerifyPaymentData(
    payload: $request->all(),
    order: [
        'id' => 'SIPARIS-123',
        'amount' => 199.90,
        'currency' => 'TRY',
        'installment' => 1,
        'ip' => $request->ip(),
        'card' => ['number' => '...', 'expire_month' => '12', 'expire_year' => '30', 'cvv' => '123'],
    ],
));
```

### 3. İade ve iptal

```php
use Voxyfy\AnadoluPay\DTO\RefundPaymentData;

// Tam iade
AnadoluPay::driver('akbank')->refund(new RefundPaymentData('SIPARIS-123'));

// Kısmi iade
AnadoluPay::driver('akbank')->refund(new RefundPaymentData('SIPARIS-123', 49.90));
```

Bazı bankalar iadeyi sipariş numarasıyla değil, orijinal işlemin banka
referansıyla eşler. Bu referansı `metadata` ile geçin:

```php
// Garanti: RetrefNum
new RefundPaymentData('SIPARIS-123', 49.90, metadata: ['ref_ret_num' => '...']);

// Yapı Kredi PosNet: hostLogKey
new RefundPaymentData('SIPARIS-123', 49.90, metadata: ['host_ref_num' => '...']);

// PayFlex: TransactionId
new RefundPaymentData('SIPARIS-123', 49.90, metadata: ['transaction_id' => '...']);

// Vakıf Katılım: bankanın OrderId'si
new RefundPaymentData('SIPARIS-123', 49.90, metadata: ['remote_order_id' => '...']);
```

Gün sonu öncesi iptal için driver'ların `cancel()` metodunu kullanın
(`PaymentGatewayInterface` dışında, banka driver'larına özeldir):

```php
AnadoluPay::driver('akbank')->cancel(new RefundPaymentData('SIPARIS-123'));
AnadoluPay::driver('garanti')->cancel(new RefundPaymentData('SIPARIS-123', metadata: ['ref_ret_num' => '...']));
```

## Ödeme modelleri

| Sabit | Anlamı |
|---|---|
| `MODEL_3D_SECURE` | Kimlik doğrulama sonrası ayrı bir provizyon isteği atılır (en yaygın) |
| `MODEL_3D_PAY` | Doğrulama ve provizyon tek adımda banka tarafında yapılır |
| `MODEL_3D_HOST` | Kart bilgileri de dahil tüm form banka sayfasında toplanır — kart verisi sitenize hiç uğramaz |
| `MODEL_NON_SECURE` | 3D doğrulaması olmadan doğrudan provizyon |

3D Host modelinde `card` vermeniz gerekmez.

## Taksit

```php
new CreatePaymentData(..., installment: 6);
```

Taksit alanının biçimi bankadan bankaya değişir (NestPay tek çekimde boş dizgi,
PosNet `'00'`, PayFor `'0'` bekler); driver'lar bunu kendi içinde normalleştirir.

## Test ortamı

Preset'lerdeki uç noktalar canlı ortamı gösterir. Test için `.env`'de ilgili
`*_PAYMENT_API` / `*_GATEWAY_3D` değişkenlerini bankanızın test adresiyle
değiştirin ve `*_TEST_MODE=true` yapın:

```env
GARANTI_TEST_MODE=true
GARANTI_PAYMENT_API=https://sanalposprovtest.garantibbva.com.tr/VPServlet
GARANTI_GATEWAY_3D=https://sanalposprovtest.garantibbva.com.tr/servlet/gt3dengine

AKBANK_PAYMENT_API=https://entegrasyon.asseco-see.com.tr/fim/api
AKBANK_GATEWAY_3D=https://entegrasyon.asseco-see.com.tr/fim/est3Dgate

YAPIKREDI_PAYMENT_API=https://setmpos.ykb.com/PosnetWebService/XML
YAPIKREDI_GATEWAY_3D=https://setmpos.ykb.com/3DSWebService/YKBPaymentService

VAKIFBANK_PAYMENT_API=https://onlineodemetest.vakifbank.com.tr:4443/VposService/v3/Vposreq.aspx
VAKIFBANK_GATEWAY_3D=https://3dsecuretest.vakifbank.com.tr:4443/MPIAPI/MPI_Enrollment.aspx

DENIZBANK_PAYMENT_API=https://test.inter-vpos.com.tr/mpi/Default.aspx
DENIZBANK_GATEWAY_3D=https://test.inter-vpos.com.tr/mpi/Default.aspx

QNB_PAYFOR_PAYMENT_API=https://vpostest.qnb.com.tr/Gateway/XMLGate.aspx
QNB_PAYFOR_GATEWAY_3D=https://vpostest.qnb.com.tr/Gateway/Default.aspx

KUVEYTTURK_PAYMENT_API=https://boatest.kuveytturk.com.tr/boa.virtualpos.services/Home
ALBARAKA_PAYMENT_API=https://epostest.albarakaturk.com.tr/ALBMerchantService/MerchantJSONAPI.svc
TOSLA_PAYMENT_API=https://prepentegrasyon.tosla.com/api/Payment
PARAM_PAYMENT_API=https://test-dmz.param.com.tr/turkpos.ws/service_turkpos_test.asmx
AKBANK_POS_PAYMENT_API=https://apipre.akbank.com/api/v1/payment/virtualpos
```

Geliştirme sırasında gerçek istek atmadan akışı denemek için `fake` driver'ını
kullanabilirsiniz.

> **Doğrulama notu.** Bu paketteki protokol implementasyonları bankaların public
> dokümantasyonuna göre yazılmış ve istek üretimi / hash / yanıt eşlemesi birim
> testleriyle kilitlenmiştir. Canlıya çıkmadan önce **her banka için kendi test
> üye işyeri bilgilerinizle uçtan uca bir işlem** yapın: hash algoritmalarında
> bankalar zaman zaman kuruluma özel farklılıklar tanımlayabiliyor.

## Webhook endpoint'i

Paket `/anadolupay/webhook/{driver}` rotasını kaydeder ve gelen isteği ilgili
driver'ın `verify()` metoduna verir. Kendi callback controller'ınızı yazmak
isterseniz yukarıdaki `verify()` örneğini kullanın.

PayTR bildirimi işlendiğinde yanıt gövdesinde düz metin `OK` bekler; PayTR için
kendi rotanızı yazıp bunu döndürmeniz gerekir.

## Güvenlik notları

- Kart verisi (`CardData`) asla loglanmamalı veya saklanmamalıdır. Loglama için
  `CardData::masked()` kullanın.
- `verify_hash` ayarını yalnızca bankanın hash'i tutarsız ürettiği bilinen
  kurulumlarda kapatın (varsayılan olarak yalnızca Ziraat Katılım'da kapalıdır).
- `verify_ssl` her zaman `true` kalmalıdır.

## Yeni bir banka eklemek

`AbstractBankGateway` sınıfını genişletin ve altı metodu implement edin:
`build3dFormFields()`, `checkCallbackHash()`, `is3dAuthSuccess()`,
`provision()`, `mapCallbackResponse()`, `mapProvisionResponse()`,
`extractOrderId()`. Ardından `config/anadolupay.php` içindeki `banks` dizisine
bir preset ekleyin. Akış, hata yönetimi ve HTTP katmanı temel sınıftan gelir.

## Testler

```bash
composer test
```

## Değişiklik Günlüğü

Son değişiklikler hakkında daha fazla bilgi için [CHANGELOG](CHANGELOG.md) dosyasına bakın.

## Katkıda Bulunma

Katkılarınızı bekliyoruz! Detaylar için [CONTRIBUTING](CONTRIBUTING.md) dosyasına bakın.

## Güvenlik Açıkları

Bir güvenlik açığı keşfederseniz, lütfen security@voxyfy.com adresine e-posta gönderin.

## Lisans

MIT Lisansı (MIT). Daha fazla bilgi için [Lisans Dosyası](LICENSE.md)'na bakın.
