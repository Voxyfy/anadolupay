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

**Hangisini kullanın:** `4282209004348015` (Simulator, 08/27, CVV `123`).
2026-08-11'de sekiz kartla ölçüldü; 3D'siz satış, taksitli satış ve 3D tam
turu bu kartla sorunsuz geçti.

**`5549600732695519` (Bonus) kullanmayın** — internete kapalı, her istekte
`93` / `INTERNETTEN KULLANILAMAZ` döndürüyor. Tablodaki diğer kartlar
çalışıyor.

---

## Akbank (NestPay / Asseco)

**Kaynak:** dolaşımdaki NestPay örneklerinden derlendi, **ölçülerek
doğrulandı** (2026-08-11, `entegrasyon.asseco-see.com.tr`)

Bu bölüm `akbank` driver'ı içindir. Akbank'ın yeni JSON API'sini
(`akbank-pos`) kullanıyorsanız aşağıdaki Akbank Sanal POS bölümüne bakın.

**3D doğrulama şifresi:** `a`

| Kart numarası | Marka | SKT | CVV |
|---|---|---|---|
| `5571135571135575` | Mastercard | 12/26 | 000 |
| `4355084355084358` | Visa | 12/26 | 000 |

**Hangisini kullanın:** `5571135571135575`. Her iki test mağazasında da
(`100100000` ve `100200000`) çalışır. Visa kart `100100000`'de
`Kartin son kullanma tarihi hatali` veriyor, yalnızca `100200000`'de geçiyor.

Mastercard kartla dokuz akış ölçüldü ve hepsi `00` döndü: 3D başlatma,
3D'siz satış, taksitli satış, iptal, iade, kısmi iade, ön provizyon, kapama
ve sorgu.

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

