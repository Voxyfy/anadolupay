<?php

use Voxyfy\AnadoluPay\Gateways\Bank\AssecoGateway;
use Voxyfy\AnadoluPay\Gateways\Bank\GarantiGateway;
use Voxyfy\AnadoluPay\Gateways\Bank\InterPosGateway;
use Voxyfy\AnadoluPay\Gateways\Bank\KuveytPosGateway;
use Voxyfy\AnadoluPay\Gateways\Bank\PayFlexGateway;
use Voxyfy\AnadoluPay\Gateways\Bank\PayForGateway;
use Voxyfy\AnadoluPay\Gateways\Bank\PosNetGateway;
use Voxyfy\AnadoluPay\Gateways\Bank\PosNetV1Gateway;
use Voxyfy\AnadoluPay\Gateways\Bank\VakifKatilimGateway;
use Voxyfy\AnadoluPay\Gateways\FakeGateway;
use Voxyfy\AnadoluPay\Gateways\IyzicoGateway;
use Voxyfy\AnadoluPay\Gateways\Provider\AkbankPosGateway;
use Voxyfy\AnadoluPay\Gateways\Provider\CraftgateGateway;
use Voxyfy\AnadoluPay\Gateways\Provider\MokaGateway;
use Voxyfy\AnadoluPay\Gateways\Provider\ParamGateway;
use Voxyfy\AnadoluPay\Gateways\Provider\ParatikaGateway;
use Voxyfy\AnadoluPay\Gateways\Provider\PayTrGateway;
use Voxyfy\AnadoluPay\Gateways\Provider\ToslaGateway;

