# AnadoluPay

[![Latest Version on Packagist](https://img.shields.io/packagist/v/voxyfy/anadolupay.svg?style=flat-square)](https://packagist.org/packages/voxyfy/anadolupay)
[![Tests](https://img.shields.io/github/actions/workflow/status/voxyfy/anadolupay/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/voxyfy/anadolupay/actions?query=workflow%3Arun-tests+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/voxyfy/anadolupay.svg?style=flat-square)](https://packagist.org/packages/voxyfy/anadolupay)

Türk bankalarının sanal POS'ları için tek arayüz.

Türkiye'de banka entegrasyonu yazmak, aynı işi on yedi kez farklı şekilde
yapmaktır. Garanti tutarı kuruş cinsinden tam sayı ister, NestPay ondalıklı
dizgi. Garanti hash'i ayraçsız birleştirip büyük harfe çevirir, NestPay alanları
sıralayıp `|` ile birleştirir, PosNet `;` kullanır. Kuveyt Türk size form
alanları değil hazır bir HTML sayfası döner. VakıfBank provizyon adımında kart
bilgisini bir daha ister. Bunların hiçbiri dokümantasyonda yan yana yazmaz;
her birini ayrı ayrı öğrenirsiniz.

Bu paket o farkları tek bir sözleşmenin arkasına alır:

```php
$response = AnadoluPay::driver('garanti')->createPayment($data);

return response($response->toHtmlForm());
```

Bankayı değiştirmek için `'garanti'` yerine `'akbank'` yazmak yeterlidir.

**Ne yapmaz:** Arayüz üretmez, sipariş durumu tutmaz, stok düşmez, fatura
kesmez. Ödeme akışını yürütür ve yanıtı normalleştirir; gerisi sizin
uygulamanızın işi.

---

> ### Canlıya çıkmadan önce okuyun
>
> Bu paketteki protokoller bankaların public dokümantasyonuna göre yazıldı ve
> istek üretimi, imza ve yanıt eşlemesi birim testleriyle kilitlendi. **Ancak
> hiçbir driver gerçek bir bankaya karşı çalıştırılmadı.**
>
> Testler benim yazdığım algoritmayı doğrular, bankanın beklediğini değil. Bir
> alanın sırası yanlışsa test yeşil kalır, banka işlemi reddeder. Kullanacağınız
> her banka için kendi test üye işyeri bilgilerinizle **en az bir 3D Secure satış
> ve bir iade** çalıştırın. Hash hesabında bankalar zaman zaman kuruluma özel
> farklılıklar tanımlıyor.
>
> `iyzico` driver'ının imza şeması ayrıca doğrulanmamıştır — bkz.
> [Yol haritası](#yol-haritası).

---

**İçindekiler**
[Kurulum](#kurulum) ·
[Desteklenen bankalar](#desteklenen-bankalar) ·
[Nasıl çalışır](#nasıl-çalışır) ·
[Yapılandırma](#yapılandırma) ·
[Ödeme akışı](#ödeme-akışı) ·
[İade ve iptal](#iade-ve-iptal) ·
[Ödeme modelleri](#ödeme-modelleri) ·
[Bankaların tuhaflıkları](#bankaların-tuhaflıkları) ·
[Loglama](#loglama) ·
[Test ortamı](#test-ortamı) ·
[Güvenlik](#güvenlik) ·
[Yeni banka eklemek](#yeni-banka-eklemek) ·
[Yol haritası](#yol-haritası)

## Kurulum

```bash
composer require voxyfy/anadolupay
php artisan vendor:publish --tag="anadolupay-config"
```

PHP 8.2+, Laravel 12 veya 13. Auto-discovery açıktır, ek adım yoktur.

## Desteklenen bankalar

Türkiye'deki sanal POS'lar birkaç ortak altyapı ailesine iner. Aynı aileyi
kullanan bankalar aynı driver'ı paylaşır; aralarındaki fark yalnızca uç nokta
ve kimlik bilgisidir.

| Driver | Banka | Altyapı | 3D | 3D Pay | 3D Host | Non-secure | İade | İptal |
|---|---|---|:-:|:-:|:-:|:-:|:-:|:-:|
| `akbank` | Akbank | Asseco / Payten | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| `isbank` | İş Bankası | Asseco / Payten | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| `ziraat` | Ziraat Bankası | Asseco / Payten | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| `halkbank` | Halkbank | Asseco / Payten | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| `qnb` | QNB Finansbank | Asseco / Payten | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| `teb` | TEB | Asseco / Payten | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| `sekerbank` | Şekerbank | Asseco / Payten | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| `garanti` | Garanti BBVA | GVPS | ✅ | ✅ | — | ✅ | ✅ | ✅ |
| `yapikredi` | Yapı Kredi | PosNet (XML) | ✅ | — | — | ✅ | ✅ | ✅ |
| `albaraka` | Albaraka Türk | PosNet V1 (JSON) | ✅ | — | ✅ | ✅ | ✅ | ✅ |
| `vakifbank` | VakıfBank | PayFlex V4 | ✅ | — | — | ✅ | ✅ | ✅ |
| `ziraat-payflex` | Ziraat Bankası | PayFlex V4 | ✅ | — | — | ✅ | ✅ | ✅ |
| `denizbank` | DenizBank | InterPos | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| `qnb-payfor` | QNB / Enpara | PayFor | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| `ziraat-katilim` | Ziraat Katılım | PayFor | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| `kuveytturk` | Kuveyt Türk | BOA / TDV2.0 | ✅ | — | — | ✅ | — | — |
| `vakif-katilim` | Vakıf Katılım | BOA | ✅ | — | ✅ | ✅ | ✅ | ✅ |

Ödeme kuruluşları:

| Driver | Kuruluş | 3D | 3D Pay | 3D Host | Non-secure | İade | İptal |
|---|---|:-:|:-:|:-:|:-:|:-:|:-:|
| `akbank-pos` | Akbank (yeni JSON API) | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| `paytr` | PayTR | — | ✅ | ✅ | ✅ | ✅ | — |
| `param` | Param | ✅ | ✅ | — | ✅ | ✅ | — |
| `tosla` | Tosla (AkÖde) | — | ✅ | ✅ | ✅ | ✅ | ✅ |
| `iyzico` | iyzico | ✅ | — | — | — | — | — |
| `fake` | geliştirme için sahte driver | — | — | — | ✅ | ✅ | — |

**Aynı bankanın iki driver'ı varsa** ikisi de gerçektir; hangisinin
tanımlandığını sanal POS sözleşmenizden teyit edin:

- Akbank → `akbank` (eski NestPay) veya `akbank-pos` (yeni JSON API)
- Ziraat → `ziraat` (NestPay) veya `ziraat-payflex` (PayFlex)
- QNB Finansbank → `qnb` (NestPay) veya `qnb-payfor` (PayFor)

## Nasıl çalışır

Bankalar arasındaki fark yüzeyde protokol (XML / JSON / SOAP / form), derinde
imza algoritmasıdır. Paket bu iki katmanı ayırır:

```
CreatePaymentData ─┐
                   ├─► AnadoluPay::driver('garanti')
CardData ──────────┘            │
                                ▼
                  ┌─────────────────────────────┐
                  │   AbstractBankGateway       │  ortak akış:
                  │   createPayment / verify    │  form → hash → provizyon
                  │   refund                    │
                  └──────────────┬──────────────┘
                                 │  banka-özel eşleme
          ┌──────────────────────┼──────────────────────┐
          ▼                      ▼                      ▼
   AssecoGateway          GarantiGateway         PosNetGateway   … (13 driver)
   sha512 + '|'           sha512 UPPER           sha256 + ';'
   CC5Request XML         GVPSRequest XML        posnetRequest XML
          │                      │                      │
          └──────────────────────┼──────────────────────┘
                                 ▼
                        BankHttpClient
              XML/JSON/form kodlama · maskeli loglama
```

Bir driver yalnızca yedi metodu doldurur: `build3dFormFields()`,
`checkCallbackHash()`, `is3dAuthSuccess()`, `provision()`,
`mapCallbackResponse()`, `mapProvisionResponse()`, `extractOrderId()`.

Akış kontrolü, hata yönetimi, HTTP ve loglama temel sınıfta tek yerde durur.
Bir bankada düzeltilen akış hatası hepsinde düzelir; bu, on yedi kopyanın
ayrı ayrı bakımını yapmaktan farkı.

Dönen `PaymentResponse` ve `VerificationResponse` bankadan bağımsızdır: hangi
driver'ı kullanırsanız kullanın `success`, `paymentId` ve `status` aynı anlama
gelir. Bankanın ham yanıtı `raw` içinde korunur — normalleştirme bilgi kaybı
yaratmaz.

## Yapılandırma

Yalnızca kullandığınız bankanın değişkenlerini doldurun. Diğer preset'ler boş
kalabilir; yalnızca çağrıldıklarında hata verirler.

Aynı kavramın bankalarda farklı adları var:

| Config | Bankadaki karşılığı |
|---|---|
| `merchant_id` | ClientId · MerchantId · ShopCode · merchantSafeId |
| `terminal_id` | TerminalId · TerminalNo · terminalSafeId |
| `username` | Name · UserCode · ProvUserID |
| `password` | API şifresi |
| `secret_key` | store key · hash key · GUID (3D anahtarı) |

```env
# Garanti BBVA
GARANTI_MERCHANT_ID=xxxxxxx
GARANTI_TERMINAL_ID=30690000
GARANTI_USERNAME=PROVAUT
GARANTI_PASSWORD=xxxxxxx
GARANTI_SECRET_KEY=xxxxxxx
GARANTI_REFUND_USERNAME=PROVRFN      # iade/iptal ayrı kullanıcı ister
GARANTI_REFUND_PASSWORD=xxxxxxx

# Akbank (NestPay)
AKBANK_MERCHANT_ID=xxxxxxx
AKBANK_USERNAME=xxxxxxx
AKBANK_PASSWORD=xxxxxxx
AKBANK_SECRET_KEY=xxxxxxx

# Yapı Kredi PosNet — posnet_id ayrı bir alandır, merchant_id değildir
YAPIKREDI_MERCHANT_ID=xxxxxxx
YAPIKREDI_TERMINAL_ID=xxxxxxx
YAPIKREDI_POSNET_ID=xxxxxxx
YAPIKREDI_SECRET_KEY=xxxxxxx
```

Tüm anahtarlar için yayınladığınız `config/anadolupay.php` dosyasına bakın.

## Ödeme akışı

Türk banka sanal POS'larında 3D Secure bir GET yönlendirmesi değil, bankanın
3D geçidine yapılan bir **form POST**'udur. Akış üç adımdır:

```
  [1] createPayment()          [2] tarayıcı            [3] verify()
      imzalı form üret    ──►   bankaya POST      ──►   hash doğrula
                                kullanıcı SMS/          + provizyon iste
                                app onayı
```

### 1 · Ödemeyi başlat

```php
use Voxyfy\AnadoluPay\DTO\CardData;
use Voxyfy\AnadoluPay\DTO\CreatePaymentData;
use Voxyfy\AnadoluPay\Facades\AnadoluPay;

$data = new CreatePaymentData(
    amount: 199.90,
    currency: 'TRY',
    orderId: 'SIPARIS-123',
    customer: [
        'name'  => 'Ahmet Yılmaz',
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

return response($response->toHtmlForm());
```

`toHtmlForm()` otomatik gönderilen bir sayfa üretir. Formu kendiniz render
etmek isterseniz:

```php
$response->formAction;   // bankanın 3D geçidi
$response->formMethod;   // 'POST'
$response->formFields;   // imzalı gizli alanlar
```

**Kuveyt Türk, Vakıf Katılım ve Param** form alanı yerine hazır bir HTML sayfası
döner; bu durumda `formFields` boştur ve içerik `$response->htmlContent`
içindedir. `toHtmlForm()` iki durumu da doğru ele alır — elle uğraşmak yerine
onu kullanın.

### 2 · Dönüşü doğrula

```php
use Voxyfy\AnadoluPay\DTO\VerifyPaymentData;

$result = AnadoluPay::driver('garanti')->verify(new VerifyPaymentData(
    payload: $request->all(),
    headers: $request->headers->all(),
    rawBody: $request->getContent(),
));

if ($result->success) {
    // $result->paymentId bankanın işlem referansıdır.
    // Saklayın — iade ve iptal için gerekecek.
}
```

`verify()` sırayla: dönüş hash'ini doğrular (eşleşmezse
`InvalidSignatureException`), 3D doğrulama durumunu kontrol eder, klasik 3D
Secure modelinde bankaya provizyon isteğini gönderir. 3D Pay ve 3D Host'ta
provizyon banka tarafında tamamlandığı için ikinci istek atılmaz.

**PayFlex (VakıfBank / Ziraat) sipariş bağlamı ister.** Banka provizyon
adımında kart bilgisini ve tutarı yeniden sorar ama bunları dönüşte
göndermez — siz sağlarsınız:

```php
$result = AnadoluPay::driver('vakifbank')->verify(new VerifyPaymentData(
    payload: $request->all(),
    order: [
        'id'       => 'SIPARIS-123',
        'amount'   => 199.90,
        'currency' => 'TRY',
        'ip'       => $request->ip(),
        'card'     => ['number' => '...', 'expire_month' => '12',
                       'expire_year' => '30', 'cvv' => '123'],
    ],
));
```

## İade ve iptal

```php
use Voxyfy\AnadoluPay\DTO\RefundPaymentData;

AnadoluPay::driver('akbank')->refund(new RefundPaymentData('SIPARIS-123'));         // tam
AnadoluPay::driver('akbank')->refund(new RefundPaymentData('SIPARIS-123', 49.90));  // kısmi
```

Gün sonu kapanmadan önce iade değil **iptal** kullanın — daha hızlı ve
komisyonsuzdur:

```php
AnadoluPay::driver('akbank')->cancel(new RefundPaymentData('SIPARIS-123'));
```

Bazı bankalar işlemi sipariş numarasıyla değil, kendi referanslarıyla eşler.
Bu referansı ödeme sırasında saklayıp `metadata` ile geçin:

| Banka | Gereken alan | Nereden gelir |
|---|---|---|
| Garanti | `ref_ret_num` | provizyon yanıtı `Transaction.RetrefNum` |
| Yapı Kredi | `host_ref_num` | provizyon yanıtı `hostlogkey` |
| PayFlex | `transaction_id` | provizyon yanıtı `TransactionId` |
| Vakıf Katılım | `remote_order_id` | provizyon yanıtı `OrderId` |

```php
new RefundPaymentData('SIPARIS-123', 49.90, metadata: ['ref_ret_num' => '...']);
```

> `cancel()` ve `status()` şu an `PaymentGatewayInterface`'de değil, driver'lara
> özel metotlardır. Yani statik tip güvenliği yoktur; desteklemeyen bir
> driver'da çağırırsanız runtime'da patlar. Hangi driver'ın hangisini
> desteklediği [tabloda](#desteklenen-bankalar) yazıyor.

## Ödeme modelleri

| Sabit | Ne yapar | Ne zaman |
|---|---|---|
| `MODEL_3D_SECURE` | Doğrulama sonrası ayrı provizyon isteği | Varsayılan; en yaygın |
| `MODEL_3D_PAY` | Doğrulama ve provizyon tek adımda bankada | Daha az round-trip isteyen kurulumlar |
| `MODEL_3D_HOST` | Kart formu da bankada toplanır | **Kart verisi sunucunuza hiç uğramaz** — PCI kapsamını daraltır |
| `MODEL_NON_SECURE` | 3D yok, doğrudan provizyon | Mail order / abonelik |

3D Host modelinde `card` vermeniz gerekmez.

## Bankaların tuhaflıkları

Driver'lar bunları sizin için hallediyor. Burada olmalarının sebebi, bir şey
ters gittiğinde nereye bakacağınızı bilmeniz.

**Tutar formatı üç farklı.** Garanti, PosNet, Kuveyt Türk ve Tosla kuruş
cinsinden tam sayı ister (`199.90` → `19990`). NestPay ve PayFor PHP'nin doğal
float gösterimini ister (`199.9` → `"199.9"`, `100.0` → `"100"`). Akbank POS ve
PayFlex iki ondalıklı dizgi ister (`"199.90"`). **Hash tam olarak gönderilen
dizgi üzerinden hesaplandığı için bu formatlar değiştirilemez.**

**Taksit alanı tek çekimde bile dört farklı.** NestPay boş dizgi, PosNet `'00'`,
PayFor ve Kuveyt Türk `'0'`, Param `'1'` bekler. PayFlex alanı hiç göndermez.

**Para birimi kodu her yerde ISO 4217 sayısal değil.** Kuveyt Türk dört haneli
kullanır (`0949`), PosNet V1 harf kısaltması (`TL`, `US`, `EU`).

**Garanti iade için ayrı kullanıcı ister.** `refund` ve `void` işlemlerinde
`securityData` normal şifreyle değil iade şifresiyle hesaplanır. İki ayrı
kullanıcı tanımlamazsanız iadeler reddedilir.

**PosNet üç sunucu isteği yapar.** Önce `oosRequestData` ile veri paketleri
alınır, sonra 3D geçidine POST edilir, dönüşte `oosResolveMerchantData` ile
çözülüp `oosTranData` ile provizyon tamamlanır. Sipariş numarası 20 haneye
sıfırla doldurulur; iade/iptalde 24 hane olur ve 3D siparişler `TDSC` ön eki
alır.

**Ziraat Katılım'ın dönüş hash'i banka tarafında tutarsız üretiliyor.** Bu
yüzden o preset'te `verify_hash` varsayılan olarak kapalıdır. Bankanız
düzelttiyse `ZIRAAT_KATILIM_VERIFY_HASH=true` yapın.

**PayTR bildirimi `OK` yanıtı bekler.** Webhook'unuz gövdede düz metin `OK`
döndürmezse PayTR bildirimi tekrar tekrar gönderir.

**NestPay hash'i alanları sıralar.** Alanlar doğal sırada (harf duyarsız)
sıralanır, `hash`/`encoding`/`nationalidno` çıkarılır, sona secret key eklenir,
`|` ve `\` karakterleri kaçırılır. Forma yeni bir alan eklerseniz hash'e de
girer — banka bunu bilmiyorsa işlem reddedilir.

## Loglama

Banka bir işlemi reddettiğinde size yalnızca bir kod döner
(`ProcReturnCode=99`). Sorunun hangi alanda olduğunu ancak gönderdiğiniz
gövdeyi görerek anlarsınız. Entegrasyon geliştirirken açın:

```env
ANADOLUPAY_LOGGING=true
ANADOLUPAY_LOG_CHANNEL=anadolupay   # boşsa uygulamanın varsayılan kanalı
```

```
[debug] AnadoluPay banka isteği  {"bank":"garanti","url":"…","body":"<GVPSRequest>…
                                  <Number>415565******6111</Number>
                                  <CVV2>[gizlendi]</CVV2>…"}
[debug] AnadoluPay banka yanıtı  {"bank":"garanti","status":200,"duration_ms":412,…}
```

Maskeleme iki katmanlıdır. Birincisi alan adına göre (`cvv`, `password`,
`secret_key`…). İkincisi değerin biçimine göre: **Luhn kontrolünden geçen her
13–19 haneli sayı, alan adı ne olursa olsun maskelenir.** İkinci katman
olmadan, on altı driver'ın farklı adlandırdığı kart alanlarından birini
gözden kaçırmak kart verisini loga düşürürdü.

Luhn kontrolü yanlış pozitifleri de eler — PosNet'in 20 haneye doldurulmuş
sipariş numaraları ve NestPay'in MD taşıyan `Number` alanı okunabilir kalır.

Başarısız HTTP yanıtları `warning`, gerisi `debug` seviyesindedir.

> Loglama **varsayılan olarak kapalıdır**. Maskeleme uygulansa bile bu
> kayıtların nereye yazıldığı bilinçli bir tercih olmalıdır: kalıcı bir kanal
> seçiyorsanız erişimini kısıtlayın ve saklama süresi tanımlayın.

## Test ortamı

Preset'lerdeki uç noktalar canlı ortamı gösterir. Test için ilgili
`*_PAYMENT_API` / `*_GATEWAY_3D` değişkenlerini bankanızın test adresiyle
değiştirin ve `*_TEST_MODE=true` yapın.

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

Gerçek istek atmadan akışı denemek için `fake` driver'ını kullanın.

## Güvenlik

- Kart verisi (`CardData`) **saklanmamalıdır**. Kendi loglarınızda göstermeniz
  gerekiyorsa `CardData::masked()` kullanın; paketin istek/yanıt logları zaten
  maskelidir.
- `CardData` nesnelerini `dd()`, `var_dump()` veya exception raporlarına
  vermeyin — bunlar maskelemeden geçmez.
- `verify_hash` yalnızca bankanın hash'i tutarsız ürettiği bilinen kurulumlarda
  kapatılmalıdır. Kapalıyken sahte callback'lere açıksınızdır.
- `verify_ssl` her zaman `true` kalmalıdır.
- 3D Host modeli kart verisini sunucunuzdan tamamen uzak tutar; PCI kapsamını
  daraltmak istiyorsanız en iyi seçenektir.

Güvenlik açığı bildirimi: security@voxyfy.com

## Yeni banka eklemek

`AbstractBankGateway` sınıfını genişletin ve yedi metodu implement edin:

```php
class YeniBankaGateway extends AbstractBankGateway
{
    protected function build3dFormFields(CreatePaymentData $data): array { … }
    protected function checkCallbackHash(array $payload): bool { … }
    protected function is3dAuthSuccess(array $payload): bool { … }
    protected function provision(array $payload): array { … }
    protected function mapCallbackResponse(array $payload): VerificationResponse { … }
    protected function mapProvisionResponse(array $payload, array $provision): VerificationResponse { … }
    protected function extractOrderId(array $payload): ?string { … }
}
```

Sonra `config/anadolupay.php` içindeki `banks` dizisine bir preset ekleyin.
Akış, hata yönetimi, HTTP ve loglama temel sınıftan gelir.

**İmza için test yazın.** Sabit girdilerle üretilmiş bir özet değerine
kilitleyin — mevcut driver'ların hepsinde örneği var (`tests/Bank/HashTest.php`).
İmza sessizce bozulabilen tek şeydir.

## Yol haritası

Bilinen eksikler. Bir maddeye başlamadan önce issue açmanız çakışmayı önler.

### Öncelikli

- [ ] **iyzico imza şemasını doğrula.** `IyzicoHttpClient` ve
      `IyzicoSignatureValidator` içindeki algoritma iyzico'nun resmi
      dokümantasyonuyla teyit edilmemiştir (kodda `TODO` olarak işaretli).
      Yanlış algoritma ya geçerli bildirimleri reddeder ya da
      `IYZICO_VALIDATE_SIGNATURE=false` kullanımında sahte bildirimlerin
      kabul edilmesine yol açar.
- [ ] **iyzico iadesi** — şu an `UnsupportedOperationException` fırlatıyor.
- [ ] **Tutarları kuruş cinsinden `int` olarak taşı.** `CreatePaymentData::$amount`
      `float`; para için prensipte risklidir. BC kıracağı için ayrı bir major
      sürümde.

### İşlem kapsamı

- [ ] `cancel()` ve `status()`'ü sözleşmeye taşı (`SupportsCancellation` /
      `SupportsStatusQuery` arayüzleri).
- [ ] Eksik `cancel()`: Kuveyt Türk, Param, PayTR.
- [ ] Eksik `status()`: Asseco, PayFor ve PayTR dışındaki tüm driver'lar.
- [ ] Kuveyt Türk iade/iptal — ayrı SOAP servisi, farklı kimlik doğrulama.
- [ ] Ön provizyon / provizyon kapama.
- [ ] İşlem geçmişi, taksit oranı ve BIN sorgulama.
- [ ] Tekrarlayan ödeme.

### Yeni bankalar

Çoğu mevcut NestPay driver'ını kullanır; yeni kod değil, preset ve doğrulanmış
uç nokta gerekir.

- [ ] ING Bank · Anadolubank · Alternatif Bank · Odeabank · Türkiye Finans ·
      Fibabanka · Burgan Bank
- [ ] Emlak Katılım — hangi altyapıyı kullandığı araştırılmalı

### Yeni ödeme kuruluşları

- [ ] **Sipay** — imza şeması güvenilir bir public kaynaktan doğrulanamadığı
      için bilinçli olarak eklenmedi.
- [ ] Craftgate · Moka · Paratika/MSU · PayU Türkiye · Vallet · Paycell

### Altyapı

- [x] ~~PSR-3 loglama (maskeli)~~ — bkz. [Loglama](#loglama)
- [ ] Event'ler: `PaymentInitiated`, `PaymentVerified`, `PaymentFailed`,
      `RefundIssued`.
- [ ] Idempotency — aynı `orderId` ile ikinci `createPayment()` çağrısına karşı
      koruma yok.
- [ ] Retry politikası — timeout var (30 sn), yeniden deneme yok.
- [ ] Hata sınıflandırması — ağ/HTTP hatası ile bankanın iş kuralı reddi şu an
      ikisi de `PaymentFailedException`.

## Katkı

```bash
composer test        # Pest
composer format      # Pint
vendor/bin/phpstan   # Larastan, level 5
```

Detaylar için [CONTRIBUTING](CONTRIBUTING.md), sürüm geçmişi için
[CHANGELOG](CHANGELOG.md).

## Lisans

MIT — bkz. [LICENSE](LICENSE.md).
