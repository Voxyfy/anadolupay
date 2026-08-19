# Changelog

`voxyfy/anadolupay` üzerindeki tüm kayda değer değişiklikler bu dosyada tutulur.

## v1.1.3 - 2026-08-19

### Sipariş numarası üretimi

`CreatePaymentData`'ya `orderId` boş geçilirse numara artık yapılandırmadan
üretiliyor: `ANADOLUPAY_ORDER_PREFIX=ODM-` ile `ODM-4KX9AB2Q7T`. Ödemeyi
başlatmadan önce numara gerekiyorsa (callback URL'i, kendi sipariş kaydı)
`AnadoluPay::orderId()` var.

Numara `A-Z0-9` ile sınırlı ve rastgele: bazı bankalar küçük harf/noktalama
içeren numarayı reddediyor, numara imzaya girdiği için de sonradan
düzeltilemiyor. Ön ek yalnızca harf, rakam, `-` ve `_` kabul ediyor; rastgele
bölüm 6 karakterin altına inemiyor.

Sipariş numarasını kendiniz veriyorsanız davranış değişmedi.

**Full Changelog**: https://github.com/Voxyfy/anadolupay/compare/v1.1.2...v1.1.3

## v1.1.2 - 2026-08-12

**Full Changelog**: https://github.com/Voxyfy/anadolupay/compare/v1.1.1...v1.1.2

## v1.1.1 - 2026-08-11

**Full Changelog**: https://github.com/Voxyfy/anadolupay/compare/v1.1.0...v1.1.1

## v1.1.0 - 2026-08-10

**Full Changelog**: https://github.com/Voxyfy/anadolupay/compare/v1.0.9...v1.1.0

## v1.0.9 - 2026-08-09

**Full Changelog**: https://github.com/Voxyfy/anadolupay/compare/v1.0.8...v1.0.9

## v1.0.8 - 2026-08-08

**Full Changelog**: https://github.com/Voxyfy/anadolupay/compare/v1.0.7...v1.0.8

## v1.0.7 - 2026-08-08

### What's Changed

* Bump actions/checkout from 6 to 7 by @dependabot[bot] in https://github.com/Voxyfy/anadolupay/pull/5

**Full Changelog**: https://github.com/Voxyfy/anadolupay/compare/v1.0.6...v1.0.7

## Yayımlanmamış

### Doğrulandı — İş Bankası ve Türkiye Finans (NestPay)

Akbank'ta işe yarayan yol iki bankada daha denendi: kendi mağaza numarasıyla
NestPay'in ortak test ucuna (`entegrasyon.asseco-see.com.tr`) bağlanmak.
`torus-stage-<banka>` host'u aramaya gerek yok — İş Bankası'nınki zaten
DNS'te yok.

**İş Bankası — uçtan uca.** Mağaza `700655000200`, API kullanıcısı
`ISBANKAPI` / `ISBANK07`, storekey `TRPS0200`. 3D Secure tam turu (3DS
2.2.0, `mdStatus 1`, provizyon `Approved`, sorgu `paid`, iade `Approved`)
örnek projeden tamamlandı; ayrıca 3D'siz satış, taksitli satış, iptal, iade,
kısmi iade, ön provizyon, kapama ve sorgu — hepsi `00`.

> **`ISBANK07` storekey değil, API şifresidir.** Dolaşımdaki
yapılandırmalar bunu storekey sanıyor; öyle kullanılınca 3D formu her
zaman `mdStatus 7 / Guvenlik Kodu hatali` veriyor ve hata kodda aranıyor.
Storekey `TRPS0200`. Kalıp `TRPS` + dört hane olarak görünüyor
(Türkiye Finans `TRPS2828`).

**Türkiye Finans — kısmen.** Kimlikler bankanın kendi yayınladığı NestPay
test dokümanından alındı (mağaza `280000100`, `TFKBAPI` / `TFKB2828`,
storekey `TRPS2828`). 3D formu bankaca kabul edilip 3DS akışına giriyor ve
storekey doğrulandı — yanlış anahtar `mdStatus 7` veriyor. Provizyon, iade,
iptal ve sorgu ölçülemedi: API kullanıcısı bu mağazada yetkili değil, her
istek `99 / Insufficent permissions` dönüyor. Kullanıcı adı, şifre, kart ve
mağaza numarasının yedi çeşitlemesinde de aynı yanıt geldi. Dokümandaki
ikinci mağaza `280000200` artık yok (`mdStatus 6`).

**Kartlar hakkında.** Ortak test ucundaki kartlar büyük ölçüde mağazadan
bağımsız: İş Bankası mağazasında Akbank, Türkiye Finans ve Ziraat
dokümanlarından gelen altı kartın altısı da 3D'siz satışta `00` aldı, hatta
Türkiye Finans dokümanındaki geçmiş `12/22` tarihi bile kabul edildi.
**Ama 3D'de durum farklı:** altı kart da gateway'i geçip 3DS akışına
giriyor, fark ancak directory server'da ortaya çıkıyor. Türkiye Finans kartı
`5377195377190410`, hem İş Bankası hem **Türkiye Finans'ın kendi**
mağazasında `mdStatus 5 / Authentication unavailable (DS)` veriyor
(`TDS2_transStatusReason: 08 – No Card record`) — bankanın kendi
dokümanındaki kart, kendi test ortamında 3D doğrulamasından geçmiyor.
3D ölçümünde kartı formun ilk adımına bakarak seçmeyin; ölçüm için
`5571135571135575` kullanın.

### Doğrulandı — Akbank (NestPay) kendi test mağazasında

`akbank` driver'ı bugüne kadar yalnızca Ziraat'in terminalinde ölçülmüştü ve
tabloda `⏳ ortak driver` olarak duruyordu. Akbank'ın kendi test mağazası
NestPay'in ortak test ucunda (`entegrasyon.asseco-see.com.tr`) tanımlıymış;
driver bu mağazaya karşı çalıştırıldı ve **bütün akışlar geçti**: 3D Secure
tam turu (3DS 2.2.0, ACS doğrulaması `mdStatus 1`, callback hash'i
doğrulandı, provizyon `Approved / 00`), 3D'siz satış, taksitli satış, iptal,
iade, kısmi iade, ön provizyon, kapama ve sorgu (ödenmiş sipariş `paid`,
iptal edilen `cancelled` döndü). Kodda değişiklik gerekmedi.

> Sorgu, ödemenin hemen ardından çağrılırsa `found: false` dönebiliyor —
NestPay kaydı henüz yazmamış oluyor. Birkaç saniye sonra aynı sipariş
`paid` görünüyor. Callback'te senkron sorgu yapıyorsanız buna dikkat.

Kullanılan değerler README'ye yazıldı: mağaza `100100000` (`100200000` de
çalışıyor), kullanıcı `AKTESTAPI`, şifre `AKBANK01`, storekey `123456`.
Storekey tahmin edilmedi, ölçülerek ayrıldı: yalnızca `123456` ACS'ye
geçiyor, denenen diğer değerler `mdStatus 7 / Guvenlik Kodu hatali` veriyor.

Ayrıca `torus-stage-akbank.asseco-see.com.tr` DNS'te yok — Ziraat için
işleyen `torus-stage-<banka>` kalıbı Akbank'ta geçerli değil, ama gerek de
kalmadı.