return [

    /*
    |--------------------------------------------------------------------------
    | Ödeme Geçidi Driver'ları
    |--------------------------------------------------------------------------
    |
    | Konteynerden çözümlenen, kendi yapılandırmasını kendi okuyan driver'lar.
    | Banka sanal POS'ları için aşağıdaki 'banks' bölümünü kullanın.
    |
    */

    'drivers' => [

        // Geliştirme ve test için sahte gateway.
        // Gerçek API çağrısı yapmaz, rastgele başarı/başarısızlık simüle eder.
        'fake' => FakeGateway::class,
        'iyzico' => IyzicoGateway::class,

    ],

    /*
    |--------------------------------------------------------------------------
    | Sahte Geçit
    |--------------------------------------------------------------------------
    |
    | `fake` driver'ı hiçbir ağ isteği yapmaz ve varsayılan olarak her
    | işlemi başarılı sayar; testlerin rastgele kırılmaması için
    | öngörülebilir olması gerekir.
    |
    | Hata yollarını denemek isterseniz oranı düşürün (0 = her zaman
    | başarısız).
    |
    */

    'fake' => [
        'success_rate' => env('ANADOLUPAY_FAKE_SUCCESS_RATE', 100),
    ],

    /*
    |--------------------------------------------------------------------------
    | Loglama
    |--------------------------------------------------------------------------
    |
    | Açıldığında her banka isteği ve yanıtı, kart numarası ve CVV
    | maskelenerek kaydedilir. Banka yalnızca bir hata kodu döndüğü için
    | entegrasyon sorunlarını ayıklamanın pratikte tek yolu budur.
    |
    | Varsayılan olarak kapalıdır: maskeleme uygulansa bile bu kayıtların
    | nereye yazıldığı bilinçli bir tercih olmalıdır. Kalıcı log tutan bir
    | kanal seçiyorsanız erişimini kısıtlayın ve saklama süresi tanımlayın.
    |
    */

    'logging' => [
        'enabled' => env('ANADOLUPAY_LOGGING', false),
        // Boş bırakılırsa uygulamanın varsayılan log kanalı kullanılır.
        'channel' => env('ANADOLUPAY_LOG_CHANNEL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Event'ler
    |--------------------------------------------------------------------------
    |
    | PaymentInitiated, PaymentVerified, PaymentFailed ve RefundIssued
    | event'leri yayınlanır. Kart verisi taşımazlar.
    |
    */

    'events' => [
        'enabled' => env('ANADOLUPAY_EVENTS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Yeniden Deneme
    |--------------------------------------------------------------------------
    |
    | Retry YALNIZCA bankaya ulaşılamayan durumlarda (bağlantı kurulamadı,
    | DNS çözülemedi) yapılır. Zaman aşımı ve HTTP hataları tekrar
    | DENENMEZ: bu durumlarda istek bankaya ulaşmış ve işlenmiş olabilir,
    | körlemesine tekrar denemek çift çekim üretir.
    |
    | Varsayılan 0'dır. Açmadan önce sipariş durumunu kendi tarafınızda
    | takip ettiğinizden emin olun.
    |
    */

    'retry' => [
        'times' => env('ANADOLUPAY_RETRY_TIMES', 0),
        'sleep_ms' => env('ANADOLUPAY_RETRY_SLEEP_MS', 250),
    ],

    /*
    |--------------------------------------------------------------------------
    | İstek zaman aşımı (saniye)
    |--------------------------------------------------------------------------
    |
    | Banka test terminalleri canlıdan belirgin biçimde yavaş olabiliyor;
    | 3D provizyonu 30 saniyeyi aşabilir. Her banka preset'i kendi
    | `timeout` değerini tanımlayarak bunu geçersiz kılabilir.
    |
    | Zaman aşımı hiçbir zaman tekrar denenmez: istek bankaya ulaşmış ve
    | işlenmiş olabilir, tekrar denemek çift çekim üretir. Böyle bir durumda
    | sonucu durum sorgusuyla netleştirin.
    |
    */

    'timeout' => env('ANADOLUPAY_TIMEOUT', 30),

    /*
    |--------------------------------------------------------------------------
    | Mükerrer Ödeme Koruması
    |--------------------------------------------------------------------------
    |
    | Aynı sipariş numarası için kısa bir pencere içinde ikinci bir ödeme
    | başlatılmasını engeller; asıl hedef çift tıklamayı yakalamaktır.
    |
    | Pencere bilinçli olarak kısadır: ödeme gerçekten başarısız olduğunda
    | müşterinin aynı sipariş numarasıyla tekrar denemesi meşrudur.
    |
    | Atomik kilit için `array` dışında bir cache sürücüsü gerekir.
    |
    */

    'idempotency' => [
        'enabled' => env('ANADOLUPAY_IDEMPOTENCY', false),
        'ttl' => env('ANADOLUPAY_IDEMPOTENCY_TTL', 30),
        // Boş bırakılırsa uygulamanın varsayılan cache sürücüsü kullanılır.
        'store' => env('ANADOLUPAY_IDEMPOTENCY_STORE'),
        'prefix' => 'anadolupay:payment:',
    ],

    /*
    |--------------------------------------------------------------------------
    | Banka Sanal POS Preset'leri
    |--------------------------------------------------------------------------
    |
    | Her anahtar `AnadoluPay::driver('<anahtar>')` ile çözümlenir. Yalnızca
    | kullandığınız bankaların kimlik bilgilerini doldurmanız yeterlidir;
    | boş bırakılan preset'ler yalnızca çağrıldıklarında hata verir.
    |
    | Alanların bankalara göre karşılıkları:
    |   merchant_id  => ClientId / MerchantId / ShopCode / merchantSafeId
    |   terminal_id  => TerminalId / TerminalNo / terminalSafeId
    |   username     => API kullanıcı adı (Name / UserCode / ProvUserID)
    |   password     => API şifresi
    |   secret_key   => 3D anahtarı (store key / hash key / GUID)
    |
    | Test uç noktaları için README'deki "Test ortamı" bölümüne bakın.
    |
    */

    'banks' => [

        /*
        |----------------------------------------------------------------------
        | Asseco / Payten (NestPay) altyapısını kullanan bankalar
        |----------------------------------------------------------------------
        */

        'akbank' => [
            'gateway' => AssecoGateway::class,
            'merchant_id' => env('AKBANK_MERCHANT_ID'),
            'username' => env('AKBANK_USERNAME'),
            'password' => env('AKBANK_PASSWORD'),
            'secret_key' => env('AKBANK_SECRET_KEY'),
            'test_mode' => env('AKBANK_TEST_MODE', false),
            'endpoints' => [
                'payment_api' => env('AKBANK_PAYMENT_API', 'https://www.sanalakpos.com/fim/api'),
                'gateway_3d' => env('AKBANK_GATEWAY_3D', 'https://www.sanalakpos.com/fim/est3Dgate'),
                'gateway_3d_host' => env('AKBANK_GATEWAY_3D_HOST', 'https://sanalpos.sanalakpos.com.tr/fim/est3Dgate'),
            ],
        ],

        'isbank' => [
            'gateway' => AssecoGateway::class,
            'merchant_id' => env('ISBANK_MERCHANT_ID'),
            'username' => env('ISBANK_USERNAME'),
            'password' => env('ISBANK_PASSWORD'),
            'secret_key' => env('ISBANK_SECRET_KEY'),
            'test_mode' => env('ISBANK_TEST_MODE', false),
            'endpoints' => [
                'payment_api' => env('ISBANK_PAYMENT_API', 'https://sanalpos.isbank.com.tr/fim/api'),
                'gateway_3d' => env('ISBANK_GATEWAY_3D', 'https://sanalpos.isbank.com.tr/fim/est3Dgate'),
            ],
        ],

        'ziraat' => [
            'gateway' => AssecoGateway::class,
            'merchant_id' => env('ZIRAAT_MERCHANT_ID'),
            'username' => env('ZIRAAT_USERNAME'),
            'password' => env('ZIRAAT_PASSWORD'),
            'secret_key' => env('ZIRAAT_SECRET_KEY'),
            'test_mode' => env('ZIRAAT_TEST_MODE', false),
            // Ziraat'in stage terminali yavaş; basit bir sorgu bile ~8 sn sürüyor.
            'timeout' => env('ZIRAAT_TIMEOUT'),
            'endpoints' => [
                'payment_api' => env('ZIRAAT_PAYMENT_API', 'https://sanalpos2.ziraatbank.com.tr/fim/api'),
                'gateway_3d' => env('ZIRAAT_GATEWAY_3D', 'https://sanalpos2.ziraatbank.com.tr/fim/est3Dgate'),
            ],
        ],

        'halkbank' => [
            'gateway' => AssecoGateway::class,
            'merchant_id' => env('HALKBANK_MERCHANT_ID'),
            'username' => env('HALKBANK_USERNAME'),
            'password' => env('HALKBANK_PASSWORD'),
            'secret_key' => env('HALKBANK_SECRET_KEY'),
            'test_mode' => env('HALKBANK_TEST_MODE', false),
            'endpoints' => [
                'payment_api' => env('HALKBANK_PAYMENT_API', 'https://sanalpos.halkbank.com.tr/fim/api'),
                'gateway_3d' => env('HALKBANK_GATEWAY_3D', 'https://sanalpos.halkbank.com.tr/fim/est3dgate'),
            ],
        ],

        'qnb' => [
            'gateway' => AssecoGateway::class,
            'merchant_id' => env('QNB_MERCHANT_ID'),
            'username' => env('QNB_USERNAME'),
            'password' => env('QNB_PASSWORD'),
            'secret_key' => env('QNB_SECRET_KEY'),
            'test_mode' => env('QNB_TEST_MODE', false),
            'endpoints' => [
                'payment_api' => env('QNB_PAYMENT_API', 'https://www.fbwebpos.com/fim/api'),
                'gateway_3d' => env('QNB_GATEWAY_3D', 'https://www.fbwebpos.com/fim/est3dgate'),
            ],
        ],

        'teb' => [
            'gateway' => AssecoGateway::class,
            'merchant_id' => env('TEB_MERCHANT_ID'),
            'username' => env('TEB_USERNAME'),
            'password' => env('TEB_PASSWORD'),
            'secret_key' => env('TEB_SECRET_KEY'),
            'test_mode' => env('TEB_TEST_MODE', false),
            'endpoints' => [
                'payment_api' => env('TEB_PAYMENT_API', 'https://sanalpos.teb.com.tr/fim/api'),
                'gateway_3d' => env('TEB_GATEWAY_3D', 'https://sanalpos.teb.com.tr/fim/est3Dgate'),
            ],
        ],

        'sekerbank' => [
            'gateway' => AssecoGateway::class,
            'merchant_id' => env('SEKERBANK_MERCHANT_ID'),
            'username' => env('SEKERBANK_USERNAME'),
            'password' => env('SEKERBANK_PASSWORD'),
            'secret_key' => env('SEKERBANK_SECRET_KEY'),
            'test_mode' => env('SEKERBANK_TEST_MODE', false),
            'endpoints' => [
                'payment_api' => env('SEKERBANK_PAYMENT_API', 'https://sanalpos.sekerbank.com.tr/fim/api'),
                'gateway_3d' => env('SEKERBANK_GATEWAY_3D', 'https://sanalpos.sekerbank.com.tr/fim/est3Dgate'),
            ],
        ],

        'ing' => [
            'gateway' => AssecoGateway::class,
            'merchant_id' => env('ING_MERCHANT_ID'),
            'username' => env('ING_USERNAME'),
            'password' => env('ING_PASSWORD'),
            'secret_key' => env('ING_SECRET_KEY'),
            'test_mode' => env('ING_TEST_MODE', false),
            'endpoints' => [
                // sanalpos.ingbank.com.tr adresi de aynı NestPay kurulumuna çıkar.
                'payment_api' => env('ING_PAYMENT_API', 'https://sanalpos.ing.com.tr/fim/api'),
                'gateway_3d' => env('ING_GATEWAY_3D', 'https://sanalpos.ing.com.tr/fim/est3Dgate'),
            ],
        ],

        'alternatifbank' => [
            'gateway' => AssecoGateway::class,
            'merchant_id' => env('ALTERNATIFBANK_MERCHANT_ID'),
            'username' => env('ALTERNATIFBANK_USERNAME'),
            'password' => env('ALTERNATIFBANK_PASSWORD'),
            'secret_key' => env('ALTERNATIFBANK_SECRET_KEY'),
            'test_mode' => env('ALTERNATIFBANK_TEST_MODE', false),
            'endpoints' => [
                'payment_api' => env('ALTERNATIFBANK_PAYMENT_API', 'https://sanalpos.alternatifbank.com.tr/fim/api'),
                'gateway_3d' => env('ALTERNATIFBANK_GATEWAY_3D', 'https://sanalpos.alternatifbank.com.tr/fim/est3Dgate'),
            ],
        ],

        'turkiyefinans' => [
            'gateway' => AssecoGateway::class,
            'merchant_id' => env('TURKIYEFINANS_MERCHANT_ID'),
            'username' => env('TURKIYEFINANS_USERNAME'),
            'password' => env('TURKIYEFINANS_PASSWORD'),
            'secret_key' => env('TURKIYEFINANS_SECRET_KEY'),
            'test_mode' => env('TURKIYEFINANS_TEST_MODE', false),
            'endpoints' => [
                'payment_api' => env('TURKIYEFINANS_PAYMENT_API', 'https://sanalpos.turkiyefinans.com.tr/fim/api'),
                'gateway_3d' => env('TURKIYEFINANS_GATEWAY_3D', 'https://sanalpos.turkiyefinans.com.tr/fim/est3Dgate'),
            ],
        ],

        /*
        |----------------------------------------------------------------------
        | Kendi altyapısını kullanan bankalar
        |----------------------------------------------------------------------
        */

        'garanti' => [
            'gateway' => GarantiGateway::class,
            'merchant_id' => env('GARANTI_MERCHANT_ID'),
            'terminal_id' => env('GARANTI_TERMINAL_ID'),
            'username' => env('GARANTI_USERNAME'),
            'password' => env('GARANTI_PASSWORD'),
            'secret_key' => env('GARANTI_SECRET_KEY'),
            // İade ve iptal işlemleri ayrı bir kullanıcı/şifre çifti ister.
            'refund_password' => env('GARANTI_REFUND_PASSWORD'),
            'extra' => [
                'refund_username' => env('GARANTI_REFUND_USERNAME'),
            ],
            'test_mode' => env('GARANTI_TEST_MODE', false),
            'endpoints' => [
                'payment_api' => env('GARANTI_PAYMENT_API', 'https://sanalposprov.garanti.com.tr/VPServlet'),
                'gateway_3d' => env('GARANTI_GATEWAY_3D', 'https://sanalposprov.garanti.com.tr/servlet/gt3dengine'),
            ],
        ],

        'yapikredi' => [
            'gateway' => PosNetGateway::class,
            'merchant_id' => env('YAPIKREDI_MERCHANT_ID'),
            'terminal_id' => env('YAPIKREDI_TERMINAL_ID'),
            'secret_key' => env('YAPIKREDI_SECRET_KEY'),
            'extra' => [
                'posnet_id' => env('YAPIKREDI_POSNET_ID'),
            ],
            'test_mode' => env('YAPIKREDI_TEST_MODE', false),
            'endpoints' => [
                'payment_api' => env('YAPIKREDI_PAYMENT_API', 'https://posnet.yapikredi.com.tr/PosnetWebService/XML'),
                'gateway_3d' => env('YAPIKREDI_GATEWAY_3D', 'https://posnet.yapikredi.com.tr/3DSWebService/YKBPaymentService'),
            ],
        ],

        'albaraka' => [
            'gateway' => PosNetV1Gateway::class,
            'merchant_id' => env('ALBARAKA_MERCHANT_ID'),
            'terminal_id' => env('ALBARAKA_TERMINAL_ID'),
            'secret_key' => env('ALBARAKA_SECRET_KEY'),
            'extra' => [
                'posnet_id' => env('ALBARAKA_POSNET_ID'),
            ],
            'test_mode' => env('ALBARAKA_TEST_MODE', false),
            'endpoints' => [
                'payment_api' => env('ALBARAKA_PAYMENT_API', 'https://epos.albarakaturk.com.tr/ALBMerchantService/MerchantJSONAPI.svc'),
                'gateway_3d' => env('ALBARAKA_GATEWAY_3D', 'https://epos.albarakaturk.com.tr/ALBSecurePaymentUI/SecureProcess/SecureVerification.aspx'),
            ],
        ],

        'vakifbank' => [
            'gateway' => PayFlexGateway::class,
            'merchant_id' => env('VAKIFBANK_MERCHANT_ID'),
            'terminal_id' => env('VAKIFBANK_TERMINAL_ID'),
            'password' => env('VAKIFBANK_PASSWORD'),
            'extra' => [
                'merchant_type' => env('VAKIFBANK_MERCHANT_TYPE', '0'),
            ],
            'test_mode' => env('VAKIFBANK_TEST_MODE', false),
            'endpoints' => [
                'payment_api' => env('VAKIFBANK_PAYMENT_API', 'https://onlineodeme.vakifbank.com.tr:4443/VposService/v3/Vposreq.aspx'),
                'gateway_3d' => env('VAKIFBANK_GATEWAY_3D', 'https://3dsecure.vakifbank.com.tr:4443/MPIAPI/MPI_Enrollment.aspx'),
                'query_api' => env('VAKIFBANK_QUERY_API', 'https://onlineodeme.vakifbank.com.tr:4443/UIService/Search.aspx'),
            ],
        ],

        'ziraat-payflex' => [
            'gateway' => PayFlexGateway::class,
            'merchant_id' => env('ZIRAAT_PAYFLEX_MERCHANT_ID'),
            'terminal_id' => env('ZIRAAT_PAYFLEX_TERMINAL_ID'),
            'password' => env('ZIRAAT_PAYFLEX_PASSWORD'),
            'test_mode' => env('ZIRAAT_PAYFLEX_TEST_MODE', false),
            'endpoints' => [
                'payment_api' => env('ZIRAAT_PAYFLEX_PAYMENT_API', 'https://sanalpos.ziraatbank.com.tr/v4/v3/Vposreq.aspx'),
                'gateway_3d' => env('ZIRAAT_PAYFLEX_GATEWAY_3D', 'https://mpi.ziraatbank.com.tr/Enrollment.aspx'),
                'query_api' => env('ZIRAAT_PAYFLEX_QUERY_API', 'https://sanalpos.ziraatbank.com.tr/v4/UIWebService/Search.aspx'),
            ],
        ],

        'denizbank' => [
            'gateway' => InterPosGateway::class,
            'merchant_id' => env('DENIZBANK_MERCHANT_ID'),
            'username' => env('DENIZBANK_USERNAME'),
            'password' => env('DENIZBANK_PASSWORD'),
            'secret_key' => env('DENIZBANK_SECRET_KEY'),
            'test_mode' => env('DENIZBANK_TEST_MODE', false),
            'endpoints' => [
                'payment_api' => env('DENIZBANK_PAYMENT_API', 'https://inter-vpos.com.tr/mpi/Default.aspx'),
                'gateway_3d' => env('DENIZBANK_GATEWAY_3D', 'https://inter-vpos.com.tr/mpi/Default.aspx'),
                'gateway_3d_host' => env('DENIZBANK_GATEWAY_3D_HOST', 'https://inter-vpos.com.tr/mpi/3DHost.aspx'),
            ],
        ],

        'qnb-payfor' => [
            'gateway' => PayForGateway::class,
            'merchant_id' => env('QNB_PAYFOR_MERCHANT_ID'),
            'username' => env('QNB_PAYFOR_USERNAME'),
            'password' => env('QNB_PAYFOR_PASSWORD'),
            'secret_key' => env('QNB_PAYFOR_SECRET_KEY'),
            'extra' => [
                'mbr_id' => env('QNB_PAYFOR_MBR_ID', '5'),
            ],
            'test_mode' => env('QNB_PAYFOR_TEST_MODE', false),
            'endpoints' => [
                'payment_api' => env('QNB_PAYFOR_PAYMENT_API', 'https://vpos.qnb.com.tr/Gateway/XMLGate.aspx'),
                'gateway_3d' => env('QNB_PAYFOR_GATEWAY_3D', 'https://vpos.qnb.com.tr/Gateway/Default.aspx'),
                'gateway_3d_host' => env('QNB_PAYFOR_GATEWAY_3D_HOST', 'https://vpos.qnb.com.tr/Gateway/3DHost.aspx'),
            ],
        ],

        'ziraat-katilim' => [
            'gateway' => PayForGateway::class,
            'merchant_id' => env('ZIRAAT_KATILIM_MERCHANT_ID'),
            'username' => env('ZIRAAT_KATILIM_USERNAME'),
            'password' => env('ZIRAAT_KATILIM_PASSWORD'),
            'secret_key' => env('ZIRAAT_KATILIM_SECRET_KEY'),
            // Ziraat Katılım tarafında dönüş hash'i tutarsız üretildiği için
            // varsayılan olarak kapalıdır.
            'verify_hash' => env('ZIRAAT_KATILIM_VERIFY_HASH', false),
            'test_mode' => env('ZIRAAT_KATILIM_TEST_MODE', false),
            'endpoints' => [
                'payment_api' => env('ZIRAAT_KATILIM_PAYMENT_API', 'https://vpos.ziraatkatilim.com.tr/Mpi/XMLGate.aspx'),
                'gateway_3d' => env('ZIRAAT_KATILIM_GATEWAY_3D', 'https://vpos.ziraatkatilim.com.tr/Mpi/Default.aspx'),
                'gateway_3d_host' => env('ZIRAAT_KATILIM_GATEWAY_3D_HOST', 'https://vpos.ziraatkatilim.com.tr/Mpi/3Dhost.aspx'),
            ],
        ],

        'kuveytturk' => [
            'gateway' => KuveytPosGateway::class,
            'merchant_id' => env('KUVEYTTURK_MERCHANT_ID'),
            'username' => env('KUVEYTTURK_USERNAME'),
            'secret_key' => env('KUVEYTTURK_SECRET_KEY'),
            'extra' => [
                'customer_id' => env('KUVEYTTURK_CUSTOMER_ID'),
            ],
            'test_mode' => env('KUVEYTTURK_TEST_MODE', false),
            'endpoints' => [
                'payment_api' => env('KUVEYTTURK_PAYMENT_API', 'https://sanalpos.kuveytturk.com.tr/ServiceGateWay/Home'),
                'query_api' => env('KUVEYTTURK_QUERY_API', 'https://boa.kuveytturk.com.tr/BOA.Integration.WCFService/BOA.Integration.VirtualPos/VirtualPosService.svc/Basic'),
            ],
        ],

        'vakif-katilim' => [
            'gateway' => VakifKatilimGateway::class,
            'merchant_id' => env('VAKIF_KATILIM_MERCHANT_ID'),
            'username' => env('VAKIF_KATILIM_USERNAME'),
            'secret_key' => env('VAKIF_KATILIM_SECRET_KEY'),
            'extra' => [
                'customer_id' => env('VAKIF_KATILIM_CUSTOMER_ID'),
                'sub_merchant_id' => env('VAKIF_KATILIM_SUB_MERCHANT_ID', '0'),
            ],
            'test_mode' => env('VAKIF_KATILIM_TEST_MODE', false),
            'endpoints' => [
                'payment_api' => env('VAKIF_KATILIM_PAYMENT_API', 'https://boa.vakifkatilim.com.tr/VirtualPOS.Gateway/Home'),
                'gateway_3d_host' => env('VAKIF_KATILIM_GATEWAY_3D_HOST', 'https://boa.vakifkatilim.com.tr/VirtualPOS.Gateway/CommonPaymentPage/CommonPaymentPage'),
            ],
        ],

        /*
        |----------------------------------------------------------------------
        | Ödeme kuruluşları
        |----------------------------------------------------------------------
        */

        'akbank-pos' => [
            'gateway' => AkbankPosGateway::class,
            'merchant_id' => env('AKBANK_POS_MERCHANT_SAFE_ID'),
            'terminal_id' => env('AKBANK_POS_TERMINAL_SAFE_ID'),
            'secret_key' => env('AKBANK_POS_SECRET_KEY'),
            'test_mode' => env('AKBANK_POS_TEST_MODE', false),
            'endpoints' => [
                'payment_api' => env('AKBANK_POS_PAYMENT_API', 'https://api.akbank.com/api/v1/payment/virtualpos'),
                'gateway_3d' => env('AKBANK_POS_GATEWAY_3D', 'https://virtualpospaymentgateway.akbank.com/securepay'),
                'gateway_3d_host' => env('AKBANK_POS_GATEWAY_3D_HOST', 'https://virtualpospaymentgateway.akbank.com/payhosting'),
            ],
        ],

        'paytr' => [
            'gateway' => PayTrGateway::class,
            'merchant_id' => env('PAYTR_MERCHANT_ID'),
            // PayTR terminolojisi: secret_key => merchant key, password => merchant salt
            'secret_key' => env('PAYTR_MERCHANT_KEY'),
            'password' => env('PAYTR_MERCHANT_SALT'),
            'test_mode' => env('PAYTR_TEST_MODE', false),
            'endpoints' => [
                'payment_api' => env('PAYTR_PAYMENT_API', 'https://www.paytr.com'),
                'gateway_3d' => env('PAYTR_GATEWAY_3D', 'https://www.paytr.com/odeme'),
                'gateway_3d_host' => env('PAYTR_GATEWAY_3D_HOST', 'https://www.paytr.com/odeme/guvenli'),
            ],
        ],

        'param' => [
            'gateway' => ParamGateway::class,
            'merchant_id' => env('PARAM_CLIENT_CODE'),
            'username' => env('PARAM_CLIENT_USERNAME'),
            'password' => env('PARAM_CLIENT_PASSWORD'),
            'secret_key' => env('PARAM_GUID'),
            'test_mode' => env('PARAM_TEST_MODE', false),
            'endpoints' => [
                'payment_api' => env('PARAM_PAYMENT_API', 'https://posws.param.com.tr/turkpos.ws/service_turkpos_prod.asmx'),
            ],
        ],

        'tosla' => [
            'gateway' => ToslaGateway::class,
            'merchant_id' => env('TOSLA_CLIENT_ID'),
            'username' => env('TOSLA_API_USER'),
            'secret_key' => env('TOSLA_API_PASS'),
            'test_mode' => env('TOSLA_TEST_MODE', false),
            'endpoints' => [
                'payment_api' => env('TOSLA_PAYMENT_API', 'https://entegrasyon.tosla.com/api/Payment'),
                'gateway_3d' => env('TOSLA_GATEWAY_3D', 'https://entegrasyon.tosla.com/api/Payment/ProcessCardForm'),
                'gateway_3d_host' => env('TOSLA_GATEWAY_3D_HOST', 'https://entegrasyon.tosla.com/api/Payment/threeDSecure'),
            ],
        ],

        'paratika' => [
            'gateway' => ParatikaGateway::class,
            // Paratika terminolojisi: merchant_id => MERCHANT,
            // username => Merchant Api User e-postası, password => şifresi.
            // secret_key yalnızca 3D dönüş imzasını doğrulamak için kullanılır.
            'merchant_id' => env('PARATIKA_MERCHANT'),
            'username' => env('PARATIKA_MERCHANT_USER'),
            'password' => env('PARATIKA_MERCHANT_PASSWORD'),
            'secret_key' => env('PARATIKA_SECRET_KEY'),
            'test_mode' => env('PARATIKA_TEST_MODE', false),
            'endpoints' => [
                // Test ortamı: https://entegrasyon.paratika.com.tr
                'payment_api' => env('PARATIKA_PAYMENT_API', 'https://vpos.paratika.com.tr/paratika/api/v2'),
                // 3D Pay: doğrulama ve satış tek adımda.
                'gateway_3d' => env('PARATIKA_GATEWAY_3D', 'https://vpos.paratika.com.tr/paratika/api/v2/post/sale3d'),
                // Klasik 3D: yalnızca kimlik doğrulama, satış dönüşte yapılır.
                'gateway_3d_auth' => env('PARATIKA_GATEWAY_3D_AUTH', 'https://vpos.paratika.com.tr/paratika/api/v2/post/auth3d'),
                // Ortak ödeme sayfası.
                'gateway_3d_host' => env('PARATIKA_GATEWAY_3D_HOST', 'https://vpos.paratika.com.tr/payment'),
            ],
        ],

        'moka' => [
            'gateway' => MokaGateway::class,
            // Moka terminolojisi: merchant_id => DealerCode.
            // CheckKey bu üç değerden türetilir; ayrı bir gizli anahtar yoktur.
            'merchant_id' => env('MOKA_DEALER_CODE'),
            'username' => env('MOKA_USERNAME'),
            'password' => env('MOKA_PASSWORD'),
            'test_mode' => env('MOKA_TEST_MODE', false),
            'extra' => [
                // Havuz ödemesi: para çekilir ama onaylanana kadar bekletilir.
                'pool_payment' => env('MOKA_POOL_PAYMENT', false),
                // 3D sonucu IFrame içine dönecekse 1 yapın.
                'redirect_type' => env('MOKA_REDIRECT_TYPE', 0),
                'software' => env('MOKA_SOFTWARE', 'anadolupay'),
            ],
            'endpoints' => [
                // Test: https://service.refmokaunited.com
                'payment_api' => env('MOKA_PAYMENT_API', 'https://service.mokaunited.com'),
            ],
        ],

        'craftgate' => [
            'gateway' => CraftgateGateway::class,
            // Craftgate terminolojisi: username => API Key,
            // secret_key => Secret Key, password => 3D Secure Callback Key.
            // Son ikisi farklı anahtarlardır: ilki API isteklerini,
            // ikincisi 3D dönüşünü imzalar.
            'username' => env('CRAFTGATE_API_KEY'),
            'secret_key' => env('CRAFTGATE_SECRET_KEY'),
            'password' => env('CRAFTGATE_CALLBACK_KEY'),
            'test_mode' => env('CRAFTGATE_TEST_MODE', false),
            'extra' => [
                // Webhook imzası için panelden alınan Merchant Hook Key.
                'merchant_hook_key' => env('CRAFTGATE_HOOK_KEY'),
                // PRODUCT veya LISTING_OR_SUBSCRIPTION.
                'payment_group' => env('CRAFTGATE_PAYMENT_GROUP', 'PRODUCT'),
            ],
            'endpoints' => [
                // Sandbox: https://sandbox-api.craftgate.io
                'payment_api' => env('CRAFTGATE_PAYMENT_API', 'https://api.craftgate.io'),
            ],
        ],

    ],

    'iyzico' => [
        'api_key' => env('IYZICO_API_KEY'),
        'secret_key' => env('IYZICO_SECRET_KEY'),
        // Test ortamı için sandbox URL; canlı kullanımda prod host'u kullanın.
        'base_url' => env('IYZICO_BASE_URL', 'https://sandbox-api.iyzipay.com'),
        'callback_url' => env('IYZICO_CALLBACK_URL'),
        // Yanıt, callback ve webhook imzalarının doğrulanması.
        // Kapatmayın: kapalıyken sahte callback'lere açık olursunuz.
        'validate_signature' => env('IYZICO_VALIDATE_SIGNATURE', true),
        // Webhook imzasını taşıyan başlık (iyzico V3 imza şeması).
        'webhook_signature_header' => env('IYZICO_WEBHOOK_SIGNATURE_HEADER', 'x-iyz-signature-v3'),
        // Yanıt gövdesinde imzayı taşıyan alan.
        'signature_param' => env('IYZICO_SIGNATURE_PARAM', 'signature'),
    ],

];