**Kaynak:** [sanalpossandbox-test.vakifbank.com.tr](https://sanalpossandbox-test.vakifbank.com.tr/) —
resmî, giriş gerektirmez. Banka test üye işyerini ve kartlarını sayfada açıkça
yayınlıyor; başvuru gerekmiyor.

| Marka | Numara | CVV | SKT |
|---|---|---|---|
| VISA | `4355084000000001` | `000` | 12/29 |
| MasterCard | `5521010140829928` | `961` | 12/29 |

Test üye işyeri bilgileri:

```
MerchantId : 000000000007955
Password   : 123Ab456
TerminalNo : VP000123
```

Uçlar (test ortamı — *bu paketin varsayılanları canlı ortamı gösterir, test için
`.env` üzerinden ezin*):

```
Enrollment : https://inbound.apigatewaytest.vakifbank.com.tr:8443/threeDGateway/Enrollment
Provizyon  : https://apiportalprep.vakifbank.com.tr:8443/virtualPos/Vposreq
Sorgu      : https://apiportalprep.vakifbank.com.tr:8443/virtualPos/Search
```

**Bu ortamda gözlemlediğimiz tuhaflıklar** (hepsi canlı istekle doğrulandı):

- **MasterCard kartı yalnızca 3D akışında kullanılabilir.** Non-secure
  provizyonda CVV ne gönderilirse gönderilsin `0312` ("Kartın Cvv2 değeri
  hatalı", `ResultDetail` ise "RED-GEÇERSİZ KART") ile reddediliyor. Non-3D
  testleri VISA kartıyla yapın.
- **CVV bu ortamda doğrulanmıyor.** VISA kartı `000` ile de `961` ile de aynı
  şekilde `0000` döndürüyor. Yani `0312` hatası gerçekten CVV'yi işaret
  etmiyor — kart bazlı bir kısıt.
- **Son kullanma tarihi iki farklı biçimde gönderilir:** Enrollment `YYMM`
  (`2912`), provizyon `YYYYMM` (`202912`). Paket bunu zaten ayırıyor.
- Eski `4443` portlu uçlar (`3dsecuretest…/MPIAPI/MPI_Enrollment.aspx`) bu üye
  işyerini tanımıyor; **her isteğe**, hatta tutar alanı hiç yokken bile,
  `1008 Invalid money amount` döndürüyorlar. Kod ayırt edici değil, hata
  ayıklarken yanıltmasın.

Kılavuzun eski sürümünde geçen `4289450189088488` / statik 3D şifresi
`12ABCDEF` kartının son kullanma tarihi geçmiştir; yukarıdaki kartları kullanın.

---

## Akbank Sanal POS (JSON API)

**Kaynak:** [sanalposteststore-prep.akbank.com/paymentTest](https://sanalposteststore-prep.akbank.com/paymentTest) —
resmî test store, giriş gerektirmez.

| Marka | Numara | SKT | CVV |
|---|---|---|---|
| MasterCard | `5578293000121055` | 11/40 | `238` |

Test üye işyeri bilgileri:

```
Güvenli İşyeri Numarası : 2023090417500272654BD9A49CF07574
Güvenli Terminal No     : 2023090417500284633D137A249DBBEB
```

Secret Key aynı sayfada yayınlanıyor. Test uçları:

```
API : https://apipre.akbank.com/api/v1/payment/virtualpos
3D  : https://virtualpospaymentgatewaypre.akbank.com/securepay
Host: https://virtualpospaymentgatewaypre.akbank.com/payhosting
```

**Gözlemlediğimiz tuhaflıklar:**

- **İade ve iptal sipariş numarasıyla eşlenir**, banka referansıyla (`rrn`)
  değil. Yanlışı gönderilirse `VPS-1007 Orjinal İşlem bulunamadı` döner.
  Paket ödeme yanıtında sipariş numarasını `paymentId` olarak verir.
- **Tutarsız iade kabul edilmez**; alan `0.00` giderse banka `Hatalı Tutar`
  der. Paket tutar verilmediğinde kalanı işlem geçmişinden hesaplar.
- Akbank'ın yeni API'si **tekil durum sorgusu sunmaz**; yerine işlem geçmişi
  (`txnCode 1010`) vardır.

## QNB (PayFor)

**Kaynak:** QNB Sanal POS geliştirici dokümanı, "Üye İşyeri Test Ortamı" —
resmî, giriş gerektirmez. Doküman test kullanıcı bilgilerinin
**değiştirilmemesini** rica ediyor ve test siparişlerinin
`SiparişNumarası+Tarih` biçiminde üretilmesini istiyor.

"Demo Ortam Test Bilgileri" sayfasındaki kartlar:

| Marka | Numara | SKT | CVV |
|---|---|---|---|
| Visa | `4022780198283155` | 01/50 | boş geçilebilir |
| Troy | `9792091234123455` | 12/20 | `123` |

"Test Kartları" sayfasındaki tam liste:

| Marka | Numara | SKT | CVV |
|---|---|---|---|
| Visa | `4155650100416111` | 12/25 | `656` |
| Visa | `4282405990002166` | 12/25 | `656` |
| MasterCard | `5209882483498019` | 12/25 | `656` |
| MasterCard | `5456165456165454` | 12/25 | `656` |
| Troy | `36577312700094` | 08/20 | `483` |
| Troy | `9792350046201275` | 07/27 | `993` |
| Troy | `6501700194147183` | 03/23 | `136` |
| Troy | `9792023757123604` | 01/26 | `861` |
| Troy | `9792072000017956` | 01/20 | `843` |
| Troy | `6500528865390837` | 01/21 | `686` |

> Listedeki kartların çoğunun son kullanma tarihi geçmiştir. 2026-08-10'da
> yaptığımız denemede yukarıdaki dört Visa/MasterCard kartı ve demo
> sayfasındaki Visa kartı bankanın 3D doğrulama ekranını açtı; Troy kartlarını
> ölçemedik. SKT'si geçmemiş tek kart `4022780198283155`'tir.

Üç ayrı üye işyeri yayınlanıyor — her ödeme modeli için farklı hesap:

| Model | Merchant Code | API kullanıcı | API şifre | SecureType |
|---|---|---|---|---|
| 3D | `085300000009597` | `QNB_API_KULLANICI` | `FwCX2` | `Payfor3DModel` |
| 3D Pay | `085300000009704` | `QNB_API_KULLANICI_3DPAY` | `UcBN0` | `Payfor3DPay` |
| 3D Host | `085300000009746` | `QNB_API_KULLANICI_HOST` | `Rxs42` | `Payfor3DHost` |

Üçünde de `MerchantPass` (hash anahtarı) `12345678`, `MbrId` `5`.

Test uçları:

```
XML  : https://vpostest.qnb.com.tr/Gateway/XmlGate.aspx
3D   : https://vpostest.qnb.com.tr/Gateway/Default.aspx
3D Host: https://vpostest.qnb.com.tr/Gateway/3DHost.aspx
```

**Gözlemlediğimiz tuhaflıklar:**

- **QNB non-secure işlem sunmuyor**; 3D zorunlu. Doküman da yalnızca 3D
  entegrasyon bilgisi paylaşıldığını söylüyor.
- **Sorgu ucu (`SecureType=Inquiry`) API şifresini doğrulamıyor.** Kasten
  yanlış şifreyle de doğru şifreyle de aynı `V013 Seçili İşlem Bulunamadı!`
  yanıtı geliyor. Yani bu uçtan alınan yanıt, kimlik bilgilerinizin doğru
  olduğunu **kanıtlamaz**.
- 3D doğrulaması test ortamında bir simülatör sayfasında yapılır
  (`PayforACSSimulator`); sayfa SMS şifresi ister ve şifre dokümanda
  yayınlanmamıştır.

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

## NestPay (Asseco / Payten) — Ziraat ve ortak test ucu

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

Aynı kart ve akış diğer NestPay bankalarında da (Halkbank, QNB, TEB,
Şekerbank, ING, Alternatif Bank) geçerlidir; yalnızca uç nokta ve kimlik
bilgileri değişir.

### Ortak test ucundaki kartlar banka bağımsızdır

Ortak test ucundaki (`entegrasyon.asseco-see.com.tr`) kartlar büyük ölçüde
mağazadan bağımsızdır. 2026-08-11'de İş Bankası mağazasında altı kartın
altısı da `00` ile onaylandı — Akbank, Türkiye Finans ve Ziraat
dokümanlarından gelenler dahil:

| Kart numarası | Marka | Geldiği doküman |
|---|---|---|
| `5571135571135575` | Mastercard | Akbank |
| `4355084355084358` | Visa | Akbank |
| `5377195377190410` | Mastercard | Türkiye Finans |
| `4799174799173828` | Visa | Türkiye Finans |
| `5401341234567891` | Mastercard | Ziraat |
| `4546711234567894` | Visa | Ziraat |

Hepsinde CVV `000`, 3D şifresi `a`. **Son kullanma tarihi de denetlenmiyor:**
Türkiye Finans dokümanındaki geçmiş `12/22` tarihi bile kabul edildi. Yine de
`12/26` kullanın — bu davranış ortak test ucuna özgüdür, bankanın kendi
terminalinde geçerli olacağını varsaymayın.

**Hangisini kullanın:** `5571135571135575`. Akbank ve İş Bankası
mağazalarında hem 3D'siz satış hem de 3D tam turu bununla doğrulandı.

> **3D'siz geçmesi 3D'de de geçeceği anlamına gelmiyor.** Yukarıdaki altı
> kartın altısı da 3D'siz satışta `00` alıyor ve altısı da 3D formunda
> gateway'i geçip 3DS akışına giriyor — fark ancak directory server'da
> ortaya çıkıyor. Türkiye Finans kartı `5377195377190410` 3D turunda
> `mdStatus 5` / `Authentication unavailable (DS)` /
> `TDS2_transStatusReason: 08 – No Card record` veriyor: kart BIN düzeyinde
> kayıtlı görünüyor (`veresEnrolledStatus: Y`) ama DS'te kaydı yok. Bu
> **Türkiye Finans'ın kendi mağazasında da** böyle — yani bankanın kendi
> dokümanındaki kart, kendi test ortamında 3D doğrulamasından geçmiyor.
> Bu yüzden 3D ölçümü yapacaksanız kartı formun ilk adımına bakarak
> seçmeyin, turu sonuna kadar koşun.

Mağazaya özel bir istisna daha: `4355084355084358` İş Bankası mağazasında
geçerken Akbank'ın `100100000` mağazasında
`Kartin son kullanma tarihi hatali` veriyor.

### Doğrulanmış mağazalar

Bu üç banka kendi mağaza numaralarıyla ortak uçta ölçüldü — Ziraat
terminalini ödünç almanıza gerek yok:

| Banka | ClientId | API kullanıcı / şifre | Storekey | Ne kadarı ölçüldü |
|---|---|---|---|---|
| Akbank | `100100000` | `AKTESTAPI` / `AKBANK01` | `123456` | tamamı, 3D tam turu dahil |
| İş Bankası | `700655000200` | `ISBANKAPI` / `ISBANK07` | `TRPS0200` | tamamı, 3D tam turu dahil |
| Türkiye Finans | `280000100` | `TFKBAPI` / `TFKB2828` | `TRPS2828` | yalnızca 3D formu |

Türkiye Finans'ta yalnızca 3D adımı doğrulanabildi: API kullanıcısı bu
mağazada yetkili değil, provizyon ve sorgu istekleri `99 / Insufficent
permissions` dönüyor. Kimlikler bankanın kendi yayınladığı
[NestPay test dokümanından](https://www.turkiyefinans.com.tr/Documents/sanal_pos_asseco.pdf)
alındı; aynı dokümandaki `280000200` (3D_Pay mağazası) artık yok
(`mdStatus 6 / Invalid merchant assigned ID`).

> **`ISBANK07` storekey değildir, API şifresidir.** Dolaşımdaki
> yapılandırmalar bunu storekey sanıyor; öyle kullanılınca 3D formu her
> zaman `mdStatus 7 / Guvenlik Kodu hatali` veriyor. İş Bankası'nın
> storekey'i `TRPS0200`. Kalıp `TRPS` + dört hane olarak görünüyor
> (Türkiye Finans `TRPS2828`).

Akbank'ın kendi kart tablosu için yukarıdaki
[Akbank (NestPay / Asseco)](#akbank-nestpay--asseco) bölümüne bakın.

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

| Kart numarası | SKT | CVV | 3D doğrulama kodu |
|---|---|---|---|
| `5188961939192544` | 06/2029 | 588 | **`123456`** |

3D sayfasında istenen "Doğrulama Kodu" test ortamında sabittir: `123456`.

> **Dolaşımdaki `06/2025 · CVV 929` bilgisi eskimiştir.** Paratika'nın
> tablosunda öyle geçiyor ama o kartla 3D adımı sorunsuz geçilip
> **provizyon adımında** `ResponseCode: 54 Vade Sonu Geçmiş Kart` alınıyor —
> yani hata ancak akışın ikinci adımında görünüyor. Yukarıdaki `06/2029 · 588`
> değerleri mewebstudio/pos örneğinden alınmıştır.

Dokümanlarda dolaşan test üye işyeri bilgileri:

```env
KUVEYTTURK_MERCHANT_ID=496
KUVEYTTURK_USERNAME=apiuser1
KUVEYTTURK_SECRET_KEY=api123
KUVEYTTURK_CUSTOMER_ID=400235
KUVEYTTURK_PAYMENT_API=https://boatest.kuveytturk.com.tr/boa.virtualpos.services/Home
```

> 2026-08-09'da bu bilgilerle bankanın gerçek 3D sayfası alındı. İlk
> denemede `AssemblyNotFound` hatası gelmişti; sebep bankanın sunucusu değil,
> isteğin taban adrese gönderilmesiydi — BOA işlemleri `ThreeDModelPayGate`
> gibi ayrı uçlara gider. Paket bunu artık kendisi ekler.

---

## Ziraat Bankası (PayFlex)

**Kaynak:** İNNOVA "MPI + Sanal POS Entegrasyon Dokümanı (3D)" v4.1, 02/05/2026,
[sanalpos.innova.com.tr/ziraatbankasi](http://sanalpos.innova.com.tr/ziraatbankasi/) — resmî

Test uçları dokümanın "Erişim Bilgileri" bölümünden:

```env
ZIRAAT_PAYFLEX_MERCHANT_ID=000000000281567
ZIRAAT_PAYFLEX_PASSWORD=123456
ZIRAAT_PAYFLEX_TEST_MODE=true
ZIRAAT_PAYFLEX_PAYMENT_API=https://preprod.payflex.com.tr/Ziraatbank/VposWeb/v3/Vposreq.aspx
ZIRAAT_PAYFLEX_GATEWAY_3D=https://preprod.payflex.com.tr/ZiraatBank/MpiWeb/Enrollment.aspx
ZIRAAT_PAYFLEX_QUERY_API=https://preprod.payflex.com.tr/ZIRAATBANK/UIWebService/Search.aspx
# Preprod yavaştır; varsayılan 30 saniye yetmiyor.
ZIRAAT_PAYFLEX_TIMEOUT=120
```

> 2026-08-10'da ölçüldü: bu üye işyeri ve şifre **MPI ucunda kabul ediliyor**.
> Banka tam bir `VERes` döndürüyor (`PaReq`, `ACSUrl`, `TermUrl`, `MD`).

Bu ortamda dört ayrı tuzağa düştük; hepsi ölçülmüş:

- **Uç nokta adı.** Dolaşımdaki yapılandırmalar MPI için
  `MPI_Enrollment.aspx` veriyor, resmî doküman ise **`Enrollment.aspx`**
  diyor. Yanlış uçta banka, tanımadığı üye işyerine `1008 Invalid money
  amount` döndürüyor — tutarla hiç ilgisi yok. Tutar biçiminin altı ayrı hâli
  denendi, hata değişmedi. Doğru uca geçildiğinde aynı kimlik anında kabul
  edildi.
- **MPI şifresi ile VPOS şifresi ayrıdır.** Yukarıdaki şifre MPI'da çalışıyor
  ama VPOS (satış/iade/iptal/sorgu) ucunda `5001 İş yeri şifresi yanlış`
  veriyor. VPOS şifresi ve `TerminalNo` bankadan ayrıca istenmelidir;
  `TerminalNo`'nun üç ayrı değeri denendi, sonuç değişmedi.
- **Preprod yavaştır.** MPI isteği **46–62 saniye** sürüyor. Paketin 30
  saniyelik varsayılanı bunu kesiyor; preset'e `timeout` verin. Web
  sunucunuzun kendi sınırı da yeterli olmalı — nginx'in varsayılan
  `fastcgi_read_timeout` değeri 60 saniyedir ve PHP hâlâ çalışırken
  bağlantıyı kesip **502** döndürür.
- **Sorgu ucunun preprod adresi vardır.** Dolaşımdaki yapılandırmalar burada
  canlı adresi gösteriyor; doküman preprod adresini veriyor.

### Kart

**Bu ortamda 3D Secure'a kayıtlı bir test kartı bulunamadı.** Denenen kartlar
ve bankanın yanıtı:

| Kart numarası | Kaynak | Sonuç |
|---|---|---|
| `4546711234567894` | Ziraat NestPay | `Status N` — 3D'ye kayıtlı değil |
| `5549601963997012` | Innova 3D Pay test formu | `Status N` — 3D'ye kayıtlı değil |
| `5521010140829928` | VakıfBank PayFlex | `Status N` — 3D'ye kayıtlı değil |
| `4546720000621074` | Innova 3D Pay dokümanı | `007 Issuer Exception` (SKT 05/2026, eskimiş) |
| `4355084000000001` | VakıfBank PayFlex | `2029 Invalid pan` |
| `5401341234567891` | Ziraat NestPay | `2009 Brand not found` |

`Status N` "kart 3-D Secure programına dâhil değil" demektir; akış ACS
ekranına hiç gitmez. 3D'yi sonuna kadar götürmek için bankadan kayıtlı bir
test kartı istenmelidir.

> **`BrandName` alanını atlamayın.** PayFlex kart markasını bu alandan
> okuyor; `CardData`'ya `type` verilmezse alan hiç gönderilmez ve banka
> `2009 Brand not found` döndürebilir.

---

## Paycell (Turkcell)

**Kaynak:** [paycellapi.apidog.io](https://paycellapi.apidog.io/test-kredi-kartlar%C4%B1-1889591m0) — resmî

Paycell hem test kimlik bilgilerini hem de kart listesini **herkese açık**
yayınlıyor; başvuru gerekmiyor.

```env
PAYCELL_MERCHANT_CODE=9998
PAYCELL_APPLICATION_NAME=PAYCELLTEST
PAYCELL_APPLICATION_PWD=PaycellTestPassword
PAYCELL_SECURE_CODE=PAYCELL12345
PAYCELL_EULA_ID=17
PAYCELL_PAYMENT_API=https://tpay-test.turkcell.com.tr/tpay/provision/services/restful/getCardToken
PAYCELL_TOKEN_API=https://omccstb.turkcell.com.tr/paymentmanagement/rest/getCardTokenSecure
PAYCELL_GATEWAY_3D=https://omccstb.turkcell.com.tr/paymentmanagement/rest/threeDSecure
```

Doküman bu değerlerin "hem TEST hem PREPROD ortamları için geçerli" olduğunu
söylüyor.

### Kartlar

Resmî tablo uzun ama **kartların çoğunun son kullanma tarihi geçmiştir**
(2019–2023 arası). İleri tarihli olanlar:

| Kart numarası | Banka | SKT | CVC | 3D şifresi |
|---|---|---|---|---|
| `4355093000777068` | Akbank | 11/2040 | 238 | Şifresiz |
| `5578293000121055` | Akbank | 11/2040 | 313 | Şifresiz |
| `5200190006338608` | DenizBank | 01/2030 | 410 | `123456` |
| `5200190009721495` | DenizBank | 01/2030 | 462 | `123456` |
| `4546711234567894` | Ziraat | 12/2026 | 000 | `a` |
| `5401341234567891` | Ziraat | 12/2026 | 000 | `a` |

> **Varsayılan test üye işyerinin sınırı var.** 2026-08-10'da bu kartlarla
> kart token adımı sorunsuz geçildi (`Islem basarili`), fakat ödeme adımı
> `9998` numaralı ortak test üye işyerinde `4000 Bank error`, 3D sayfası ise
> "3D Sayfasına yönlendirilirken bir hata oluştu" veriyor. Kart tarafını
> uçtan uca denemek için Paycell'den kendi üye işyeri kodunuzu istemeniz
> gerekiyor (paycelldev@paycell.com.tr).

---

## Yapı Kredi (PosNet)

**Kaynak:** [POSNET ThreeD Secure XML Servis Entegrasyonu v2.0.1.5](https://yapikredipos.com.tr/getmedia/780c5f70-fb98-45ed-817e-fc56fce37810/POSNET-3D-Secure-Entegrasyonu-2-0-1-5.pdf) —
resmî, Yapı Kredi'nin kendi sitesi

Yapı Kredi, **üye işyeri bilgilerini yayınlıyor ama test kartlarını
yayınlamıyor.** Bu ayrım önemli: aşağıdaki terminale bağlanabilirsiniz, fakat
3D akışını sonuna kadar götürecek kartı bankadan istemeniz gerekir.

Dokümanın "Sample Data" sütununda açıkça verilen test terminali:

```env
YAPIKREDI_MERCHANT_ID=6706598320
YAPIKREDI_TERMINAL_ID=67005551
YAPIKREDI_POSNET_ID=9644
YAPIKREDI_SECRET_KEY=10,10,10,10,10,10,10,10
YAPIKREDI_PAYMENT_API=https://setmpos.ykb.com/PosnetWebService/XML
YAPIKREDI_GATEWAY_3D=https://setmpos.ykb.com/3DSWebService/YKBPaymentService
```

`ENCKEY` için doküman "şifreleme anahtarı (**test ortamı için sabittir**)"
diyor — yani bu değer size özel değil, test ortamının tamamında geçerli.

> 2026-08-10'da bu bilgilerle şifreleme adımı (`oosRequestData`) çağrıldı ve
> banka `approved=1` ile `data1`/`data2`/`sign` döndürdü. Doküman "test ortamı
> için de statik IP bildirilmelidir" dese de XML servisi tanımsız bir IP'den
> gelen isteği kabul etti. 3D doğrulama ve finansallaştırma adımlarında IP
> kontrolü çıkar mı, henüz ölçülmedi.

### Kart

| Kart numarası | SKT | CVC | Nereden |
|---|---|---|---|
| `5400637500005263` | `3012` (biçim **YYAA**) | `111` | Dokümanın örnek isteği |

Bu kart şifreleme adımını geçiyor, ancak **gerçek bir test kartı olduğu
doğrulanmadı** — doküman onu yalnızca XML örneğinde kullanıyor. Çalışan kart
setini `posnet.support@yapikredi.com.tr` adresinden test terminalinizle
birlikte istersiniz.

İki tuzak:

- **SKT biçimi `YYAA`**, yani önce yıl sonra ay: Aralık 2030 için `3012`.
  Ters yazarsanız `respCode 0150 PAKET HATALI (EXPDATE)` alırsınız — bu hatayı
  ölçtük.
- Doküman, `0150` hata kodu açıklamasında **test ortamında CVC olarak `XXX`**
  kullanıldığını söylüyor. Kart setiniz bu davranıştaysa sayısal CVC yerine
  `XXX` beklenir.

---

## Tami

**Kaynak:** [dev.tami.com.tr/test-kartlari](https://dev.tami.com.tr/test-kartlari)
— sayfa hangi senaryoyu (başarılı/başarısız/3D kodu) simüle ettiğini
belirtmiyor, yalnızca kart/banka/SKT/CVV bilgisini veriyor.

| Kart numarası | Banka | SKT | CVV | Not |
|---|---|---|---|---|
| `5406697543211173` | Garanti BBVA | 04/27 | 423 | Bonus Kredi Kartı |
| `5549605007824017` | Garanti BBVA | 12/25 | 460 | Bonus Kredi Kartı |
| `5549603469426017` | Garanti BBVA | 01/27 | 916 | Bonus Kredi Kartı |
| `5170404942561157` | Garanti BBVA | 10/25 | 329 | Paracard Bonus (banka kartı) |
| `5423740051890870` | Ziraat Bankası | 05/27 | 015 | |
| `4938410180801789` | VakıfBank | 12/29 | 767 | |
| `4155650100416111` | QNB Finansbank | 01/50 | 715 | Diğer sürücülerde de aynı numara geçiyor (bkz. NestPay bölümü) |
| — | Halkbank | 12/26 | 000 | Sayfada "birden fazla kart" deniyor, tekil numara verilmemiş |
| `4543600372218357` | İş Bankası | 09/27 | 448 | |
| `5127543000946141` | Akbank | 01/35 | 517 | |
| `4355084355084358` | TEB | 12/28 | 000 | Diğer sürücülerde de aynı numara geçiyor |

> ⚠️ Tami sürücüsü (`TamiGateway`) henüz gerçek bir sandbox'a karşı hiç
> çalıştırılmadı — `securityHash` imza formülü dokümantasyonun kendi
> içinde çelişkili (bkz. README'deki [Tami](../README.md#tami) bölümü).
> Bu kartlarla ilk denemede `4003 Headerda gönderilen hash değeri
> tutarsız` alırsanız bu bilinen risk gerçekleşmiş olabilir.

---

## Diğer bankalar

Aşağıdaki sağlayıcılar test kartlarını herkese açık yayınlamaz; kartlar test
üye işyeri bilgilerinizle birlikte verilir. Uydurma numara vermek yerine
kartı nereden alacağınızı yazıyoruz.

| Driver | Sağlayıcı | Test kartını nereden alırsınız |
|---|---|---|
| `akbank`, `isbank`, `ziraat`, `halkbank`, `qnb`, `teb`, `sekerbank` | Asseco / Payten (NestPay) | Bankanızın gönderdiği NestPay entegrasyon dokümanı. Test üye işyeri başvurusu banka şubesi veya POS ekibi üzerinden yapılır. |
| `yapikredi` | Yapı Kredi PosNet | Terminal bilgileri dokümanda yayınlı — bkz. [Yapı Kredi (PosNet)](#yapı-kredi-posnet); kart için `posnet.support@yapikredi.com.tr` |
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
