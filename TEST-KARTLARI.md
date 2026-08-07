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
