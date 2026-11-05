# Tasarım Dokümanı: Otel / Kiralık Ev Rezervasyon Sistemi

## 1. Mimari

Her istek aynı yoldan geçer: `public/index.php` → Router → Controller → Service → Repository. Her katmanın tek bir görevi vardır ve bir katman yalnızca altındaki katmanı çağırır.

| Katman | Görevi | Asla yapmaz | Otel rezervasyon örneği |
|---|---|---|---|
| Router | Adrese göre hangi controller metodunun çalışacağını seçer | İş kuralı içermez | `POST /rezervasyon` → `ReservationController::store` |
| Controller | `$_POST/$_GET` verisini alır, servisi çağırır, görünümü seçer | SQL yazmaz, hesap yapmaz | `ReservationController` |
| Service | İş kuralları ve çekirdek algoritma | `$_POST` okumaz, HTML üretmez | `AvailabilityService::isAvailable` (çakışma), `PricingService::quote` (gecelik döküm), `CancellationService::refund` |
| Repository | Veriyi okur ve yazar | İş kuralı içermez | `ReservationRepositoryInterface` |
| Model | Veriyi taşıyan sade sınıf | Veritabanına erişmez | `Reservation`, `Property` |
| View | HTML gösterir, çıktıyı kaçışlar | Hesap yapmaz, veri çekmez | `views/reservations/form.php` |

**Çekirdek problem:** Tarih çakışması ve çift rezervasyonun önlenmesi (`AvailabilityService`), sezonluk dinamik fiyat (`PricingService`, Strategy deseni) ve iptal/iade hesabı (`CancellationService`).

### Klasör yapısı

```
ev-otel-rezervasyon/
├── public/
│   └── index.php
├── src/
│   ├── Router.php
│   ├── Controller/      (Auth, Property, Reservation, Profile, Host, Review,
│   │                     Complaint, Admin, Api)
│   ├── Service/         (Auth, Availability, Property, Reservation, Pricing,
│   │   │                 Cancellation, Favorite, Review, Notification, Complaint,
│   │   │                 User, Report, ImageUpload)
│   │   └── Pricing/     (Weekend, Season, LongStayDiscount stratejileri)
│   ├── Repository/
│   │   ├── *Interface.php
│   │   ├── Json/        (Aşama 2)
│   │   └── Pdo/         (Aşama 3)
│   └── Model/           (User, RoomType, Property, Photo, Amenity, PriceRule,
│                         Availability, Reservation, Cancellation, Review,
│                         Favorite, Notification, Complaint)
├── views/
├── database/
├── tests/
├── docs/
└── storage/
```

**Kontrol sorusu:** Veritabanını değiştirirsek yalnızca `src/Repository/Pdo/` altındaki sınıflar değişir.
=======
01.ana-sayfa.png
02.arama-sonuclari.png
03.ilan-detay-rezervasyon.png
04.rezervasyon-özeti.png
05.giris-kayit.png

## 2. Sınıf Diyagramı

Diyagram, çekirdek akışı gösterir: rezervasyon oluşturma, çakışma kontrolü, Strategy ile gecelik fiyat hesabı, iptal ve iade. Controller yalnızca Service'i, Service yalnızca Repository arayüzünü çağırır.

```mermaid
classDiagram
    class ReservationController {
        +store() void
        +cancel() void
    }

    class ReservationService {
        +create(int userId, int propertyId, DateTimeImmutable checkIn, DateTimeImmutable checkOut, int guests) Reservation
    }
    class AvailabilityService {
        +isAvailable(int propertyId, DateTimeImmutable checkIn, DateTimeImmutable checkOut) bool
        +assertCapacity(Property property, int guests) void
    }
    class PricingService {
        +quote(Property property, DateTimeImmutable checkIn, DateTimeImmutable checkOut) PriceQuote
    }
    class CancellationService {
        +cancel(int reservationId, int userId) Cancellation
        +refundAmount(Reservation reservation) float
    }

    class PricingStrategyInterface {
        <<interface>>
        +apply(float price, DateTimeImmutable night, int totalNights) float
    }
    class WeekendStrategy
    class SeasonStrategy
    class LongStayDiscountStrategy

    class ReservationRepositoryInterface {
        <<interface>>
        +save(Reservation reservation) Reservation
        +findOverlapping(int propertyId, DateTimeImmutable checkIn, DateTimeImmutable checkOut) array
        +findById(int id) Reservation
    }
    class JsonReservationRepository
    class PdoReservationRepository
    class PropertyRepositoryInterface {
        <<interface>>
        +findById(int id) Property
    }
    class PriceRuleRepositoryInterface {
        <<interface>>
        +findByProperty(int propertyId) array
    }
    class CancellationRepositoryInterface {
        <<interface>>
        +save(Cancellation cancellation) Cancellation
    }

    class Reservation {
        +int id
        +string number
        +DateTimeImmutable checkIn
        +DateTimeImmutable checkOut
        +string status
    }
    class Property {
        +int id
        +string name
        +int capacity
        +float basePrice
    }
    class RoomType {
        +int id
        +string name
    }
    class User {
        +int id
        +string role
    }
    class PriceRule {
        +int id
        +string type
    }
    class Cancellation {
        +int id
        +float refundAmount
    }
    class PriceQuote {
        +float total
    }
    class NightlyCharge {
        +DateTimeImmutable night
        +float price
    }

    ReservationController --> ReservationService
    ReservationController --> CancellationService

    ReservationService --> AvailabilityService
    ReservationService --> PricingService
    ReservationService --> ReservationRepositoryInterface
    AvailabilityService --> ReservationRepositoryInterface
    AvailabilityService --> PropertyRepositoryInterface
    PricingService --> PriceRuleRepositoryInterface
    PricingService --> PricingStrategyInterface
    CancellationService --> ReservationRepositoryInterface
    CancellationService --> CancellationRepositoryInterface

    WeekendStrategy ..|> PricingStrategyInterface
    SeasonStrategy ..|> PricingStrategyInterface
    LongStayDiscountStrategy ..|> PricingStrategyInterface
    JsonReservationRepository ..|> ReservationRepositoryInterface
    PdoReservationRepository ..|> ReservationRepositoryInterface

    PricingService ..> PriceQuote
    PriceQuote *-- NightlyCharge
    ReservationRepositoryInterface ..> Reservation
    Reservation --> Property
    Reservation --> User
    Property --> RoomType
    PriceRule --> Property
    Cancellation --> Reservation
```