### Düzeltildi — NestPay sorgusunda maskeli kart hep boş dönüyordu

`AssecoGateway::status()` maskeli kartı `MaskedPan` alanından okumaya
çalışıyordu; oysa NestPay sorgu yanıtında kart `Extra.PAN` içinde gelir,
`MaskedPan` yalnızca ödeme yanıtlarında bulunur. Üstelik okuma
`Extra.NUMCODE` boşsa koşuluna bağlıydı ve `NUMCODE` her yanıtta dolu
geliyordu — yani `StatusResponse::$maskedCardNumber` NestPay ailesinin
**on bankasında da** her zaman `null` kalıyordu.

Akbank'ın test mağazasında ortaya çıktı: `Extra.PAN` = `5571 13** **** 5575`
dolu gelirken alan boştu. Artık `Extra.PAN` okunuyor, bulunamazsa
`MaskedPan`e düşülüyor. Kart bankanın döndürdüğü biçimde bırakılıyor —
NestPay araya boşluk koyar, sıkıştırılmıyor.

### Düzeltildi — Akbank 3D Host varsayılanı

`akbank` preset'inin `gateway_3d_host` varsayılanı
`https://sanalpos.sanalakpos.com.tr/fim/est3Dgate` idi; bu host DNS'te
çözülmüyor. NestPay ailesindeki diğer dokuz bankanın hiçbirinde
`gateway_3d_host` varsayılanı yoktu, yalnızca Akbank'ta vardı ve yanlıştı.
Varsayılan kaldırıldı; `AKBANK_GATEWAY_3D_HOST` ile geçilebilir.

### Doğrulandı — Garanti BBVA test ortamı

Garanti, test üye işyeri bilgilerini geliştirici portalında açıkça yayınlıyor
(MerchantID `7000679`, TerminalID `30691297`, `PROVAUT`/`PROVRFN`, StoreKey
`12345678`). Driver bu terminale karşı çalıştırıldı ve **uçtan uca gerçek bir
ödeme tamamlandı**; kodda değişiklik gerekmedi.

Geçen akışlar:

- **3D Secure tam turu.** Form bankaca kabul edildi, ACS'ye yönlendi
  (3DS 2.1.0, `creq`), doğrulama `mdstatus 1 / Authenticated` döndü,
  callback hash'i `checkCallbackHash`'ten geçti, provizyon `00 Approved`
  verdi ve `orderinq` siparişi `paid` olarak gördü.
- **3D'siz satış.** Sekiz resmî test kartının yedisi `00` ile onaylandı.
- **Taksitli satış**, **sipariş sorgulama** (`orderinq`) ve **hareket
  dökümü** (`orderhistoryinq`).

Ölçüm dört şeyi ortaya çıkardı ve bunlar README'ye yazıldı:

- **İade ve iptal bu test terminalinde reddediliyor** (`05` / `RPC-05`).
  İstek biçiminin on dört çeşitlemesi denendi — `void` ve `refund`, tam ve
  kısmi tutar, `OriginalRetrefNum` ile ve olmadan (İptal dokümanının
  tablosunda bu alan yok), `CardholderPresentCode` 0 ve 13, `<Card>` bloğu
  ile ve olmadan — hepsi aynı yanıtı verdi. Kimlik doğru kabul ediliyor:
  aynı isteği `PROVAUT` ile göndermek `92 / 0652 "yetkiniz yok"` veriyor,
  `PROVRFN` ile host'a ulaşıp `RPC-05` alıyor. Ret banka tarafında.
  Ön provizyon da `14` veriyor.
- **Kart `5549600732695519` internete kapalı** (`93` /
  `INTERNETTEN KULLANILAMAZ`); ölçüm için `4282209004348015` kullanılmalı.
- **`secure3dsecuritylevel` için `3D` değeri çalışıyor.** Garanti'nin form
  örneği yalnızca `CUSTOM_PAY`, `3D_PAY`, `3D_FULL`, `3D_HALF` sayıyor ama
  driver'ın gönderdiği `3D` de ACS'ye ulaşıyor. `CUSTOM_PAY` ise bu
  terminalde `Isyeri Kullanim Tipi Desteklenmiyor` veriyor.
- **Test ortamı arıza yapabiliyor.** Ölçümün ilk yarısında `gt3dengine` her
  forma HTTP 500, `VPServlet` her XML'e `92 / 9999` döndürdü — kasten bozuk
  hash ile doğru hash **birebir aynı** yanıtı verdi, yani istek hash
  kontrolüne hiç ulaşmıyordu. Aynı istekler yarım saat sonra değişmeden
  çalıştı. Sabit yanıt görürseniz kodu değil ortamı şüpheli sayın; uydurma
  bir terminal numarası anlamlı hata döndürüyorsa ortam sağlıklıdır.

### Belgelendi — Ziraat Bankası PayFlex test ortamı

İNNOVA'nın Ziraat için yayınladığı entegrasyon dokümanı (MPI + Sanal POS v4.1)
test uçlarını ve bir üye işyeri numarasını açıkça veriyor. Driver bu ortama
karşı çalıştırıldığında 3D başlatma adımı geçti: banka üye işyeri ve şifreyi
kabul edip tam bir `VERes` döndürdü (`PaReq`, `ACSUrl`, `TermUrl`, `MD`).

Kodda değişiklik gerekmedi; ölçüm dört ayrı tuzağı ortaya çıkardı ve bunlar
`TEST-KARTLARI.md`'ye yazıldı:

- **MPI ucunun adı `Enrollment.aspx`**, dolaşımdaki yapılandırmalardaki
  `MPI_Enrollment.aspx` değil. Yanlış uçta banka `1008 Invalid money amount`
  döndürüyor — tutarla ilgisi yok, tutar biçiminin altı hâli denendi. Doğru
  uçta aynı kimlik anında kabul edildi.
- **MPI şifresi ile VPOS iş yeri şifresi ayrı kimliklerdir.** MPI'da çalışan
  şifre VPOS'ta `5001 İş yeri şifresi yanlış` veriyor; satış, iade ve iptal
  için bankadan ayrıca şifre ve `TerminalNo` alınmalı.
- **Preprod yavaştır: MPI isteği 46–62 saniye sürüyor.** Paketin 30 saniyelik
  varsayılanı yetmiyor, preset'e `timeout` verilmeli. Web sunucusunun kendi
  sınırı da unutulmamalı — nginx'in varsayılan 60 saniyesi PHP hâlâ
  çalışırken bağlantıyı kesip 502 döndürüyor.
- **Sorgu servisinin preprod adresi vardır**; dolaşımdaki yapılandırmalar
  burada yanlışlıkla canlı adresi gösteriyor.

Bu ortamda **3D Secure'a kayıtlı test kartı bulunamadı**: denenen altı kart
`Status N` ("kart 3-D Secure programına dâhil değil") ya da kart hatası
verdi, dolayısıyla akış ACS ekranına hiç ulaşmıyor.

### Eklendi — Paycell (Turkcell) driver'ı

Paycell'in ayırt edici yanı, kart bilgisinin ödeme ucuna hiç gitmemesi: önce
ayrı bir uçtan kart token'ı alınır, ödeme o token ile yapılır. Driver bu iki
adımı, 3D oturumunu, iadeyi, iptali, ön provizyon kapamasını ve durum
sorgusunu kapsar.

