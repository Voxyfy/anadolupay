# Test Kartları

Türk banka sanal POS'larını denemek için kullanılan test kartları.

> **Bu kartlar yalnızca test ortamında çalışır.** Canlı uçlarda geçersizdirler
> ve gerçek bir karta ait değildirler.

---

## Önce şunu bilin

Türkiye'de test kartları iki farklı şekilde dağıtılır ve bu ayrım önemlidir:

**Ödeme kuruluşları** (iyzico, PayTR, Param, Tosla) test kartlarını **herkese
açık** yayınlar. Sandbox hesabı açar açmaz kullanabilirsiniz.

**Bankalar** test kartlarını genellikle **test üye işyeri bilgilerinizle
birlikte** verir. Kartlar kuruluma özel olabilir; internette bulduğunuz bir
liste sizin terminalinizde çalışmayabilir. Bankanızdan gelen entegrasyon
dokümanı her zaman önceliklidir.

Bu yüzden aşağıdaki tablolarda her sağlayıcının **kaynağı** belirtilmiştir.
Resmî kaynaktan doğrulanamayanlar için kartı nereden alacağınız yazılıdır —
uydurma numara vermek, çalışmayan bir kartla saat harcamanıza yol açar.

---

## iyzico

**Kaynak:** [docs.iyzico.com/en/add-ons/test-cards](https://docs.iyzico.com/en/add-ons/test-cards) — resmî

Son kullanma tarihi ve CVV serbesttir; biçim doğru ve tarih gelecekte olmalıdır.
Örnek: `12/2030`, `123`.

### Başarılı işlem kartları

| Kart numarası | Banka | Marka | Tip |
|---|---|---|---|
| `5890040000000016` | Akbank | Mastercard | Banka kartı |
| `5526080000000006` | Akbank | Mastercard | Kredi kartı |
| `9792072000017956` | Akbank | Troy | Kredi kartı |
| `4766620000000001` | DenizBank | Visa | Banka kartı |
| `4603450000000000` | DenizBank | Visa | Kredi kartı |
| `4987490000000002` | QNB | Visa | Banka kartı |
| `5311570000000005` | QNB | Mastercard | Kredi kartı |
| `9792020000000001` | QNB | Troy | Banka kartı |
| `9792030000000000` | QNB | Troy | Kredi kartı |
| `5170410000000004` | Garanti BBVA | Mastercard | Banka kartı |
| `5400360000000003` | Garanti BBVA | Mastercard | Kredi kartı |
| `374427000000003` | Garanti BBVA | Amex | Kredi kartı |
| `4475050000000003` | Halkbank | Visa | Banka kartı |
| `5528790000000008` | Halkbank | Mastercard | Kredi kartı |
| `4059030000000009` | HSBC | Visa | Banka kartı |
| `5504720000000003` | HSBC | Mastercard | Kredi kartı |
| `5892830000000000` | İş Bankası | Mastercard | Banka kartı |
| `4543590000000006` | İş Bankası | Visa | Kredi kartı |
| `4910050000000006` | VakıfBank | Visa | Banka kartı |
| `4157920000000002` | VakıfBank | Visa | Kredi kartı |
| `6500528865390837` | VakıfBank | Troy | Banka kartı |
| `6501700194147183` | VakıfBank | Troy | Kredi kartı |
| `5168880000000002` | Yapı Kredi | Mastercard | Banka kartı |
| `5451030000000000` | Yapı Kredi | Mastercard | Kredi kartı |

Yurt dışı kartları: `5400010000000004` (kredi), `4054180000000007` (banka).

### Hata senaryosu kartları

Hata yollarını denemek için bunlar paha biçilmezdir — mutlu yolun çalışması
entegrasyonun bittiği anlamına gelmez.

| Kart numarası | Simüle ettiği durum |
|---|---|
| `4111111111111129` | Yetersiz bakiye |
| `4129111111111111` | İşleme izin verilmedi (do not honour) |
| `4128111111111112` | Geçersiz işlem |
| `4127111111111113` | Kayıp kart |
| `4126111111111114` | Çalıntı kart |
| `4125111111111115` | Süresi dolmuş kart |
| `4124111111111116` | Geçersiz CVC |
| `4123111111111117` | Kart sahibine izin verilmiyor |
| `4122111111111118` | Terminale izin verilmiyor |
| `4121111111111119` | Şüpheli işlem (fraud) |
| `4120111111111110` | Karta el koy |
| `4130111111111118` | Genel hata |
| `4131111111111117` | Onaylandı ama `mdStatus=0` |
| `4141111111111115` | Onaylandı ama `mdStatus=4` |
| `4151111111111112` | 3D Secure başlatma başarısız |
| `5406670000000009` | Onaylandı ama iptal/iade/provizyon kapama yapılamaz |

`mdStatus` senaryoları özellikle değerlidir: paket yalnızca `mdStatus=1`'i tam
3D doğrulaması sayar (`AssecoGateway`), Garanti ve InterPos ise `1-4` arasını
kabul eder. Bu kartlar o ayrımı test etmenizi sağlar.

---

## Garanti BBVA

**Kaynak:** [dev.garantibbva.com.tr/test-kartlari](https://dev.garantibbva.com.tr/test-kartlari) — resmî

**3D OTP şifresi (tüm kartlar için):** `147852`

| Kart numarası | Marka | SKT | CVV |
|---|---|---|---|
| `4282209004348015` | Simulator | 08/27 | 123 |
| `5549600732695519` | Bonus | 04/30 | 244 |
| `4824898262197018` | Money | 05/31 | 529 |
| `9792290849783014` | Troy | 08/31 | 865 |
| `377599936020011` | SF Amex | 09/30 | 380 |
| `4329542729807013` | Shop&Fly | 08/29 | 908 |
| `4273149145316011` | Bonus | 01/30 | 115 |
| `5549602763692019` | Bonus | 05/30 | 775 |
| `375623530840012` | Amex | 05/29 | 4101 |
| `5549602257210013` | Bonus | 02/30 | 689 |
| `375623270949015` | Amex | 02/29 | 4440 |
| `4329542955012015` | SM | 02/29 | 863 |
| `9792290527103014` | Troy | 02/31 | 058 |
| `9792290529525016` | Troy | 05/31 | 836 |

Garanti kartlarında CVV kart başına farklıdır; tabloya birebir uyun.

---

## PayTR

**Kaynak:** [dev.paytr.com/en/direkt-api/test-kart-bilgileri](https://dev.paytr.com/en/direkt-api/test-kart-bilgileri) — resmî

Kart sahibi adı ve son kullanma tarihi serbesttir.

| Kart numarası | SKT | CVV | Ad |
|---|---|---|---|
| `4355084355084358` | 12/30 | 000 | PAYTR TEST |
| `5406675406675403` | 12/30 | 000 | PAYTR TEST |
| `9792030394440796` | 12/30 | 000 | PAYTR TEST |

Bu kartlar **Direkt API** çözümü içindir. iFrame API kullanıyorsanız PayTR test
kartlarını otomatik uygular.

PayTR test modunda `test_mode=1` gönderilir; paket bunu `test_mode`
yapılandırmasından okur:

```env
PAYTR_TEST_MODE=true
```

---

## VakıfBank (PayFlex)

**Kaynak:** [VakıfBank Sanal POS Entegrasyon Kılavuzu (PDF)](https://vbassets.vakifbank.com.tr/ticari/pos-uye-is-yeri-hizmetleri/vakifbank-sanal-pos-entegrasyon-dokumani-2.4-versiyon.pdf) — resmî

Entegrasyon kılavuzunda örnek 3D Secure test kartı olarak
`4289450189088488`, statik 3D şifresi `12ABCDEF` verilmiştir. Kılavuzdaki son
kullanma tarihi geçmiştir; güncel kart ve tarih için bankadan gelen test üye
işyeri paketine bakın.

---

## Paratika (Payten)

**Kaynak:** [docs.paratika.com.tr/test-kartlari](https://docs.paratika.com.tr/test-kartlari) — resmî, giriş gerektirmez

| Banka | Kart numarası | SKT | CVV |
|---|---|---|---|
| Ziraat | `4546711234567894` | 12/2026 | 000 |
| Ziraat | `5401341234567891` | 12/2026 | 000 |
| Akbank | `4355084355084358` | 12/2030 | 000 |
| Akbank | `5571135571135575` | 12/2030 | 000 |
| Akbank (Troy) | `9792072000017956` | 12/2027 | 000 |
| TEB | `4402934402934406` | 12/2030 | 000 |
| TEB | `5101385101385104` | 12/2030 | 000 |
| Halkbank | `4920244920244921` | 12/2030 | 001 |
| Halkbank | `5404355404355405` | 12/2030 | 001 |
| QNB Finansbank | `4022774022774026` | 12/2030 | 000 |
| QNB Finansbank | `5456165456165454` | 12/2030 | 000 |
| QNB Finansbank (Troy) | `9792350046201275` | 07/2027 | 993 |
| İş Bankası | `4508034508034509` | 12/2030 | 000 |
| İş Bankası | `5406675406675403` | 12/2030 | 000 |
| Anadolubank | `4258464258464253` | 12/2030 | 000 |
| Anadolubank | `5222405222405229` | 12/2030 | 000 |
| ING | `4555714555714556` | 12/2030 | 000 |
| ING | `5400245400245409` | 12/2030 | 000 |
| Garanti | `4824892919057014` | 12/2025 | 067 |
| Garanti | `5378297758742014` | 05/2025 | 467 |
| Garanti (Troy) | `9792052565200010` | 01/2027 | 327 |
| Yapı Kredi | `4506344103118942` | 12/2025 | 000 |
| Yapı Kredi | `5400617004770430` | 12/2025 | 000 |
| Yapı Kredi (Troy) | `6501617060023449` | 12/2026 | 000 |
| VakıfBank | `4938460158754205` | 01/2024 | 715 |
| VakıfBank | `4119790155203496` | 04/2024 | 579 |
| Kuveyt Türk | `5188961939192544` | 06/2025 | 929 |
| Türkiye Finans (Troy) | `9792182023832743` | 10/2028 | 878 |
| Şekerbank (Troy) | `6501750104751517` | 12/2027 | 516 |
| Alternatif Bank (Troy) | `36577312700094` | 12/2027 | 000 |
| HSBC | `5100051016005572` | 01/2020 | 742 |

Portaldaki tabloda Yapı Kredi için dört ayrı Visa, üç ayrı Master ve üç ayrı
Troy kartı daha var; taksit ve puan senaryolarını denemek isterseniz tam
listeye bakın. Bazı kartların son kullanma tarihi geçmiş (HSBC `01/2020`,
VakıfBank `01/2024`) — bunlar tabloda öylece duruyor, çalışmayan kartla
uğraşmayın.

```env
PARATIKA_PAYMENT_API=https://entegrasyon.paratika.com.tr/paratika/api/v2
PARATIKA_GATEWAY_3D=https://entegrasyon.paratika.com.tr/paratika/api/v2/post/sale3d
PARATIKA_GATEWAY_3D_AUTH=https://entegrasyon.paratika.com.tr/paratika/api/v2/post/auth3d
PARATIKA_GATEWAY_3D_HOST=https://entegrasyon.paratika.com.tr/payment
PARATIKA_TEST_MODE=true
```

> Test kimlik bilgileri açık değildir: Paratika panelinde hesap açıp
> **Merchant Api User** oluşturmanız gerekir. `PARATIKA_SECRET_KEY` ayrı bir
> değerdir ve yalnızca 3D dönüş imzasını doğrulamakta kullanılır.

---

## Moka United

**Kaynak:** [developer.mokaunited.com/home.php?page=test-kartlari](https://developer.mokaunited.com/home.php?page=test-kartlari) — resmî, giriş gerektirmez

Bu kartlarla yapılan ödemeler **bankaya gönderilmez**; yanıtı Moka'nın kendi
sistemi üretir. Hepsinin son kullanma tarihi `12/2030`, CVC'si `000`.

| Kart numarası | Banka | Tip |
|---|---|---|
| `5127541122223332` | Akbank | Master |
| `4531441122223338` | Aktif Bank | Visa |
| `4230021122223332` | Albaraka | Visa |
| `5126181122223338` | Alternatif Bank | Master |
| `4258461122223337` | Anadolu Bank | Visa |
| `5482021122223334` | Burgan Bank | Master |
| `4715091122223339` | Citi Bank | Visa |
| `5120171122223335` | Deniz Bank | Master |
| `4234951122223336` | Fibabanka | Visa |
| `4022771122223334` | Finansbank | Visa |
| `5269111122223332` | Finansbank | Master |
| `5269551122223339` | Garanti Bankası | Master |
| `4155141122223339` | Halkbank | Visa |
| `5100051122223333` | HSBC | Master |
| `4137291122223335` | ICBC | Visa |
| `5101511122223335` | ING | Master |
| `4397481122223337` | ININAL | Visa |
| `5406681122223338` | İş Bankası | Master |
| `4183441122223339` | İş Bankası | Visa |
| `5125951122223335` | Kuveyt Türk | Master |
| `4691801122223339` | Odeabank | Visa |
| `5313891122223335` | Papara | Master |
| `4349131122223337` | PTT Bank | Visa |
| `5100101122223336` | Şekerbank | Master |
| `4024591122223334` | TEB | Visa |
| `4347271122223333` | Turkcell | Visa |
| `5185991122223338` | Turkishbank | Master |
| `4007421122223335` | Türkiye Finans | Visa |
| `5313251122223332` | Türkpara | Master |
| `4029401122223331` | Vakıfbank | Visa |
| `5353551122223336` | Vakıf Katılım | Master |
| `4462121122223339` | Yapı Kredi | Visa |
| `5136621122223331` | Ziraat Bankası | Master |
| `4162831122223336` | Ziraat Katılım | Visa |
| `9792061122223337` | Ziraat Bankası | Troy |
| `6549971122223339` | İş Bankası | Troy |

> **Kart listede olması çalışacağı anlamına gelmez.** Test bayinizde her
> bankanın sanal POS'u tanımlı olmayabilir; tanımsız bir bankanın kartıyla
> ödeme başlatırsanız Moka
> `PaymentDealer.DoDirectPayment3dRequest.VirtualPosNotAvailable` döner.
> Hata kartın **bankasıyla** ilgilidir; tutar ve taksit sayısı etkilemez.
> 2026-08-09'da bir test bayisinde İş Bankası, Akbank ve Ziraat kartları
> çalışırken Garanti kartı bu hatayı verdi.

Portalda ikinci bir tablo daha var: o kartlarla yapılan işlemler **gerçekten
bankaya gider** ve yanıt bankadan döner. Uçtan uca 3D akışını denemek
istiyorsanız onları kullanın; günlük hayatta yukarıdaki liste yeterlidir.

Sandbox ayrı bir adres kullanır:

```env
MOKA_PAYMENT_API=https://service.refmokaunited.com
MOKA_DEALER_CODE=xxx
MOKA_USERNAME=xxx
MOKA_PASSWORD=xxx
MOKA_TEST_MODE=true
```

> Moka'da 3D dönüşünün başarılı olup olmadığı `resultCode` alanından
> **anlaşılmaz** — o alan başarılı işlemlerde boş gelir. Sonuç yalnızca
> `hashValue` içinde taşınır, o da ödeme başlatılırken dönen `CodeForHash`
> değerinden üretilir. Bu değeri saklamayı unutursanız test ortamında da
> canlıda da ödemenin sonucunu okuyamazsınız.

---

## Craftgate

**Kaynak:** [craftgate/craftgate-php-client](https://github.com/craftgate/craftgate-php-client/tree/master/samples),
[craftgate-java-client](https://github.com/craftgate/craftgate-java-client) ve
[craftgate-go-client](https://github.com/craftgate/craftgate-go-client) —
Craftgate'in kendi resmî istemci depolarındaki örnek ve test dosyaları

Craftgate'in kart tablosunun tamamı geliştirici portalında yayınlanıyor ancak
portal giriş istiyor: [developer.craftgate.io/en/test-cards](https://developer.craftgate.io/en/test-cards/).
Aşağıdaki kartlar portala girmeden doğrulanabilen tek kaynaktan — Craftgate'in
herkese açık istemci depolarından — alınmıştır.

| Kart numarası | SKT | CVV | Not |
|---|---|---|---|
| `5258640000000001` | 07/2044 | 000 | Craftgate'in tüm örneklerinde kullandığı varsayılan kart |
| `4256690000000001` | 11/2035 | 123 | Go istemcisinin ödeme testlerinde kullandığı kart |
| `5400010000000004` | 07/2044 | 000 | Java ve .NET örneklerinde kullanılan kart |
| `4043080000000003` | 07/2044 | 000 | Ödül/puan (loyalty) sorgusu için |

> Sandbox'ta **yalnızca** Craftgate'in tanımladığı test kartlarıyla ödeme
> yapılabilir; rastgele bir kart numarası reddedilir. Bankaya özel senaryolar
> (belirli hata kodları, taksit tabloları, ödül puanları) için portaldaki tam
> listeye ihtiyacınız olacak.

Sandbox ortamı ayrı bir uç nokta ve ayrı anahtar kullanır:

```env
CRAFTGATE_PAYMENT_API=https://sandbox-api.craftgate.io
CRAFTGATE_API_KEY=sandbox-api-key
CRAFTGATE_SECRET_KEY=sandbox-secret-key
CRAFTGATE_CALLBACK_KEY=merchantThreeDsCallbackKeySndbox
CRAFTGATE_TEST_MODE=true
```

`CRAFTGATE_CALLBACK_KEY`, API anahtarından **farklı** bir değerdir (panelde
"3D Secure Callback Key"). Boş bırakırsanız 3D dönüşü imza doğrulamasında
reddedilir.

---

## NestPay (Asseco / Payten) — Ziraat

**Kaynak:** Ziraat NestPay test terminali (`torus-stage-ziraat.asseco-see.com.tr`)
ve [Paratika'nın resmî test kartı tablosu](https://docs.paratika.com.tr/test-kartlari)
— aynı numaralar iki kaynakta da geçiyor.

| Kart numarası | SKT | CVV | Tip |
|---|---|---|---|
| `4546711234567894` | 12/2026 | 000 | Visa |
| `5401341234567891` | 12/2026 | 000 | Mastercard |

3D Secure adımında istenen SMS şifresi test terminallerinde **`a`**'dır.

> Bu kartların son kullanma tarihi **12/2026** — yani yakında geçecek.
> Reddedilmeye başlarlarsa bankadan güncel listeyi isteyin, kart numarasını
> tahmin etmeye çalışmayın.

Terminale erişim bilgileri bankadan gelir; `clientid` + `storekey` 3D formu
üretmeye yeter, provizyon ve sorgular ayrıca API kullanıcı adı/şifresi ister.

```env
ZIRAAT_MERCHANT_ID=190000300
ZIRAAT_SECRET_KEY=TEST1234
ZIRAAT_USERNAME=...api
ZIRAAT_PASSWORD=...
ZIRAAT_PAYMENT_API=https://torus-stage-ziraat.asseco-see.com.tr/fim/api
ZIRAAT_GATEWAY_3D=https://torus-stage-ziraat.asseco-see.com.tr/fim/est3Dgate
```

Aynı kart ve akış diğer NestPay bankalarında da (Akbank, İş Bankası,
Halkbank, QNB, TEB, Şekerbank, ING, Alternatif Bank, Türkiye Finans) geçerlidir;
yalnızca uç nokta ve kimlik bilgileri değişir.

---

## Tosla (AkÖde)

**Kaynak:** [tosla.com/isim-icin/gelistirici-merkezi](https://tosla.com/isim-icin/gelistirici-merkezi) — resmî

| Kart numarası | SKT | CVV |
|---|---|---|
| `4546711234567894` | 12/26 | 000 |
| `4531444531442283` | 12/26 | 001 |
| `5406675406675403` | 12/26 | 000 |

Test üye işyeri bilgileri de **açık yayınlanıyor**:

```env
TOSLA_CLIENT_ID=1000000494
TOSLA_API_USER=POS_ENT_Test_001
TOSLA_API_PASS=POS_ENT_Test_001!*!*
TOSLA_PAYMENT_API=https://prepentegrasyon.tosla.com/api/Payment
TOSLA_TEST_MODE=true
```

Adres erişiminde IP kontrolü yoktur; 2026-08-09'da bu bilgilerle 3D oturumu
açıldığı doğrulanmıştır.

> **Zaman damgası Türkiye saatinde olmalıdır.** Tosla `timeSpan` alanını
> GMT+3'te ve en fazla 1 saat farkla kabul eder. Uygulamanız UTC'de
> çalışıyorsa damga üç saat geride kalır ve **her istek**
> `998 Validasyon Hatası` ile reddedilir — mesaj sebebi söylemez. Paket
> damgayı uygulamanın saat diliminden bağımsız olarak İstanbul saatinde
> üretir.

---

## Kuveyt Türk (BOA)

**Kaynak:** Kuveyt Türk entegrasyon dokümanları ve
[Paratika'nın resmî tablosu](https://docs.paratika.com.tr/test-kartlari) —
aynı numara iki kaynakta da geçiyor.

| Kart numarası | SKT | CVV |
|---|---|---|
| `5188961939192544` | 06/2025 | 929 |

> **Son kullanma tarihi geçmiştir** (06/2025). Test ortamları tarihi
> doğrulamayabilir ama reddedilirse bankadan güncel kartı isteyin.

Dokümanlarda dolaşan test üye işyeri bilgileri:

```env
KUVEYTTURK_MERCHANT_ID=496
KUVEYTTURK_USERNAME=apiuser1
KUVEYTTURK_SECRET_KEY=api123
KUVEYTTURK_CUSTOMER_ID=400235
KUVEYTTURK_PAYMENT_API=https://boatest.kuveytturk.com.tr/boa.virtualpos.services/Home
```

> 2026-08-09'da denendi: istek kabul edilip imzalı bir yanıt üretildi, ancak
> banka `ResponseCode: AssemblyNotFound` döndürdü — "Call couldn't find the
> method in the orchestration assembly". Bu **bankanın test sunucusundaki**
> bir sorundur, istemci tarafından düzeltilemez. Kendi test terminaliniz
> varsa onu kullanın.

---

## Diğer bankalar

Aşağıdaki sağlayıcılar test kartlarını herkese açık yayınlamaz; kartlar test
üye işyeri bilgilerinizle birlikte verilir. Uydurma numara vermek yerine
kartı nereden alacağınızı yazıyoruz.

| Driver | Sağlayıcı | Test kartını nereden alırsınız |
|---|---|---|
| `akbank`, `isbank`, `ziraat`, `halkbank`, `qnb`, `teb`, `sekerbank` | Asseco / Payten (NestPay) | Bankanızın gönderdiği NestPay entegrasyon dokümanı. Test üye işyeri başvurusu banka şubesi veya POS ekibi üzerinden yapılır. |
| `yapikredi` | Yapı Kredi PosNet | YKB POS ekibi; test terminaliyle birlikte gelir |
| `albaraka` | Albaraka PosNet V1 | Albaraka e-POS başvurusu |
| `denizbank` | InterPos (Intertech) | DenizBank sanal POS sözleşmesi |
| `qnb-payfor`, `ziraat-katilim` | PayFor | [vpostest.qnb.com.tr](https://vpostest.qnb.com.tr) test terminali başvurusu |
| `kuveytturk` | Kuveyt Türk BOA | Kuveyt Türk üye işyeri portalı |
| `vakif-katilim` | Vakıf Katılım BOA | Vakıf Katılım POS ekibi |
| `akbank-pos` | Akbank (yeni JSON API) | Akbank geliştirici portalı |
| `param` | Param | [dev.param.com.tr/tr/test-kartlari](https://dev.param.com.tr/tr/test-kartlari) |
| `tosla` | Tosla (AkÖde) | Tosla İşim entegrasyon dokümanı |

> Bir bankanın kartını internette bulduysanız da önce bankanın size verdiği
> dokümanla karşılaştırın. Test terminalleri kuruluma göre farklı kart
> setleriyle tanımlanabiliyor.

---

## Sahte driver — karta hiç ihtiyaç duymadan

Akışı denemek için gerçek bir karta ve banka bağlantısına ihtiyacınız yok.
`fake` driver'ı 3D sayfasını kendisi üretir ve işlemleri hatırlar:

```php
$gateway = AnadoluPay::driver('fake');

$gateway->createPayment($data);                  // yerel 3D sayfası döner
$gateway->status('SIPARIS-123')->isPaid();       // true
$gateway->refund(new RefundPaymentData('SIPARIS-123'));
$gateway->status('SIPARIS-123')->isRefunded();   // true
```

Hata yollarını denemek için:

```php
config(['anadolupay.fake.success_rate' => 0]);   // her zaman başarısız
```

---

## Güvenlik

- Test kartlarını **canlı ortamda kullanmayın**; reddedilirler ve gereksiz
  başarısız işlem kaydı oluştururlar.
- Gerçek kart numaralarını test ortamına girmeyin. Test uçları PCI kapsamında
  değildir ve loglama politikaları farklıdır.
- Paketin istek/yanıt logları kart numarasını maskeler, CVV'yi gizler
  (bkz. README → Loglama). Yine de kendi loglarınızda `CardData` nesnesini
  `dd()` veya `var_dump()` ile basmayın — bunlar maskelemeden geçmez.

---

## Katkı

Bir sağlayıcının resmî test kartı listesini biliyorsanız kaynağıyla birlikte
PR açın. Kaynağı doğrulanamayan kart numaraları eklenmez: çalışmayan bir kart,
hiç kart olmamasından daha çok vakit kaybettirir.