### Diyagramın okunuşu
- **Strategy:** `PricingService`, fiyat kurallarını `PricingStrategyInterface` üzerinden çalıştırır. Yeni kural eklemek yeni bir sınıf eklemektir.
- **Repository:** Servisler yalnızca `...RepositoryInterface` bilir. JSON (Aşama 2) ve PDO (Aşama 3) uygulamaları aynı arayüzü uygular.
- **Çekirdek problem:** `AvailabilityService::isAvailable`, `findOverlapping` ile çakışmaları arar. Aynı gün çıkış-giriş çakışma sayılmaz.

anlatıcı irem ablak 
## 3. ER Diyagramı
 
```mermaid
erDiagram
    USERS ||--o{ PROPERTIES : "sahip olur"
    USERS ||--o{ RESERVATIONS : "yapar"
    USERS ||--o{ REVIEWS : "yazar"
    USERS ||--o{ FAVORITES : "ekler"
    USERS ||--o{ NOTIFICATIONS : "alır"
    USERS ||--o{ COMPLAINTS : "gönderir"
    USERS ||--o{ PASSWORD_RESET_TOKENS : "talep eder"
    USERS |o--o{ AUDIT_LOGS : "işlem yapar"
 
    PROPERTIES ||--|{ BOOKABLE_UNITS : "içerir"
    PROPERTIES ||--o{ PHOTOS : "sahiptir"
    PROPERTIES ||--o{ PROPERTY_AMENITIES : "sunar"
    AMENITIES ||--o{ PROPERTY_AMENITIES : "ilan listesinde yer alır"
    PROPERTIES ||--o{ PRICING_RULES : "tanımlar"
    PROPERTIES ||--o{ FAVORITES : "favorilenir"
    PROPERTIES |o--o{ COMPLAINTS : "hakkında"
 
    BOOKABLE_UNITS ||--o{ CALENDAR_ENTRIES : "istisna tanımlanır"
    BOOKABLE_UNITS ||--o{ RESERVATIONS : "ayrılır"
    BOOKABLE_UNITS ||--o{ RESERVATION_NIGHTS : "geceleri tutulur"
 
    RESERVATIONS ||--o{ RESERVATION_NIGHTS : "engelleyen geceler"
    RESERVATIONS ||--|{ RESERVATION_CHARGES : "kalemlere ayrılır"
    RESERVATIONS ||--o{ PAYMENTS : "ödenir"
    RESERVATIONS ||--o| REVIEWS : "yorumlanır"
    RESERVATIONS |o--o{ COMPLAINTS : "hakkında"
 
    USERS {
        int id PK
        string first_name
        string last_name
        string email UK
        string password_hash
        enum role "musteri, ilan_sahibi, yonetici"
        enum status "aktif, kisitli"
        datetime created_at
    }
    PROPERTIES {
        int id PK
        int owner_id FK
        string name
        enum type "otel, kiralik_ev"
        text description
        string address
        string city
        enum booking_mode "aninda, onayli"
        enum cancellation_policy "esnek, orta, kati"
        enum status "taslak, aktif, pasif, kaldirildi"
    }
    BOOKABLE_UNITS {
        int id PK
        int property_id FK
        string name "UK (property_id, name)"
        int capacity
        decimal base_price "DECIMAL(10,2)"
        enum status "aktif, pasif"
    }
    PHOTOS {
        int id PK
        int property_id FK
        string url
        int sort_order
    }
    AMENITIES {
        int id PK
        string name UK
    }
    PROPERTY_AMENITIES {
        int property_id PK, FK
        int amenity_id PK, FK
    }
    CALENDAR_ENTRIES {
        int id PK
        int unit_id FK "UK (unit_id, date)"
        date date
        enum status "acik, kapali"
        decimal special_price "NULL olabilir"
    }
    RESERVATIONS {
        int id PK "UK (id, unit_id)"
        string reservation_no UK "RZ-YYYYMMDD-XXXXXX"
        int customer_id FK
        int unit_id FK
        date check_in "CHECK check_out > check_in"
        date check_out
        int guest_count
        enum status "Beklemede, Onaylandi, Iptal, Reddedildi, SuresiDoldu, Tamamlandi"
        datetime expires_at
        decimal total_price "DECIMAL(10,2)"
        string idempotency_key UK
        string cancel_reason
        datetime created_at
        datetime updated_at
    }
    RESERVATION_NIGHTS {
        int reservation_id PK, FK
        date night_date PK
        int unit_id FK "UK (unit_id, night_date)"
    }
    RESERVATION_CHARGES {
        int id PK
        int reservation_id FK
        enum type "konaklama, vergi, ek_ucret"
        string description
        decimal amount "DECIMAL(10,2)"
    }
    PRICING_RULES {
        int id PK
        int property_id FK
        string name
        enum type "vergi, ek_ucret"
        enum calc_method "yuzde, gecelik_sabit, rezervasyon_basina_sabit"
        decimal value "DECIMAL(10,2)"
    }
    PAYMENTS {
        int id PK
        int reservation_id FK
        string provider_ref
        decimal amount "DECIMAL(10,2)"
        string currency
        enum status "beklemede, basarili, basarisiz, iade_simule"
    }
    REVIEWS {
        int id PK
        int reservation_id FK, UK
        int customer_id FK
        tinyint rating "1-5"
        text comment
        enum moderation_status
        datetime created_at
    }
    FAVORITES {
        int user_id PK, FK
        int property_id PK, FK
        datetime added_at
    }
    NOTIFICATIONS {
        int id PK
        int user_id FK
        string type
        string message
        boolean is_read
        datetime created_at
    }
    COMPLAINTS {
        int id PK
        int sender_id FK
        int property_id FK "NULL olabilir"
        int reservation_id FK "NULL olabilir"
        text description
        enum status
        text admin_note
    }
    AUDIT_LOGS {
        int id PK
        int actor_id FK "sistem islemlerinde NULL"
        string entity_type
        int entity_id
        string action
        string old_value
        string new_value
        string reason
        datetime created_at
    }
    PASSWORD_RESET_TOKENS {
        int id PK
        int user_id FK
        string token_hash UK
        datetime expires_at
        datetime used_at "NULL olabilir"
    }
```
 
