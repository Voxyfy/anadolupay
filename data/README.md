# Paylaşılan veri

Bu dizindeki dosyalar AnadoluPay'in **üç paketinde de birebir aynı**
taşınır: Node.js ve Go paketleri. Kod içermez, saf veridir.

Kanonik hâli [Voxyfy/anadolupay-data](https://github.com/Voxyfy/anadolupay-data)
deposundadır; buradaki dosyalar o deponun kopyasıdır.

Aynı ödeme kütüphanesi üç dilde yaşıyor. Bir sağlayıcının durum kodu
eşlemesi değiştiğinde bunun üç yerde birlikte değişmesi gerekiyor. Üç
kopyayı elle senkron tutmak sürdürülebilir değil: sapma sessizce olur
ve ödeme yolunda sessiz sapmanın bedeli, yanlış eşlenmiş bir durumun
"başarılı" sanılmasıdır.

## Dosyalar

| Dosya | İçerik |
| --- | --- |
| `currencies.json` | ISO 4217 alfabetik → sayısal para birimi kodları |
| `order-status.json` | Sağlayıcı durum kodlarının ortak duruma eşlenmesi |

## Nasıl işliyor

Paket bu dosyaları çalışma zamanında okumaz. Tablolar paketin kendi
dilinde, kendi biçiminde yazılıdır; ``tests/DataConformanceTest.php`` bu tabloları buradaki
dosyalara karşı doğrular ve saparlarsa kırılır.

Kod üretimi (codegen) kullanılmıyor. Bunun nedeni, üretilen kodun
taşıyamayacağı bir şeyin var olması: sözlüklerdeki açıklamalar.
`PARATIKA.MR`'ın neden kasıtlı olarak `pending` olduğunu, `MOKA`
kodunun tek başına neden yetmediğini anlatan yorumlar, o kodun
okunduğu yerde durmalı. Üretilen bir dosya bunları ya kaybeder ya da
JSON'a taşıyıp okunmaz hâle getirir. Uygunluk testi bu bedeli ödemeden
aynı garantiyi veriyor.

## Ne yok

**Uç adresleri.** Banka ve sağlayıcı adresleri kullanıcı
yapılandırmasından gelir, pakette gömülü değildir; paylaşılan veri
değil.

**Test kartları.** Ayrı depoda tutulur.

**İmza kuralları ve alan adları.** Bunlar veri gibi görünse de koddur:
hangi alanın imzaya hangi sırayla girdiği sağlayıcının protokolüne
gömülü bir davranıştır ve dilden bağımsız bir tabloya indirgenmesi
yanlış bir soyutlama olur.

## Bir değişikliği geçirmek

Her iki dosya aynı `version` alanını taşır ve birlikte ilerler. Her
paket beklediği sürümü testinde sabit olarak tutar.

1. Değişikliği kanonik depoda yap, `version`'ı yükselt ve oradaki
   `tools/validate.py` ile doğrula.
2. Dosyaları üç pakete kopyala.
3. Üç paketin uygunluk testi de kırılacak; yerel tabloyu ve beklenen
   sürüm sabitini güncelle.

Üçüncü adım atlanırsa o paketin testi kırık kalır — istenen davranış
budur. Sürüm alanlarının paketler arasında karşılaştırılması, hangisinin
geride kaldığını görmenin yoludur.

## Sözlük atlamak

Bir paket bir sözlüğü tamamen atlayabilir: `PARAM` yalnızca PHP
paketinde canlıdır, çünkü o sürücü Node ve Go paketlerine taşınmadı.
Atlama, uygunluk testinde gerekçesi yazılmış açık bir listeyle
bildirilir; böylece eksiklik kaza ile karışmaz.