İmza iki aşamalıdır ve girdinin **tamamı büyük harfe çevrilir**:

```
securityData = base64( sha256( UPPER( applicationPwd + applicationName ) ) )
hashData     = base64( sha256( UPPER( applicationName + transactionId
                                      + transactionDateTime
                                      + [responseCode] + [cardToken]
                                      + secureCode + securityData ) ) )



```
**Yanıt imzası ölçüldü.** Sağlayıcının test ortamından alınan gerçek bir kart
token yanıtındaki `hashData`, formülümüzle birebir eşleşti ve test vektörü
olarak kilitlendi. Ölçüm sırasında protokolün üç ayrıntısı da doğrulandı:
`transactionId` 20 hane olmalı (kısa gönderildiğinde `80003`), imzalanan
alanlar `header` altında ama `hashData` yanıtın kökünde geliyor, ve imza
istekte gönderilen değil **yanıtta dönen** `transactionId` ile hesaplanıyor.

3D oturumu da gerçek ortamda açıldı. Ödeme adımı doğrulanamadı: dokümanın
yayınladığı ortak test üye işyeri (`9998`) kart provizyonunda `4000 Bank error` döndürüyor.

### Düzeltildi — Yapı Kredi PosNet, bankanın test ortamına karşı

Yapı Kredi entegrasyon dokümanı test terminalini (üye işyeri, terminal ve
POSNET numarası ile şifreleme anahtarı) açıkça yayınlıyor; anahtar için
"test ortamı için sabittir" deniyor. Driver bu ortamda koşulduğunda iki
gerçek kusur çıktı:

- **Para birimi yanlış biçimde gidiyordu.** Alan ISO sayısal kodu (`949`)
  taşıyordu, oysa PosNet kendi iki harfli kısaltmasını bekliyor ve banka
  `E190 CurrencyCode hatalı` döndürüyordu. Artık `TRY → TL`, `USD → US`,
  `EUR → EU` çevrimi yapılıyor; desteklenmeyen para biriminde sessizce banka
  hatasına düşmek yerine açık bir hata veriliyor.
- **3D dönüşünün çözümleme isteği imzasız gidiyordu.** `oosResolveMerchantData`
  çağrısındaki `mac` değeri banka dönüşünden okunmaya çalışılıyordu, oysa banka
  o alanı hiç göndermiyor — doküman bu değerin üye işyeri tarafından
  hesaplanmasını istiyor. Boş gönderildiğinde banka `E216 Mac Doğrulama hatalı`
  diyordu. Artık sipariş numarası, tutar, para birimi, üye işyeri numarası ve
  güvenlik verisinden hesaplanıyor.

Doğrulanan adımlar: kart verilerinin şifrelenmesi (`approved=1`), 3D formunun
bankaya POST edilmesi, bankanın gerçek ACS sayfasının açılması ve dönüş
paketinin `oosResolveMerchantData` ile çözülmesi.

**Dönüş imzası ölçüldü.** Bankanın ürettiği `mac`, formülümüzle birebir
eşleşti; gerçek dönüşten alınan değer test vektörü olarak kilitlendi.

Finansallaştırma (satış, iade, iptal) ölçülemedi: banka bu işlemler için IP
tanımlaması istiyor (`0148 UNAUTHORIZED REQUEST`) ve dolaşımdaki test
kartlarının tamamı eskimiş.

### Düzeltildi — Akbank Sanal POS canlı test ortamına karşı

Akbank'ın test store'u üye işyeri bilgilerini ve test kartını yayınlıyor.
Driver bu ortamda çalıştırıldığında iki gerçek kusur çıktı:

- **Ödeme yanıtındaki `paymentId` ile iade/iptal yapılamıyordu.** Alan banka
  referansını (`rrn`) taşıyordu, oysa Akbank bu işlemleri sipariş numarasıyla
  eşliyor ve yanlışı gönderildiğinde `VPS-1007 Orjinal İşlem bulunamadı`
  döndürüyor. `paymentId` artık sipariş numarasıdır; `rrn` ham yanıtta
  duruyor. Eski kayıtlar için `metadata['order_id']` da okunuyor.
- **Tutarsız iade `0.00` gönderiyordu** ve banka `Hatalı Tutar` diyordu.
  Tutar verilmediğinde işlem geçmişinden (satış ve ön provizyonlar eksi
  önceki iade ve iptaller) kalan tutar hesaplanıyor; hesaplanamazsa açık bir
  hata veriliyor. Aynı çözüm provizyon kapamasına da uygulandı.

Doğrulanan işlemler: **tarayıcıyla tamamlanan 3D Secure satış** ve onun
iptali, non-3D satış, kısmî iade, tutarsız tam iade, kısmî iadeden sonra
kalanın iadesi, iptal, ön provizyon, tutarlı ve tutarsız kapama, işlem
geçmişi.

Dönüş imzası da ölçüldü: banka `hashParams` alanında hangi alanların hangi
sırayla imzalandığını bildiriyor; bu alanların değerleri ayraçsız birleştirilip
secretKey ile HMAC-SHA512'lendiğinde bankanın gönderdiği hash birebir çıktı.
Test vektörü olarak kilitlendi.

### Doğrulandı — QNB (PayFor) uçtan uca

QNB, sanal POS demo ortamının üye işyeri bilgilerini ve test kartlarını
dokümanında açıkça yayınlıyor. Driver bu ortamda tarayıcıyla tamamlanan bir
3D Secure satışla doğrulandı (`ProcReturnCode 00`, "Onaylandı"); ardından
durum sorgusu (`paid` → iptal sonrası `cancelled`) ve iptal da çalıştı.