### Normalizasyon Gerekçesi
 
Her varlık kendi tablosunda tutulmuş, tekrarlayan veriler (fotoğraf, olanak, gece, fiyat kalemi, favori) ara veya alt tablolara ayrılarak **1NF** sağlanmıştır. Bileşik anahtarlı tablolarda (`PROPERTY_AMENITIES`, `FAVORITES`, `RESERVATION_NIGHTS`) yalnızca anahtarın tamamına bağlı sütunlar bırakıldığı için **2NF** sağlanır. Müşteri, ilan veya şehir gibi bilgiler yalnızca bir tabloda tutulur ve diğer tablolar bunlara FK ile bağlanır; böylece anahtar olmayan sütunlar arasında geçişli bağımlılık kalmaz ve **3NF** sağlanır.
 
**Bilinçli iki istisna:**
 
1. `RESERVATION_NIGHTS.unit_id`: Kesin 3NF'ye göre fazlalıktır; ancak veritabanının `UNIQUE (unit_id, night_date)` kısıtını uygulayabilmesi için gereklidir. Bu kısıt çift rezervasyona karşı son savunmadır. Tutarsızlığı `(reservation_id, unit_id)` bileşik FK'si önler.
2. `RESERVATIONS.total_price`: `RESERVATION_CHARGES` toplamından türetilebilir; ancak fiyat anlık görüntüsü olarak bilinçli saklanır (BR-16) ve testle kalemlerin toplamına eşitliği doğrulanır.

anlatıcı irem ablak
## 4. Sıralama Diyagramı: Rezervasyon Oluşturma (Çekirdek: Çift Rezervasyon Önleme) 
 
```mermaid
sequenceDiagram
    autonumber
    participant T as Tarayıcı
    participant C as ReservationController
    participant S as ReservationService
    participant P as PricingService
    participant B as BookingConflictService
    participant R as ReservationRepository
 
    T->>C: POST /rezervasyon (birimId, giriş, çıkış, kişi, idempotencyKey)
    C->>S: create(userId, data)
    S->>R: findByIdempotencyKey(key)
 
    alt anahtar daha önce kullanılmış (çift gönderim, CS-06)
        R-->>S: mevcut Reservation
        S-->>C: mevcut Reservation
        C-->>T: 302 mevcut rezervasyon sayfası (yeni kayıt yok)
    else yeni istek
        S->>S: tarih, kişi sayısı, ilan ve birim durumu doğrula (BR-01, BR-02, BR-03, BR-14)
        alt doğrulama geçersiz
            S-->>C: ValidationException
            C-->>T: formu hata mesajıyla tekrar göster (kayıt yok)
        else doğrulama geçerli
            S->>P: calculate(birimId, giriş, çıkış)
            P-->>S: gece, vergi ve ek ücret kalemleri
            S->>R: beginTransaction()
            S->>B: assertAvailable(birimId, giriş, çıkış, kişi)
            B->>R: lockUnit(birimId)
            alt kilit alınamadı (2 yeniden denemeden sonra)
                R-->>B: LockTimeoutException
                B-->>S: LockTimeoutException
                S->>R: rollback()
                S-->>C: BusyException
                C-->>T: "Yoğunluk var, lütfen tekrar deneyin" (kayıt yok)
            else kilit alındı
                B->>R: expireStaleHolds(birimId)
                Note over B,R: Süresi dolmuş Beklemede kayıtlar işlem içinde<br/>Süresi Doldu yapılır ve geceleri serbest kalır (CS-05)
                B->>R: findOverlapping(birimId, giriş, çıkış)
                B->>R: findClosedNights(birimId, giriş, çıkış)
                R-->>B: çakışan rezervasyonlar ve kapalı geceler
                alt çakışma var veya gece kapalı (CS-01, CS-04, CS-08)
                    B->>B: en çok 3 yakın müsait aralık hesapla (FR-36)
                    B-->>S: ConflictException(alternatifler)
                    S->>R: rollback()
                    S-->>C: ConflictException(alternatifler)
                    C-->>T: 409 "Tarihler müsait değil" + alternatif aralıklar (kayıt yok)
                else birim müsait
                    B-->>S: uygun
                    S->>R: createWithNights(rezervasyon, kalemler, geceler)
                    alt UNIQUE(birim, gece) ihlali veya yazma hatası (CS-01 yarışı, CS-12)
                        R-->>S: PersistenceException
                        S->>R: rollback()
                        S-->>C: ConflictException veya SystemException
                        C-->>T: hata mesajı (yarım rezervasyon veya yarım gece kaydı kalmaz)
                    else kayıt başarılı
                        R-->>S: Reservation (Beklemede, expires_at)
                        S->>R: commit()
                        S-->>C: Reservation
                        C-->>T: 302 /rezervasyon/RZ-... "Beklemede" (anında 10 dk, onaylı 24 saat)
                    end
                end
            end
        end
    end
```
 anlatıcı irem ablak
### Diyagramdaki durumlar
 
| Dal | Senaryo | Sonuç |
| --- | --- | --- |
| Başarılı | Birim müsait, kayıt ve geceler tek işlemde yazıldı | Rezervasyon Beklemede olur, geceler tutulur |
| Hata 1 | Çift gönderim (CS-06) | Yeni kayıt oluşmaz, mevcut rezervasyon gösterilir |
| Hata 2 | Geçersiz tarih, kapasite aşımı, pasif ilan | ValidationException, kayıt yok |
| Hata 3 | Kilit alınamadı | 2 deneme sonrası "yoğunluk" mesajı, kayıt yok |
| Hata 4 | Çakışma veya kapalı gece (CS-01, 04, 08) | ConflictException, en çok 3 alternatif önerilir |
| Hata 5 | UNIQUE(birim, gece) ihlali veya yazma hatası (CS-12) | Rollback, yarım kayıt kalmaz |


5. Tasarım Desenleri
   
5.1 Repository Deseni (Zorunlu)
1. Problem: Projede kullanıcı, ilan, müsaitlik, rezervasyon, ödeme, yorum, favori, bildirim ve şikâyet olmak üzere çok sayıda kalıcı veri varlığı vardır. Veriye erişim kodu (SQL, dosya okuma) doğrudan servis sınıflarının içine yazılırsa, Aşama 2'de JSON dosyalarıyla Aşama 3'te MySQL ile çalışmak istendiğinde tüm iş mantığı kodunun değişmesi gerekir.

2. Desen: Repository deseni; UserRepositoryInterface, PropertyRepositoryInterface, ReservationRepositoryInterface, AvailabilityRepositoryInterface, ReviewRepositoryInterface gibi arayüzler ve bunların MySql*Repository (Aşama 3) ile Json*Repository (Aşama 2) somut uygulamalarıyla kurulur. Servis sınıfları (ReservationService, AvailabilityService, PricingService) yalnızca arayüzü tanır; gerçek veritabanı sınıfını bilmez.

3. Alternatif: Servis sınıflarının içinde doğrudan PDO veya file_get_contents() çağrıları yapmak; yani katmansız, veri erişimi ile iş kuralını aynı sınıfta toplamak.

4. Neden: Depolama katmanı değiştiğinde (JSON → MySQL) yalnızca yeni bir MySql*Repository sınıfı yazılır; ReservationService ve AvailabilityService içindeki çekirdek iş kuralı (tarih çakışma kontrolü) hiç değişmez. Bu, SRS'teki NFR-08 (Bakım yapılabilirlik) ve FR-18 (çift rezervasyon engeli) gereksinimlerinin sürdürülebilir biçimde karşılanmasını sağlar.

5.2 Strategy Deseni (Fiyatlandırma)
1. Problem: SRS'teki FR-22, ilan sahibinin tarih aralığına göre fiyat tanımlamasını; FR-14 ise gecelik fiyat ile vergi/ek ücretlerin ayrı ayrı hesaplanmasını zorunlu kılar. Aynı ilan için hafta sonu farklı fiyat, yüksek sezon farklı fiyat, uzun konaklamada indirim uygulanabilir. Bu kuralların tümünü PricingService içinde if ($tarih hafta sonu mu) ... elseif ($sezon mu) ... elseif ($gece > 7) ... zinciriyle yazmak hem okunması zor hem de yeni bir kural (örneğin "son dakika indirimi") eklendiğinde mevcut çalışan kodu bozar.

2. Desen: Strategy deseni; PricingStrategyInterface arayüzü ile WeekendPricingStrategy, SeasonalPricingStrategy ve LongStayDiscountStrategy somut strateji sınıfları üzerinden uygulanır. PricingService::calculate(int roomTypeId, string checkIn, string checkOut) metodu, PriceRuleRepositoryInterface'ten gelen kurallara bakarak çalışma anında uygun stratejiyi (setStrategy()) seçer ve fiyat dökümünü dizi olarak döner. Sınıf diyagramında bu ilişki PricingService --> PricingStrategyInterface ve WeekendPricingStrategy ..|> PricingStrategyInterface olarak gösterilmiştir.

3. Alternatif: Tek bir PricingService sınıfı içinde iç içe if/elseif bloklarıyla tüm fiyat kurallarını yönetmek; yani desensiz, tek sorumluluk ilkesini bozan çözüm.

4. Neden: Yeni bir fiyat kuralı (örneğin "bayram dönemi zammı" veya "erken rezervasyon indirimi") eklemek için mevcut PricingService veya diğer strateji sınıflarına dokunmadan yalnızca yeni bir sınıf (HolidayPricingStrategy) yazıp arayüzü uygulamak yeterlidir. Bu, Açık/Kapalı Prensibi'ne (OCP) uygundur; ayrıca her strateji bağımsız olarak birim testine tabi tutulabildiği için SRS'teki AC-08 (toplam fiyatın onay öncesi açık gösterimi) ve kılavuzdaki "en az 10 PHPUnit testi" şartı daha güvenilir biçimde karşılanır. Sunumda "Hangi sınıflar bu deseni oluşturuyor?" sorusuna PricingStrategyInterface ile üç somut strateji sınıfı diyagr