**Dönüş imzası artık ölçülmüş durumda.** Bankanın ürettiği `ResponseHash`,
iki ayrı gerçek dönüşte formülümüzle birebir eşleşti; biri test vektörü olarak
kilitlendi. Gerçek dönüşte `AuthCode` alanı `null` geliyor (Laravel'in
`ConvertEmptyStringsToNull` middleware'i) — NestPay'dekiyle aynı tuzak; boş
dizgi sayılmasaydı imza hiçbir zaman tutmazdı.

Sağlayıcı sınırı olarak görüldü ve belgelendi: **aynı gün içindeki işlem iade
edilemiyor**, banka `V014` ile "asıl işlemi iptal edin" diyor. Aynı gün için
`cancel()` kullanılmalı.

Test ortamına dair iki not `TEST-KARTLARI.md`'ye eklendi: demo sayfasındaki
`4022780198283155` kartı provizyonda `96` ile reddediliyor — "Test Kartları"
sayfasındaki kartları kullanın; ve sorgu ucu (`SecureType=Inquiry`) API
şifresini doğrulamıyor, yanlış şifreyle de aynı yanıtı verdiği için oradan
alınan sonuç kimlik bilgilerinin doğruluğunu kanıtlamaz.

### Düzeltildi — VakıfBank (PayFlex) canlı sandbox'a karşı

VakıfBank, sanal POS sandbox'ını test üye işyeri ve kartlarıyla birlikte
herkese açık yayınlıyor ([sanalpossandbox-test.vakifbank.com.tr](https://sanalpossandbox-test.vakifbank.com.tr/)). Driver bu
ortama karşı çalıştırıldığında üç gerçek kusur çıktı:

- **Enrollment isteği yanlış kodlanıyordu.** Servis düz form alanı beklerken
  `prmstr` içinde XML gönderiliyordu; banka alanları hiç okumadan yanıltıcı
  bir `2030 Invalid expire date` döndürüyordu. Artık düz form gönderiliyor.
  
- **`MerchantType` varsayılan olarak `0` gönderiliyordu**; banka yalnızca `1`
  (ana bayi) ve `2` (alt bayi) tanır. Alan artık yalnızca alt bayi
  yapılandırıldığında, `SubMerchantId` ile birlikte gönderiliyor.
  
- **Durum sorgusu her zaman `unknown` döndürüyordu.** Yanıtta `TransactionStatus`
  diye bir alan yok; durum `IsCanceled`, `IsReversed`, `IsRefunded`,
  `TotalRefundAmount` ve `IsCaptured` bayraklarından türetiliyor. Maskeli kart
  da okunmuyordu (`PanMasked`).
  
- **3D provizyonu kart bilgisi istiyordu.** Banka işlemi `MpiTransactionId`
  üzerinden bulur; kart zorunlu değil, hatta bazı kurulumlar gönderilmesini
  `1127` ile reddeder. `order['card']` artık isteğe bağlı — verilmezse kart
  alanları hiç gönderilmiyor. Böylece kart numarasını 3D dönüşü için istekler
  arasında saklama zorunluluğu kalktı. Tutar da aynı şekilde isteğe bağlı oldu.
  

Ayrıca 3D akışı için: bankanın BKM "GO Güvenli Öde" kurulumunda `PaReq`, klasik
bir 3DS bloğu değil, kendi kendini gönderen bir HTML sayfasının base64'üdür.
Doğrulama sayfası `ACSUrl`'de değil o sayfanın form hedefindedir; `ACSUrl`'e
POST edildiğinde banka "400 Hatalı İstek" sayfası döndürür. Driver artık bunu
ayırt edip doğru formu üretiyor — klasik `PaReq` gelen kurulumlarda davranış
değişmedi.

Doğrulanan işlemler: **tarayıcıyla tamamlanan 3D Secure satış** (2026-08-10),
non-3D satış, durum sorgusu, iade (kısmî ve tam), iptal, ön provizyon ve kapama.

Ortama dair iki not `TEST-KARTLARI.md`'ye eklendi: yayınlanan MasterCard
yalnızca 3D akışında geçiyor (non-secure provizyonda CVV'den bağımsız olarak
`0312` ile reddediliyor) ve eski `4443` portlu uçlar bu üye işyerini tanımayıp
her isteğe — tutar alanı hiç yokken bile — `1008 Invalid money amount`
döndürüyor.

### Doğrulandı — Moka uçtan uca

Moka test servisinde tarayıcıyla tamamlanan bir 3D Secure satış driver'ı
uçtan uca doğruladı: `DoDirectPaymentThreeD` ile bağlantı alındı, 3D
tamamlandı, dönüş `sha256(CodeForHash + "T")` ile doğrulandı ve durum
sorgusu ödemeyi `paid` olarak raporladı.

Sağlayıcı sınırı olarak görüldü: test bayisinde her bankanın sanal POS'u
tanımlı değil. Tanımsız bir bankanın kartıyla ödeme `VirtualPosNotAvailable`
verir; hata kartın bankasıyla ilgilidir, tutar ve taksit etkilemez.
Belgelendi.

### Düzeltildi — Tosla taksit sorgusu hiç sonuç döndürmüyordu

Driver yanıtı `Installments` / `Count` / `TotalAmount` alanlarında arıyordu;
Tosla'nın gerçek yanıtı ise şöyle:

* `GetInstallmentOptions` → `InstallmentOptions: [{Installment, Title, Amount}]`
  (tutar kuruş cinsinden, komisyon dâhil)
* `GetCommissionAndInstallmentInfo` → `CommissionPackages[].InstallmentRate`
  altında `T2`, `T3`… anahtarları; anahtarın sayısal kısmı taksit sayısıdır

Sorgu bu yüzden her zaman boş liste döndürüyordu. Eşleme gerçek yanıta göre
yeniden yazıldı ve canlı test ortamında doğrulandı: BIN'siz sorgu 12 seçenek
(2 taksit → 101,01 TL), BIN'li sorgu 11 oran (Ziraat Bankası) döndürüyor.

Eski birim testi uydurma bir şema varsaydığı için yeşil kalıyordu; gerçek
alan adlarıyla değiştirildi.

### Doğrulandı — Tosla uçtan uca

Düzeltmeden sonra Tosla'nın gerçek test ortamında tarayıcıyla tamamlanan bir
3D Secure satış driver'ı uçtan uca doğruladı: oturum açıldı, 3D geçildi ve
dönüş imzası doğrulandı (`MdStatus: 1`, `BankResponseCode: 00`).

Aynı oturumda durum sorgusu (`paid`, 100,00 TL), **iade** ve **iptal** de
gerçek ortamda çalıştırıldı; ikisi de `Code: 0 Başarılı` döndü.

Dönüş bağımsız olarak yeniden hesaplanıp bire bir eşleştiği görüldü ve kalıcı
test vektörü olarak eklendi. Yükte `BankResponseMessage` **null** geliyor;
Tosla bu alanı hash'e boş dizgi olarak katıyor — atlanırsa imza tutmaz.

### Düzeltildi — Tosla UTC'de çalışan uygulamalarda hiç çalışmıyordu

Tosla `timeSpan` alanını **GMT+3'te ve en fazla 1 saat farkla** kabul ediyor.
Driver `date('YmdHis')` kullandığı için uygulama UTC'de çalışırken üç saat
geride bir damga üretiyor ve **her istek** `998 Validasyon Hatası` ile
reddediliyordu. Hata mesajı sebebi söylemiyor, yalnızca genel doğrulama
hatası dönüyor — bu yüzden kimlik bilgilerinin geçersiz olduğu sanılabilir.

Damga artık uygulamanın saat diliminden bağımsız olarak `Europe/Istanbul`
üretiliyor. UTC'de çalışan bir uygulamadan gerçek test ortamında 3D oturumu
açılarak doğrulandı; regresyon testi damganın UTC olmadığını da kontrol eder.

`TEST-KARTLARI.md` dosyasına Tosla'nın resmî test kartları ve açık yayınlanan
test üye işyeri bilgileri eklendi.

### Değişti — Moka'da işlem kodu tahmin edilmiyor

`reference()` verilen değerin `ORDER-` ile başlamasına bakarak onu Moka'nın
işlem kodu sayıyordu. Gerçek bir test bayisinde kod `Test-df91b14d-…`
biçiminde geldi; yani tahmin yanlış yönlendiriyordu.

Artık verilen değer her zaman sizin sipariş numaranız (`OtherTrxCode`)
sayılır. Moka'nın kendi kodunu kullanmak isterseniz
`metadata['virtual_pos_order_id']` ile bildirin.

Moka'nın üç kimliği (`OtherTrxCode`, `VirtualPosOrderId`/`trxCode`,
`DealerPaymentId`) ve hangisinin nerede geçerli olduğu belgelendi. Durum
sorgusundan dönen `paymentId` `DealerPaymentId`'dir ve iptal/iadede
kullanılamaz — `PaymentNotFound` verir.

### Düzeltildi — Moka BIN sorgusu hiç çalışmıyordu

Gerçek Moka test servisinde ortaya çıktı: servislerin çoğu istek gövdesini
`PaymentDealerRequest` altında bekler ama **BIN sorgusu
`BankCardInformationRequest` ister**. Yanlış sarmalayıcıyla istek
`GetBankCardInformation.InvalidRequest` ile reddediliyordu.

`post()` artık sarmalayıcı adını parametre alıyor; yalnızca BIN sorgusu
farklı olanı kullanıyor. Düzeltmeden sonra gerçek serviste doğrulandı:
`41834411 → İŞ BANKASI / VISA / credit`.

### Düzeltildi — belirsiz sonuç ile kesin ret ayrılmadı

`TransportException` her taşıma hatasını "sonuç belirsiz" gibi sunuyordu.
Kuveyt Türk'ün sorgu servisi JSON gövdeyi `415` ile reddedince paket
"sağlayıcıya ulaşılamadı — sonuç belirsiz" diyordu; oysa banka isteği
**okumadan** reddetmişti, yani hiçbir şey olmamıştı.

`outcomeUncertain` alanı eklendi ve `safeToRetry`den ayrıldı; bunlar farklı
sorulardır:

| Durum | safeToRetry | outcomeUncertain |
|---|---|---|
| Bağlantı kurulamadı | ✔ | ✘ — istek ulaşmadı |
| Zaman aşımı | ✘ | ✔ — işlenmiş olabilir |
| HTTP 4xx | ✘ | ✘ — okunmadan reddedildi |
| HTTP 5xx | ✘ | ✔ — işlenmiş olabilir |

Yanlış tarafa düşmek pahalıdır: kesin bir reddi "belirsiz" diye raporlamak
gereksiz mutabakat çalışması doğurur, tersi ise çift çekimi gözden kaçırır.

### Doğrulandı — Kuveyt Türk 3D akışı

Uç nokta düzeltmesinden sonra gerçek test terminalinde 3D doğrulaması
tamamlandı (`ResponseCode: 00`, `MDStatusCode: 1 AUTHENTICATION_SUCCESSFUL`)
ve **provizyon isteği doğru uca gidip bankadan gerçek bir karar aldı**.

Güncel kart bilgisiyle akış tamamlandı: provizyon `ResponseCode: 00`
`OTORİZASYON VERİLDİ` döndürdü ve `ProvisionNumber` alındı.

İlk denemede karar `54 Vade Sonu Geçmiş Kart` olmuştu: dolaşımdaki test kartı
bilgisi (`06/2025`) eskimişti. 3D adımı son kullanma tarihini kontrol
etmediği için hata ancak ikinci adımda görünüyor — `TEST-KARTLARI.md`
güncel değerlerle (`06/2029`, CVV `588`) düzeltildi.

### Düzeltildi — Kuveyt Türk ödeme isteği yanlış uca gidiyordu

BOA işlemleri ayrı uçlara gider (`ThreeDModelPayGate`,
`ThreeDModelProvisionGate`); driver hepsini yapılandırmadaki taban adrese
POST ediyordu. Banka isteği işliyor ama
`ResponseCode: AssemblyNotFound` — "Call couldn't find the method in the
orchestration assembly" — döndürüyordu, yani **ödeme hiç başlamıyordu**.

İşlem adı artık taban adrese ekleniyor; adres zaten işlem adıyla bitiyorsa
tekrar eklenmiyor (mevcut kurulumlar bozulmasın diye). Gerçek Kuveyt Türk
test terminalinde doğrulandı: istek artık bankanın gerçek "3D Secure
Processing" sayfasını döndürüyor.

### Düzeltildi — NestPay durum sorgusu tutarı 100 kat büyük raporluyordu

Birleşik `ORDERSTATUS` alanındaki `ORIG_TRANS_AMT` ve `CAPTURE_AMT`
**kuruş** cinsindendir; driver bunları ondalık sayıyordu. Gerçek terminalde
100,00 TL'lik bir ödeme `ORIG_TRANS_AMT:10000` olarak dönüyor ve paket bunu
**10.000,00 TL** olarak raporluyordu.

Mutabakatta sessizce yanlış davranan cinsten bir kusur: sorgu başarılı
görünüyor, durum doğru, yalnızca tutar yüz katı. Birleşik alandan gelen
tutarlar artık kuruş olarak okunuyor; düz `Extra.ORIG_TRANS_AMT` döndüren
kurulumlarda davranış değişmedi.

Eski birim testi uydurma bir değer (`ORIG_TRANS_AMT:199.90`) kullandığı için
kusuru göremiyordu; gerçek biçimle değiştirildi.

### Doğrulandı — NestPay iade ve iptal

Ziraat test terminalinde 100,00 TL'lik bir ödemenin yarısı iade, yarısı iptal
edildi; ikisi de `Response: Approved` / `ProcReturnCode: 00` döndü. Durum
sorgusu da doğru tutarla (100,00 TL) yanıt veriyor.

### Düzeltildi — NestPay 3D dönüşü Laravel'de hiç doğrulanamıyordu

Gerçek bir Ziraat 3D dönüşüyle ortaya çıktı. Banka boş alanları hash'e **boş
dizgi** olarak katar; Laravel'in varsayılan `ConvertEmptyStringsToNull`
middleware'i ise dönüş yükündeki boş alanları `null` yapar. `createHash()`
skaler olmayanı atladığı için bu alanlar hash'ten düşüyor ve imza **hiçbir
zaman** tutmuyordu.

Artık `null` boş dizgi sayılıyor. Bankadan gelen gerçek dönüş, kalıcı bir test
vektörü olarak `tests/Bank/HashTest.php` dosyasına eklendi — doğrulanıyor,
tek alanı değiştirilince reddediliyor.

Kusur `hashAlgorithm=ver3` kullanan tüm NestPay driver'larını etkiliyordu.

### Düzeltildi — NestPay durum sorgusu hiç doğru çalışmıyordu

Gerçek bir Ziraat NestPay test terminaline sorulduğunda ortaya çıktı: sorgu
yanıtında `Extra.ORDERSTATUS` tek harflik bir durum kodu değil, **birleşik bir
alandır**:

```
ORD_ID:ZR-1 CHARGE_TYPE_CD:S ORIG_TRANS_AMT:1.00 TRANS_STAT:A AUTH_DTTM:… AUTH_CODE:…




```
Driver bu dizginin tamamını durum kodu sanıyordu. Sonuç: var olan bir sipariş
`unknown` görünüyor, **var olmayan bir sipariş ise `found: true` dönüyordu** —
çünkü boş şablon (`ORD_ID: CHARGE_TYPE_CD: …`) dolu bir değer sayılıyordu.

Alan artık bilinen anahtarlara göre ayrıştırılıyor, durum `TRANS_STAT`ten
okunuyor ve boş şablon "bulunamadı" olarak yorumlanıyor. Ayrıştırma tarihlerin
içindeki boşluğu da doğru geçiyor. Tek harflik `ORDERSTATUS` döndüren
kurulumlar etkilenmez.

Bu **yedi NestPay banka driver'ının tamamını** ilgilendiriyordu (Akbank, İş
Bankası, Ziraat, Halkbank, QNB, TEB, Şekerbank ve yeni eklenen ING, Alternatif
Bank, Türkiye Finans).

### Doğrulandı — ilk banka driver'ı gerçek terminale karşı

Ziraat'in NestPay test terminalinde (`torus-stage-ziraat.asseco-see.com.tr`):

* **3D form ve `ver3` hash'i kabul edildi** — banka isteği işleyip müşteriyi
  BKM'nin gerçek 3D onay sayfasına yönlendirdi.
* **API kimlik doğrulaması ve XML istek biçimi kabul edildi** — sorgu ucu
  isteği işledi.

Paketin banka tarafında ilk kez gerçek bir terminale karşı ölçülen driver'ı.
`TEST-KARTLARI.md` dosyasına NestPay bölümü eklendi.

### Düzeltildi — Param'ın belgelenen test adresi kapanmış

README'de test ortamı olarak gösterilen `test-dmz.param.com.tr` artık `404`
dönüyor; güncel adres `testposws.param.com.tr`. Ayrıca Param hem test hem
canlı sunucularında **IP kısıtı** uyguluyor: whitelist dışındaki bir adresten
gelen istek WAF seviyesinde `403` ile reddediliyor, kimlik bilgileri doğru
olsa bile. İkisi de belgelendi.

### Düzeltildi — iyzico'da tutarsız iade hiç çalışmıyordu

`refund()` çağrısında tutar verilmediğinde paket `price` alanını hiç
göndermiyordu ve istek **her seferinde** `5004 price gönderilmesi zorunludur`
ile reddediliyordu. Paketin diğer driver'larında tutarı boş bırakmak
"tamamını iade et" demektir; iyzico'da böyle bir uç yok — hem
`/v2/payment/refund` hem `/payment/refund` tutarı zorunlu tutar.

Sözleşme bozulmasın diye tutar verilmediğinde ödemenin tahsil edilen tutarı
`/payment/detail` ucundan okunup gönderiliyor. Tutar okunamazsa istek
gönderilmiyor, açıklayıcı bir hata veriliyor.

Kısmi iade yapılmış bir ödemede bu tutar kalan bakiyeden büyük olur ve iyzico
işlemi reddeder — bilinçli tercih: fazla iade etmektense hata vermek doğrudur.
Öyle bir ödemede tutarı açıkça verin.

Bu kusur sandbox testinde ortaya çıktı; birim testleri paketin kendi
varsayımını doğruladığı için görünmüyordu.

Düzeltmeden sonra iade sandbox'ta başarıyla çalıştı ve **iade yanıt imzası**
(`paymentId:price:currency:conversationId`) da gerçek trafikle doğrulanmış
oldu. iyzico işlemi `transactionType: CANCEL` olarak kaydetti — aynı gün
yapılan tam iadeyi iptal olarak işliyor. Bu yüzden driver'ın ayrı bir
`SupportsCancellation` uygulaması yoktur ve olmasına gerek de yoktur;
belgelendi.

### Doğrulandı — iyzico uçtan uca

2026-08-09'da iyzico sandbox'ında, örnek proje üzerinden tarayıcıyla tamamlanan
bir 3D Secure satış (100,00 TL, tek çekim, resmî test kartı) `iyzico`
driver'ının **dört imza şemasını da** gerçek trafikle doğruladı:

* `Authorization` başlığı (IYZWSv2) — istek kabul edildi, `paymentId` alındı
* Initialize yanıt imzası
* 3DS dönüş imzası — `conversationData:conversationId:mdStatus:paymentId:status`
* Provizyon (`/payment/3dsecure/auth`) yanıt imzası

Bu, paketin ilk uçtan uca doğrulanan driver'ı. README'ye sürüm bazında değil
**driver bazında** bir [Doğrulama durumu](README.md#do%C4%9Frulama-durumu) tablosu
eklendi: "test var" ile "gerçekten çalışıyor" aynı şey olmadığı için her
driver'ın hangi seviyede ölçüldüğü ayrı ayrı yazıyor.

Doğrulanmayanlar aynı tabloda: iyzico'nun iade/iptal/sorgu işlemleri, diğer
sağlayıcılar ve canlı ortam.

### Eklendi — üç yeni banka preset'i

* `ing`, `alternatifbank`, `turkiyefinans`. Üçü de mevcut `AssecoGateway`
  (NestPay) driver'ını kullanır; yeni kod yazılmadı.
* Uç noktalar tahmin edilmedi, **doğrulandı**: her adayın `/fim/est3Dgate`
  ve `/fim/api` yolları NestPay'in kendi imzasını ("3D Gate requires HTTP
  POST", "VPos Api Server") döndürüyor. Aynı sunucularda NestPay'e ait
  olmayan yollar WAF hata sayfası veriyor; yani imza yola özgü.

**Eklenmeyenler:** Anadolubank, Odeabank, Fibabanka, Burgan Bank ve Emlak
Katılım için sanal POS uç noktası herkese açık bir kaynaktan doğrulanamadı.
Denenen alan adları ya DNS'te yok ya da NestPay/PayFor/BOA imzası vermiyor.
Çalışmayan bir adresle preset koymak, preset'in hiç olmamasından kötüdür —
çalışıyormuş gibi görünür. Yol haritasında açık madde olarak duruyorlar.

### Eklendi — Paratika (Payten)

* **Paratika driver'ı** (`paratika`). Dört ödeme modeli de destekleniyor:
  3D Pay (`sale3d`), klasik 3D (`auth3d` + ayrı satış), 3D Host (ortak ödeme
  sayfası) ve non-secure. Ayrıca ön provizyon/kapama, iptal, tam ve kısmi
  iade, durum sorgusu, işlem dökümü, BIN sorgusu ve taksit sorgulama.
* Akış her modelde oturum anahtarıyla (`SESSIONTOKEN`) başlar; paket bunu
  kendisi alır.
* `TEST-KARTLARI.md` dosyasına Paratika'nın resmî test kartları eklendi.

**Dikkat edilmesi gereken iki davranış:**

* **Dönüş imzasında iki alan gelir.** `SD_SHA512` dokümanda "Deprecated /
  Legacy — Do not use!" işaretlidir ama örnek yanıtlarda önce göründüğü için
  yaygın biçimde yanlışlıkla kullanılıyor. Driver güncel olan `sdSha512`
  alanını doğrular; eskisi doğru olsa bile yenisi tutmuyorsa dönüş
  reddedilir.
* **Durum sorgusu tek bir kayıt değil, işlem listesi döndürür.** İade
  edilmiş bir satışın kendi kaydı hâlâ `AP` (onaylı) görünür; yalnızca ona
  bakan bir eşleme iade edilmiş siparişi "ödendi" sanır. Driver listenin
  tamamını yorumluyor: tam iade `refunded`, kısmi iade `paid` +
  `refundedAmount`, iptal `cancelled`, kapatılmamış ön provizyon
  `pre_authorized`.

Paratika istek imzası kullanmaz; kimlik doğrulama her isteğe eklenen
`MERCHANT` / `MERCHANTUSER` / `MERCHANTPASSWORD` alanlarıdır. `secret_key`
yalnızca 3D dönüşünü doğrulamak için gerekir.

### Eklendi — Moka United

* **Moka United driver'ı** (`moka`). 3D Secure ve non-secure ödeme, ön
  provizyon ve kapama (`DoCapture`), iptal (`DoVoid`), tam ve kısmi iade
  (`DoCreateRefundRequest`), durum sorgusu, işlem dökümü, BIN sorgusu ve
  düz metin `OK` webhook onayı.
* Moka'nın dönüş hash'i (`sha256(CodeForHash + "T"|"F")`) dokümantasyondaki
  test vektörüyle kilitlendi.
* `TEST-KARTLARI.md` dosyasına Moka'nın 36 kartlık resmî test tablosu eklendi.

**Dikkat edilmesi gereken davranış:** Moka 3D dönüşünde ödemenin başarılı olup
olmadığını ayrı bir alanda bildirmez — `resultCode` başarılı işlemlerde boş
gelir ve sonuç yalnızca `hashValue` içinde taşınır. Bu yüzden `verify()`
çağrısı `order['code_for_hash']` ister; verilmezse paket sonucu tahmin etmek
yerine hata verir. Hash ne başarı ne başarısızlık varyantıyla eşleşiyorsa
`InvalidSignatureException` atılır.

### Eklenmeyecek — PayU Türkiye

PayU Türkiye için driver yazılmadı: ödeme ucu `secure.payu.com.tr` artık
DNS'te çözülmüyor (NXDOMAIN) ve PayU'nun Türkiye'deki ödeme hizmeti iyzico
markası altında sürüyor. Aynı işlevi mevcut `iyzico` driver'ı görüyor.

### Eklendi — Craftgate

* **Craftgate driver'ı** (`craftgate`). Craftgate bir bankanın sanal POS'u
  değil, birden çok POS'u tek API arkasında toplayan bir orkestrasyon
  platformudur; bu yüzden akış banka driver'larından iki noktada ayrılır:
  müşteri bir banka geçidine form POST edilmez (`3ds-init` hazır bir HTML
  sayfası döner) ve tutarlar kuruş değil ondalık sayı olarak gönderilir.
* Desteklenen işlemler: 3D Secure ve non-secure ödeme, ön provizyon ve
  provizyon kapama, tam ve kısmi iade, durum sorgusu, işlem dökümü, BIN
  sorgusu, taksit seçenekleri ve webhook imza doğrulaması.
* Her üç imza şeması da (API `x-signature`, 3D dönüşü, webhook) Craftgate'in
  resmi istemci depolarındaki **test vektörleriyle** doğrulandı ve testle
  kilitlendi. İmzalanan gövdenin gönderilen gövdeyle aynı olduğu ayrıca
  test ediliyor; ikisinin ayrışması bu API'de en sık yapılan hatadır.
* `TEST-KARTLARI.md` dosyasına Craftgate bölümü eklendi. Craftgate'in kart
  tablosunun tamamı girişli bir portalda olduğu için yalnızca herkese açık
  istemci depolarından doğrulanabilen kartlar listelendi.

### Eklendi

* `BankHttpClient::get()`. Sanal POS'ların çoğu her şeyi POST ile yapar;
  sorgu uçlarını GET olarak sunan sağlayıcılar için gerekliydi.

### Bilinen sınırlar

* Craftgate'te **gün içi iptal ayrı bir uç değildir**; Craftgate henüz
  mutabakata girmemiş işlemi iade isteğinde kendisi void olarak geçer. Bu
  yüzden driver `SupportsCancellation` arayüzünü uygulamaz.
* Craftgate'te **kısmi iade işlem bazındadır**. Bir ödeme birden çok satıcı
  işlemine bölünebildiği için hangi işlemin iade edileceğini paket kendi
  başına seçemez; `metadata['payment_transaction_id']` verilmezse tam iadeye
  sessizce düşmek yerine hata verilir.

## v1.0.1 – v1.0.6 · 2026-08-07

GitHub'ın otomatik ürettiği sürüm notları boş kaldığı için bu altı yamanın
içeriği burada toplu olarak listelenmiştir.

**Full Changelog**: https://github.com/Voxyfy/anadolupay/compare/v1.0.0...v1.0.6

### Düzeltildi — Param driver'ı hiç çalışmıyordu

Davranış testleri yazılırken iki hata ortaya çıktı; ikisi de `Xml`
yardımcısındaydı ve Param driver'ını tamamen kullanılamaz kılıyordu:

* `Xml::encode()` `@` önekli anahtarları eleman adı sanıyordu. Param istekleri
  ad alanını `'@xmlns'` ile taşıdığı için **her çağrı `DOMException` ile
  düşüyordu**. Artık `@` önekli anahtarlar öznitelik olarak yazılıyor —
  `decode()`'un öznitelikleri `@ad` ile döndürmesiyle simetrik.
* `Xml::decode()` ad alanlı çocukları atlıyordu. SOAP yanıtlarında gövdenin
  tamamı `soap:` ad alanında olduğu için Param'ın **yanıtı boş görünüyordu**.
  Artık belgede bildirilen her ad alanı dolaşılıyor; anahtar olarak ön eksiz
  yerel ad kullanılıyor. Düz XML kullanan diğer driver'ların davranışı
  değişmedi.

### Düzeltildi — güvenlik

* **iyzico `Authorization` başlığı üç noktada birden yanlıştı**: imzalanan
  dizgi (`apiKey + rnd + gövde` yerine `randomKey + uriPath + gövde`),
  kodlama (base64 yerine hex) ve başlık biçimi. Ayrıca gövde imzalandıktan
  sonra HTTP istemcisi tarafından yeniden kodlanıyordu; artık imzalanan
  dizginin aynısı gönderiliyor.
* **iyzico yanıt ve callback imzası** doğrulanmamış bir alan sırası
  kullanıyordu. Resmi dokümantasyondaki sıralar uygulandı; her uç için ayrı
  doğrulama eklendi (initialize, 3DS auth, callback, refund).
* **iyzico webhook imzası** yanlış başlıktan (`x-iyzi-signature`) okunuyordu.
  Doğrusu `X-IYZ-SIGNATURE-V3`'tür ve HPP bildirimlerinde `token` da imzaya
  girer.

### Eklendi

* Türk bankaları için native sanal POS driver'ları: Asseco/Payten NestPay
  (Akbank, İş Bankası, Ziraat, Halkbank, QNB Finansbank, TEB, Şekerbank),
  Garanti BBVA, Yapı Kredi PosNet, Albaraka PosNet V1, VakıfBank/Ziraat PayFlex
  V4, DenizBank InterPos, QNB/Ziraat Katılım PayFor, Kuveyt Türk, Vakıf Katılım.
* Ödeme kuruluşu driver'ları: Akbank POS (yeni JSON API), PayTR, Param, Tosla.
* **Yetenek sözleşmeleri.** `SupportsStatusQuery`, `SupportsCancellation`,
  `SupportsPreAuthorization`, `SupportsOrderHistory`, `SupportsBinQuery`,
  `SupportsInstallmentQuery`, `SupportsRecurringPayments`. Desteklenmeyen
  bir işlem artık `instanceof` ile önceden anlaşılıyor.
* **Sipariş durumu sorgusu** 13 driver'a yayıldı ve `StatusResponse` ile
  normalleştirildi. Bankaların birbirine benzemeyen durum kodları tek
  sözlüğe indirgeniyor; tanınmayan kod `unknown` döner.
* **Eksik iptaller**: Kuveyt Türk (ayrı BOA sorgu servisi) ve Param.
* **Kuveyt Türk iade** — ayrı SOAP servisi üzerinden.
* **Ön provizyon ve provizyon kapama** 12 driver'da; `CapturePaymentData`.
* **İşlem geçmişi** (6 driver), **BIN sorgusu** (Garanti, PayTR, iyzico),
  **taksit seçenekleri** (PayTR, Tosla, iyzico).
* **Tekrarlayan ödeme** (`RecurringPlan`): Asseco, Garanti, PayFlex,
  Akbank POS. Bankanın desteklemediği frekans hata verir.
* **iyzico iadesi** — `/v2/payment/refund` ile tam ve kısmi iade.
* **`Money` value object.** Tutarlar artık paket içinde kuruş cinsinden tam
  sayı olarak taşınır; float aritmetiği ödeme yolundan çıkarıldı.
  `CreatePaymentData` ve `RefundPaymentData` hem `Money` hem `float` kabul
  eder, mevcut çağrılar değişmeden çalışır.
* **Hata sınıflandırması.** `TransportException` (ve alt tipleri
  `GatewayUnreachableException`, `GatewayHttpException`) bankanın kesin
  reddi olan `PaymentFailedException`'dan ayrıldı. Taşıma hataları
  `safeToRetry` bayrağı taşır; zaman aşımı ve HTTP hataları isteğin bankaya
  ulaşmış olabileceği için tekrar denenemez olarak işaretlenir.
* **Yeniden deneme** (`anadolupay.retry`). Yalnızca bankaya ulaşılamayan
  durumlarda çalışır; zaman aşımı ve HTTP hataları çift çekim riski
  taşıdığı için tekrar denenmez. Varsayılan kapalı.
* **Mükerrer ödeme koruması** (`anadolupay.idempotency`). Aynı sipariş
  numarası için kısa bir pencerede ikinci ödeme başlatmayı engeller;
  atomik cache kilidi kullanır. Varsayılan kapalı.
* **Event'ler** (`anadolupay.events`): `PaymentInitiated`, `PaymentVerified`,
  `PaymentFailed`, `RefundIssued`. Kart verisi taşımazlar.
* **PSR-3 loglama** (`anadolupay.logging`). Her banka isteği ve yanıtı, kart
  numarası maskelenip CVV/şifre gizlenerek kaydedilir. Maskeleme hem alan adına
  hem de değerin biçimine (Luhn doğrulamalı) göre çalışır. Varsayılan kapalıdır.
* **Sağlayıcıya özel webhook onay yanıtı** (`ProvidesWebhookAcknowledgement`).
  PayTR bildirimin işlendiğini yalnızca düz metin `OK` yanıtıyla kabul eder;
  paketin webhook rotası artık bunu kendisi döndürüyor. Önceden her zaman
  JSON dönüyordu ve PayTR bildirimi yeniden göndermeye devam ediyordu.
* **`FakeGateway` yetenek arayüzlerini uyguluyor** ve yaptığı işlemleri
  bellekte tutuyor: ödenen sipariş `paid`, iade edilen `refunded` döner.
  Böylece durum sorgusu ve iptal akışları bankaya bağlanmadan test
  edilebiliyor. Başarı oranı `anadolupay.fake.success_rate` ile ayarlanır
  ve artık varsayılan olarak %100'dür — sahte geçidin rastgele
  başarısız olması testleri kırılgan yapıyordu.
* `CardData` DTO'su ve `PaymentResponse` üzerinde 3D Secure form alanları
  (`formAction`, `formFields`, `htmlContent`, `toHtmlForm()`).
* `config/anadolupay.php` içinde banka preset'leri; `AnadoluPay::driver()`
  artık banka anahtarlarını da çözümlüyor.
* Her altyapının imza algoritması için regresyon testleri.
* **Davranış testleri**: VakıfKatılım (sıfır kapsamdaydı), InterPos, PayFor,
  PosNet V1, Param, Tosla, PayFlex. Her driver için 3D form üretimi, dönüş
  imzası, provizyon, iade/iptal, ön provizyon ve durum sorgusu artık
  testle kilitli. Süit 164 → 230 test.

### Değişti

* `createPayment()`, `verify()` ve `refund()` artık `final`; bankaya özel
  davranış `performCreatePayment()`, `performVerify()` ve `performRefund()`
  metotlarında yaşıyor. Böylece idempotency, event yayını ve hata sarmalama
  tek yerde duruyor ve driver override'ları bunları atlayamıyor.
* Laravel 13 desteği; CI matrisi Laravel 12/13 olarak güncellendi.
* **Pest 3 desteği eklendi** (`^3.0|^4.0|^5.0`). Pest 4 en az PHP 8.3, Pest 5
  en az PHP 8.4 istiyor; bu yüzden `composer.json` PHP 8.2 bildirmesine
  rağmen test araç zinciri 8.2'de kurulamıyordu. Kütüphane kodu 8.2
  uyumludur — eksik olan yalnızca test bağımlılığıydı.
* CI matrisine PHP 8.2 eklendi. `composer.json` `^8.2` bildiriyordu ancak
  testler yalnızca 8.3 ve 8.4'te koşuyordu. Laravel 13 en az PHP 8.3
  istediği için o kombinasyon matristen hariç tutuldu.
* `larastan` geliştirme bağımlılığı eklendi (PHPStan iş akışı bağımlılık
  olmadan çalışmıyordu).

### Düzeltildi

* `BankHttpClient` 2xx dışı bir yanıtın düz metin gövdesini query string
  sanıp **başarılı sonuç gibi** döndürebiliyordu; durum kodu artık
  çözümleme denemesinden önce kontrol ediliyor.
* `IyzicoMapper` kart bilgisini yalnızca eski `customer['card']` dizisinden
  okuyordu; birinci sınıf `CardData` alanı yok sayılıyordu.
* `Currency::alphabetic()` her iki dalı aynı olan bir koşul içeriyordu ve
  sıfırla doldurulmuş kodları (Kuveyt Türk `0949`) çözemiyordu.
* `AbstractBankGateway::paymentModelOf()` hiçbir yerde yazılmayan bir
  `anadolupay_payment_model` anahtarını okuyordu; kaldırıldı.

### Belgelendi

* Bazı işlemler sağlayıcı tarafından sunulmuyor; bunlar artık arayüz
  uygulanmayarak açıkça bildiriliyor: PayTR iptal ve ön provizyon sunmaz,
  Akbank POS tekil durum sorgusu sunmaz (yerine işlem geçmişi), Kuveyt
  Türk ön provizyon sunmaz.

## v1.0.0 - 2026-08-06

### What's Changed

* Bump ramsey/composer-install from 3 to 4 by @dependabot[bot] in https://github.com/Voxyfy/anadolupay/pull/2
* Bump dependabot/fetch-metadata from 2.5.0 to 3.1.0 by @dependabot[bot] in https://github.com/Voxyfy/anadolupay/pull/4

**Full Changelog**: https://github.com/Voxyfy/anadolupay/compare/v0.1.0...v1.0.0

## v0.1.0 - 2026-01-18

### What's Changed

* Bump actions/checkout from 5 to 6 by @dependabot[bot] in https://github.com/Voxyfy/anadolupay/pull/1

### New Contributors

* @dependabot[bot] made their first contribution in https://github.com/Voxyfy/anadolupay/pull/1

**Full Changelog**: https://github.com/Voxyfy/anadolupay/commits/v0.1.0