6 risk tablosu
| Kimlik | Risk | Kategori | Olasılık | Etki | Olasılık Puanı | Etki Puanı | Risk Skoru | Seviye | Önlem | İlgili Test / Gereksinim | Kaynak |
| :--- | :--- | :--- | :--- | :--- | :---: | :---: | :---: | :---: | :--- | :--- | :--- |
| **R1** | Eş zamanlı isteklerde "önce kontrol et, sonra kaydet" yarışı yüzünden çift rezervasyon oluşması | Çekirdek | Orta | Yüksek | 2 | 3 | 6 | Yüksek | Transaction + birim satırı kilidi (SELECT ... FOR UPDATE) + UNIQUE(birim, gece) kısıtı. T-05 (50 paralel istek) ve T-06 testleri. | T-05, T-06, FR-18, FR-33 | SRS 4.9 |
| **R2** | Sınır günü hatası: çıkış gününün gece sayıp sayılmaması | Çekirdek | Yüksek | Yüksek | 3 | 3 | 9 | Yüksek | Yarı açık aralık [giriş, çıkış] tek tanımı. Tüm kodda tek karşılaştırma fonksiyonu. Veri güdümlü T-01 testi (8 durum). | T-01, BR-11, BR-13 | SRS 4.9 |
| **R3** | Arama ve rezervasyonun farklı kural uygulaması (aramada görünen ama rezervasyonda reddedilen birim) | Çekirdek | Orta | Orta | 2 | 2 | 4 | Orta | Kuralın tek kopyası. AvailabilityService kuralı kendisi yazmaz, bu servisi kullanır. T-08 tutarlılık testi. | T-08, FR-06, FR-10, FR-11 | SRS 4.9 |
| **R4** | Süresi dolmuş "hayalet" bekletmelerin geceleri gereksiz yere bloke etmesi | Çekirdek | Orta | Orta | 2 | 2 | 4 | Orta | Lazy expiry (çalışma kontrolü sırasında temizleme) + dakikada bir çalışan zamanlanmış görev. T-03 testi. | T-03, FR-34, CS-05 | SRS 4.9 |
| **R5** | Aşama 2'deki JSON depoda eşzamanlı yazmayla dosya bozulması veya çift kayıt | Çekirdek | Orta | Yüksek | 2 | 3 | 6 | Yüksek | flock(LOCK_EX) altında tek kritik bölüm. Önce geçici dosyaya yazıp yeniden adlandırma. T-10 testi. | T-10, SRS 4.5 | SRS 4.9 |
| **R6** | Yarım kayıt: rezervasyon yazıldıktan sonra gece kaydı eklenirken hata veya sunucu kesintisi | Çekirdek | Düşük | Yüksek | 1 | 3 | 3 | Orta | Rezervasyon, gece and kalemler tek atomik işlemde yazılır (createWithNights). Hata durumunda rollback(). T-07 hata enjeksiyon testi. | T-07, FR-33, CS-12 | SRS 4.6 (CS-12) |
| **R7** | Yapay zekâ üretimi kodun çakışma kontrolünü Controller'a veya yanlış katmana koyması | Çekirdek | Yüksek | Orta | 3 | 2 | 6 | Yüksek | Her istemde mimari tablo ve SRS Bölüm 4.7 bağlam olarak verilir. PR incelemesinde katman kuralı kontrol edilir (Controller $\rightarrow$ Repository oku yok). | SRS 4.7, katmanlı mimari | SRS 4.9 |
| **R8** | Kapsamın büyümesi (42 FR, çok sayıda varlık, kısıtlı süre) | Genel | Yüksek | Orta | 3 | 2 | 6 | Yüksek | Backlog'ta önce çekirdek etiketli işler. SRS'deki kapsam dışı listesine (overbooking, kanal yöneticisi, gerçek ödeme) sadık kalmak. | SRS 4.1, SRS 11.2 | Kılavuz (projeye uyarlandı) |
| **R9** | Bir üyenin katkı vermemesi (4 kişilik ekip, 4 rol) | Genel | Orta | Yüksek | 2 | 3 | 6 | Yüksek | Her issue'ya sorumlu atamak, haftalık toplantı, sorunu erken bildirmek, panoda Group by Assignees ile iş dağılımını izlemek. | GitHub Projects backlog | Kılavuz |
| **R10** | Aynı dosyada çakışan değişiklikler (Git) | Genel | Yüksek | Düşük | 3 | 1 | 3 | Orta | Dal + pull request, main için onay zorunluluğu, işleri dosya bazında bölmek. | Git dal ve PR akışı | Kılavuz |
| **R11** | Ortam sorunları (PHP sürümü, Composer, MySQL) | Genel | Düşük | Orta | 1 | 2 | 2 | Düşük | İlk hafta kurulum ve doğrulama (composer install). Sorun olursa Laragon. PHP >= 8.2 sürümünün herkeste aynı olduğunu kontrol etmek. | Adım 0: Composer kurulumu | Kılavuz |
| **R12** | Süre, limit ve iade oranı gibi sayısal kararların onaysız olması | Genel | Orta | Orta | 2 | 2 | 4 | Orta | Ekip içinde doğrulama toplantısı, ders yürütücüsüyle onay, değerlerin tek yerde (yapılandırma) tutulması. | SRS 11.4 | SRS 11.4 (varsayımlar) |


7 Use Case diyagramı
```mermaid
graph LR
    sub5["Otel / Kiralık Ev Rezervasyon Sistemi"]
        UC01["UC-01: Konaklama arama"]
        UC02["UC-02: Rezervasyon oluşturma"]
        UC03["UC-03: İlan, fiyat, takvim"]
        UC04["UC-04: İptal ve iade"]
        UC05["UC-05: Talep onay / ret"]
        UC06["UC-06: Üyelik ve giriş"]
        UC07["UC-07: Rezervasyon ve profil"]
        UC08["UC-08: Yorum ve şikâyet"]
        UC09["UC-09: Yönetici denetimi"]
        UC10["UC-10: Doluluk / gelir raporu"]
        UC11["UC-11: Otomatik durum geçişi"]
    end

    Ziyaretci([Ziyaretçi])
    Musteri([Müşteri])
    IlanSahibi([İlan sahibi])
    Yonetici([Yönetici])
    Sistem([Sistem / zamanlı görev])

    Musteri --> Ziyaretci

    Ziyaretci --- UC01
    Ziyaretci --- UC06

    Musteri --- UC01
    Musteri --- UC02
    Musteri --- UC04
    Musteri --- UC06
    Musteri --- UC07
    Musteri --- UC08

    IlanSahibi --- UC03
    IlanSahibi --- UC05
    IlanSahibi --- UC10

    Yonetici --- UC09
    Sistem --- UC11
 
8 arayüz Sayfa haritası
```mermaid
 flowchart LR
subgraph HER["Herkes (ziyaretçi dahil)"]
  A["Ana sayfa ve arama"] --> B["Arama sonuçları"]
  B --> C["İlan detayı ve aylık takvim"]
  G["Giriş"]
  K["Kayıt ol"]
  PW["Parola sıfırlama"]
end
subgraph MUS["Kayıtlı müşteri"]
  D["Rezervasyon onayı ve gecelik fiyat dökümü"] --> E["Rezervasyonlarım"]
  E --> F["İptal önizleme ve iade"]
  E --> Y["Yorum yaz"]
  H["Favoriler"]
  I["Profil"]
  BL["Bildirimler"]
  SK["Şikâyet oluştur"]
end
subgraph ILN["İlan sahibi (yalnızca ilan sahibi)"]
  J["İlan sahibi paneli"] --> L1["İlanlarım ve odalar"]
  L1 --> RS["Resim yükleme"]
  J --> TK["Takvim ve fiyat kuralları"]
  J --> GR["Gelen rezervasyonlar: onayla / reddet"]
  J --> RP["Doluluk ve gelir raporu"]
end
subgraph YON["Yönetici (yalnızca yönetici)"]
  O["Yönetici paneli"] --> KU["Kullanıcılar"]
  O --> IL["İlanlar: inceleme ve kaldırma"]
  O --> SC["Şikâyetler"]
  O --> YD["Yorum denetimi"]
  O --> YR["Raporlar"]
end
G --> A
K --> G
G -.-> PW
C -->|"giriş gerekir"| D
A --> H
A --> I
A --> BL
C -.-> SK
G -->|"ilan sahibi rolü"| J
G -->|"yönetici rolü"| O
classDef her fill:#eef2f7,stroke:#5b6b82,color:#1b2430
classDef mus fill:#e3f1e8,stroke:#3d8b5a,color:#12301f
classDef iln fill:#fdf0d9,stroke:#b9852a,color:#3a2a08
classDef yon fill:#f9e1e1,stroke:#b04a4a,color:#3a1010
class A,B,C,G,K,PW her
class D,E,F,Y,H,I,BL,SK mus
class J,L1,RS,TK,GR,RP iln
class O,KU,IL,SC,YD,YR yon
style HER fill:#f7f9fc,stroke:#c3ccd8,color:#1b2430
style MUS fill:#f4faf6,stroke:#b9dcc6,color:#12301f
style ILN fill:#fffaf0,stroke:#ecd5a6,color:#3a2a08
style YON fill:#fdf6f6,stroke:#e6c0c0,color:#3a1010
```

Renkler rolü gösterir: gri = herkes, yeşil = kayıtlı müşteri, turuncu = yalnızca ilan sahibi, kırmızı = yalnızca yönetici. Yetki sunucu tarafında da denetlenir (NFR-03).

Yönetici (yalnızca yönetici)
İlan sahibi (yalnızca ilan sahibi)
Kayıtlı müşteri
Herkes (ziyaretçi dahil)
giriş gerekir
ilan sahibi rolü
yönetici rolü
Ana sayfa ve arama
Arama sonuçları
İlan detayı ve aylık takvim
Giriş
Kayıt ol
Parola sıfırlama
Rezervasyon onayı vegecelik fiyat dökümü
Rezervasyonlarım
İptal önizleme ve iade
Yorum yaz
Favoriler
Profil
Bildirimler
Şikâyet oluştur
İlan sahibi paneli
İlanlarım ve odalar
Resim yükleme
Takvim ve fiyat kuralları
Gelen rezervasyonlar:onayla / reddet
Doluluk ve gelir raporu
Yönetici paneli
Kullanıcılar
İlanlar: inceleme ve kaldırma
Şikâyetler
Yorum denetimi
Raporlar
7.2 Ekran taslakları

## 9  izlenebilirlik matrisi

Eşleme, SRS’teki FR kartlarının “Olay/Kullanım Senaryosu” ve “Destekleyici Materyal” alanlarından çıkarıldı (Ek B ile uyumlu). Turuncu işaret ÇEKİRDEK gereksinimdir.

| UC | Senaryo | FR sayısı | Çekirdek FR | AC sayısı | Test sayısı |
| --- | --- | --- | --- | --- | --- |
| **UC-01** | Konaklama Arama | 5 | 3 | 4 | 3 |
| **UC-02** | Rezervasyon Oluşturma | 15 | 11 | 16 | 10 |
| **UC-03** | İlan/Fiyat/Takvim Yönetimi | 7 | 2 | 6 | 2 |
| **UC-04** | İptal ve İade | 5 | 4 | 5 | 2 |
| **UC-05** | Talep Onay/Ret | 4 | 2 | 2 | 1 |

| FR | Öncelik | UC-01 | UC-02 | UC-03 | UC-04 | UC-05 | BR | AC | Test | Tasarım bileşeni |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| **FR-01** | Zorunlu |  |  |  |  |  |  | 01 |  | AuthController → AuthService |
| **FR-02** | Zorunlu |  |  |  |  |  |  |  |  | AuthController → AuthService |
| **FR-03** | Zorunlu |  |  |  |  |  |  |  |  | AuthController → AuthService |
| **FR-04** | Gerekli |  |  |  |  |  |  |  |  | AuthController → AuthService |
| **FR-05** | Zorunlu |  |  |  |  |  |  | 06, 07 |  | AuthController → AuthService |
| **FR-06** | Çekirdek | ● |  |  |  |  | BR-01, BR-02, BR-14 | 02, 20 | T-08 | PropertyController → AvailabilityService |
| **FR-07** | Gerekli | ● |  |  |  |  |  | 29 |  | PropertyController → PropertyService |
| **FR-08** | Gerekli | ● |  |  |  |  |  |  |  | PropertyController → PropertyService |
| **FR-09** | Zorunlu |  |  |  |  |  | BR-06 |  |  | PropertyController → PropertyService |
| **FR-10** | Çekirdek | ● | ● |  |  |  |  | 32 | T-08 | PropertyController → AvailabilityService |
| **FR-44** | Çekirdek | ● | ● |  |  |  | BR-23, BR-26 |  | T-12, T-13 | PropertyController → PricingService |
| **FR-11** | Çekirdek |  | ● |  |  |  |  | 12 | T-05 | ReservationController → BookingConflictService |
| **FR-12** | Çekirdek |  | ● |  |  |  | BR-05, BR-15 |  |  | ReservationController → ReservationService |
| **FR-13** | Zorunlu |  | ● |  |  |  |  | 04 |  | ReservationController → ReservationService |
| **FR-18** | Çekirdek |  | ● |  |  |  | BR-11, BR-12, BR-13 | 05, 13, 22 | T-05, T-06, T-11 | ReservationController → BookingConflictService |
| **FR-33** | Çekirdek |  | ● |  |  |  |  | 12 | T-07 | ReservationService → BookingConflictService |
| **FR-34** | Çekirdek |  | ● |  |  |  | BR-15 | 15 | T-03 | ReservationService → BookingConflictService |
| **FR-35** | Çekirdek |  | ● |  |  |  |  | 17 | T-04 | ReservationController → ReservationService |
| **FR-36** | Çekirdek |  | ● |  |  |  |  | 12 |  | ReservationController → BookingConflictService |
| **FR-37** | Zorunlu |  | ● |  |  |  | BR-05 | 19 |  | ReservationController → PaymentService |
| **FR-14** | Çekirdek |  | ● |  |  |  | BR-07, BR-16, BR-23, BR-26 | 08, 21, 23, 24, 25 | T-12, T-13 | ReservationController → PricingService |
| **FR-43** | Çekirdek |  |  | ● |  |  | BR-23, BR-26 | 25 | T-12 | HostController → PricingService |
| **FR-15** | Çekirdek |  | ● |  | ● | ● | BR-12 |  | T-02 | ReservationController → ReservationService |
| **FR-16** | Gerekli |  |  |  |  |  |  | 06 |  | ReservationController → ReservationService |
| **FR-17** | Çekirdek |  |  |  | ● |  | BR-17, BR-18 | 16, 26, 27 | T-02, T-14 | ReservationController → ReservationService |
| **FR-40** | Zorunlu |  |  |  |  |  | BR-09, BR-30 |  |  | Zamanlanmış görev → ReservationService |
| **FR-45** | Çekirdek |  |  |  | ● |  | BR-17, BR-27, BR-28, BR-29 | 26, 27, 28 | T-14 | ReservationController → RefundService |
| **FR-20** | Zorunlu |  |  | ● |  |  |  | 07 |  | HostController → PropertyService |
| **FR-21** | Zorunlu |  |  | ● |  |  | BR-08 | 07 |  | HostController → PropertyService |
| **FR-22** | Çekirdek |  |  | ● |  |  | BR-22 | 18, 25 | T-09 | HostController → AvailabilityService |
| **FR-23** | Çekirdek |  |  |  |  | ● | BR-05, BR-08, BR-15 | 07 |  | HostController → ReservationService |
| **FR-38** | Zorunlu |  |  | ● |  |  | BR-05, BR-17 | 28 |  | HostController → PropertyService |
| **FR-42** | Zorunlu |  |  | ● |  |  | BR-04 | 29 |  | HostController → PropertyService |
| **FR-46** | Gerekli |  |  | ● |  |  |  | 31 |  | HostController → PhotoService |
| **FR-19** | İsteğe bağlı |  |  |  |  |  |  |  |  | ProfileController → FavoriteService |
| **FR-24** | Gerekli |  |  |  |  |  | BR-09, BR-21 |  |  | ReviewController → ReviewService |
| **FR-25** | Gerekli |  |  |  |  |  | BR-10 |  |  | AdminController → ReviewService |
| **FR-26** | Gerekli |  | ● |  | ● | ● |  |  |  | ReservationController → NotificationService |
| **FR-27** | Gerekli |  |  |  |  |  |  |  |  | ComplaintController → ComplaintService |
| **FR-31** | Gerekli |  |  |  |  |  |  |  |  | ProfileController → UserService |
| **FR-32** | Gerekli |  | ● |  |  | ● |  | 06, 07 |  | ReservationController → ReservationService |
| **FR-28** | Gerekli |  |  |  |  |  | BR-10 | 09 |  | AdminController → PropertyService |
| **FR-29** | Gerekli |  |  |  |  |  | BR-10 |  |  | AdminController → UserService |
| **FR-30** | Gerekli |  |  |  |  |  | BR-30 | 30 | T-15 | AdminController → ReportService |
| **FR-39** | Çekirdek |  |  |  | ● |  | BR-10, BR-28 | 09, 27 |  | AdminController → ReservationService |
| **FR-41** | Gerekli |  |  |  |  |  | BR-10 |  |  | AdminController, ReservationService → AuditService |

17 FR hiçbir UC’ye bağlı değil (FR-01–05, 09, 16, 19, 24, 25, 27–31, 40, 41): hesap, profil, yorum, yönetim ve rapor işlevleri. Bunlar için UC yazılmamış, yalnızca US/AC düzeyinde izleniyorlar. İzlenebilirlik açığıdır.

##  10 Akış diyagramları

Genel senaryo akışı

```mermaid
flowchart LR
V([Ziyaretçi]) --> UC1[UC-01 Arama]
UC1 -->|müsait ilan seç| UC2[UC-02 Rezervasyon<br/>ÇEKİRDEK]
UC2 -->|onaylı mod: Beklemede| UC5[UC-05 Onay/Ret]
UC2 -->|anında mod: ödeme| ON([Onaylandı])
UC5 -->|Onay| ON
UC5 -->|Ret| RD([Reddedildi])
ON -->|giriş gününden önce| UC4[UC-04 İptal ve iade<br/>ÇEKİRDEK]
UC4 --> IC([İptal Edildi, geceler serbest])
ON -->|çıkış geçti, FR-40| TM([Tamamlandı])
HS([İlan sahibi]) --> UC3[UC-03 İlan, fiyat, takvim]
UC3 -.->|fiyat kuralı, takvim, birim| UC1
UC3 -.->|kapatma çakışma denetimi FR-22| UC2
class UC2,UC4 core
classDef core fill:#fed7aa,stroke:#c2410c,color:#1c1b19
```

UC-02 Rezervasyon oluşturma (temel akış ve A1–A7 alternatifleri)

```mermaid
flowchart TD
A([Müşteri: ilan ve birim seç, tarih/kişi gir]) --> B[Ön kontrol: müsaitlik + kapasite<br/>FR-06, FR-10]
B --> C[Fiyat dökümü göster<br/>FR-14, FR-44]
C --> D{Kullanıcı onaylar?}
D -- Hayır --> Z([Bitiş: geceler tutulmaz])
D -- Evet --> E{Oturum var?}
E -- Hayır --> E1[Girişe yönlendir, seçimleri koru A6] --> E
E -- Evet --> F{Aynı idempotency anahtarı?<br/>FR-35}
F -- Evet --> F1[Mevcut rezervasyonu göster A4] --> Y
F -- Hayır --> G[ATOMİK İŞLEM FR-33<br/>birimi kilitle, süresi dolan bekletmeleri temizle]
G --> H{Çakışma var mı?<br/>FR-11, FR-18}
H -- Evet --> H1[Geri al, 3 alternatif aralık öner<br/>A1, FR-36] --> Z
H -- Hayır --> I{Fiyat değişti mi?}
I -- Evet --> I1[Yeni döküm, yeniden onay A5] --> C
I -- Hayır --> J[Beklemede kaydet + geceleri ayır<br/>numara üret, FR-12, FR-13]
J --> K{Rezervasyon modu?<br/>FR-38}
K -- Anında --> L[Simüle ödeme FR-37]
L -- Başarılı --> M[Onaylandı]
L -- Başarısız --> N[Beklemede kalır, yeniden dene A3]
N -- Süre dolar FR-34 --> X[Süresi Doldu, geceler serbest]
K -- Onaylı --> O[İlan sahibine bildirim, UC-05]
O -- Onay --> M
O -- Ret --> R[Reddedildi, geceler serbest]
O -- 24 saat dolar --> X
M --> Y([Durum, numara ve bildirim FR-26])
class G,H core
classDef core fill:#fed7aa,stroke:#c2410c,color:#1c1b19
```

## 11 Maliyet-değer grafiği
```mermaid
%%{init: {"quadrantChart": {"chartWidth": 900, "chartHeight": 700, "pointLabelFontSize": 10, "pointTextPadding": 4}}}%%
quadrantChart
    title Gereksinim maliyet-değer grafiği (SRS v2.3)
    x-axis Düşük maliyet --> Yüksek maliyet
    y-axis Düşük değer --> Yüksek değer
    quadrant-1 Stratejik (pahalı, değerli)
    quadrant-2 Önce yap (ucuz, değerli)
    quadrant-3 Fırsat (ucuz, düşük değer)
    quadrant-4 Ertelenebilir (pahalı, düşük değer)
    FR-19: [0.065, 0.100] radius: 6, color: #2563eb
    FR-03: [0.065, 0.420] radius: 6, color: #2563eb
    FR-13: [0.100, 0.420] radius: 6, color: #2563eb
    FR-32: [0.135, 0.420] radius: 6, color: #2563eb
    FR-04: [0.225, 0.260] radius: 6, color: #2563eb
    FR-08: [0.260, 0.260] radius: 6, color: #2563eb
    FR-25: [0.295, 0.260] radius: 6, color: #2563eb
    FR-26: [0.225, 0.225] radius: 6, color: #2563eb
    FR-27: [0.260, 0.225] radius: 6, color: #2563eb
    FR-31: [0.295, 0.225] radius: 6, color: #2563eb
    FR-29: [0.225, 0.190] radius: 6, color: #2563eb
    FR-37: [0.225, 0.420] radius: 6, color: #2563eb
    FR-40: [0.260, 0.420] radius: 6, color: #2563eb
    FR-28: [0.295, 0.420] radius: 6, color: #2563eb
    FR-01: [0.225, 0.580] radius: 6, color: #2563eb
    FR-02: [0.260, 0.580] radius: 6, color: #2563eb
    FR-09: [0.295, 0.580] radius: 6, color: #2563eb
    FR-35: [0.225, 0.545] radius: 6, color: #c2410c
    FR-16: [0.260, 0.545] radius: 6, color: #2563eb
    FR-21: [0.295, 0.545] radius: 6, color: #2563eb
    FR-38: [0.225, 0.510] radius: 6, color: #2563eb
    FR-20: [0.225, 0.740] radius: 6, color: #2563eb
    FR-42: [0.260, 0.740] radius: 6, color: #2563eb
    FR-24: [0.385, 0.260] radius: 6, color: #2563eb
    FR-41: [0.420, 0.260] radius: 6, color: #2563eb
    FR-07: [0.385, 0.420] radius: 6, color: #2563eb
    FR-36: [0.420, 0.420] radius: 6, color: #c2410c
    FR-46: [0.455, 0.420] radius: 6, color: #2563eb
    FR-05: [0.385, 0.580] radius: 6, color: #2563eb
    FR-10: [0.420, 0.580] radius: 6, color: #c2410c
    FR-34: [0.455, 0.580] radius: 6, color: #c2410c
    FR-43: [0.385, 0.545] radius: 6, color: #c2410c
    FR-44: [0.385, 0.740] radius: 6, color: #c2410c
    FR-15: [0.420, 0.740] radius: 6, color: #c2410c
    FR-23: [0.455, 0.740] radius: 6, color: #c2410c
    FR-39: [0.385, 0.705] radius: 6, color: #c2410c
    FR-17: [0.385, 0.900] radius: 6, color: #c2410c
    FR-30: [0.545, 0.420] radius: 6, color: #2563eb
    FR-33: [0.545, 0.740] radius: 6, color: #c2410c
    FR-22: [0.580, 0.740] radius: 6, color: #c2410c
    FR-06: [0.545, 0.900] radius: 6, color: #c2410c
    FR-12: [0.580, 0.900] radius: 6, color: #c2410c
    FR-14: [0.615, 0.900] radius: 6, color: #c2410c
    FR-45: [0.545, 0.865] radius: 6, color: #c2410c
    FR-11: [0.705, 0.900] radius: 6, color: #c2410c
    FR-18: [0.740, 0.900] radius: 6, color: #c2410c
```




