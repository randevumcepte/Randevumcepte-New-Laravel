# Strangler — Log Hareketleri (v2)

Eski web `/isletmeyonetim/log-hareketleri` (`StoreAdminController@logHareketleri` + `log-hareketleri.blade`)
ekranının v2 karşılığı. **Salt okuma.** Web paneli (`/v2/log-hareketleri`) ve Flutter **aynı**
`/api/v2/hareketler*` uçlarını kullanır. Flutter'da henüz bu ekran yok; uç ileride drop-in kullanılabilir.

Tablo `salon_aktivite_log` üç yazıcıyla ORTAK doldurulur (bu domain hiçbirine dokunmaz):

| Yazıcı | Yer | meta.kaynak |
|---|---|---|
| `App\SalonYonetim\Audit::log` | eski web paneli (elle) | yok → panel (kullanıcı varsa) / sistem |
| `PanelAktiviteLog` middleware | eski web paneli (her POST otomatik) | `panel_oto` |
| `App\SalonYonetim\Audit::logApi` | eski mobil v1 API | `mobil` |
| `App\Support\AktiviteLog::yaz` | yeni /api/v2 (web v2 + Flutter v2) | `v2` |

## Katmanlar

| Katman | Dosya | Sorumluluk |
|---|---|---|
| Domain (saf) | `app/Domain/Aktivite/HareketKategorisi.php` | kategori sözlüğü (etiket/renk/zemin/ikon) + eski blade'in anahtar kelime kuralı |
| Domain (saf) | `app/Domain/Aktivite/HareketKaynagi.php` | panel / mobil / v2 / sistem — meta.kaynak'tan çözüm |
| Domain (saf) | `app/Domain/Aktivite/HareketKatalogu.php` | işlem kodu + hedef tipi → okunur etiket, kategori override'ı (**genişleme noktası**) |
| Okuma | `Services/Aktivite/HareketSorgusu.php` | filtreli liste + filtre seçenekleri (DISTINCT'ler 10 dk önbellekli) |
| Sunum | `Services/Aktivite/HareketSunumu.php` | JSON sözleşmesi (istemci hiçbir şey hesaplamaz) |
| HTTP | `HareketController`, `routes/v2/hareket.php` | ince controller, doğrulama, erişim kuralı |
| Web | `public/js/lib/v2-hareket.js` (+ `css/v2-hareket.css`) | **ortak** hareket tablosu bileşeni — sayfa ve gömülü kullanım |
| Web | `public/js/log-hareketleri-v2.js`, `views/v2/log-hareketleri.blade.php` | yalnız sayfa başlığı + bileşen çağrısı |

Yeniden kullanılanlar (kopya yok): `V2Controller::salonDenetle`, `GuvenlikKapisi` (yeni `rolDisiDenetle`;
`yoneticiDenetle` artık bunun `[5]` hali), `ListeIstegi` (sayfalama/arama/sıralama sözleşmesi),
`ApiCevap`, `lib/v2-tablo.js veriTablosu` (10/25/50/100, tablo içi arama, sıralama).

## Uçlar

```
GET /api/v2/hareketler/secenekler?salon_id=
  → {kategoriler[{kod,etiket,renk,zemin,ikon}], kaynaklar[{kod,etiket,ikon}],
     islemler[{kod,etiket,kategori}], kullanicilar[{id,ad}], hedef_tipleri[{kod,etiket}]}

GET /api/v2/hareketler?salon_id=
      &islem=randevu_sil &kategori=silme &kaynak=mobil &kullanici_id=12
      &hedef_tipi=musteri &hedef_id=42 &tarih_bas=2026-11-01 &tarih_bit=2026-11-03
      &page=1 &per_page=25 &ara=… &sirala=zaman|kullanici|islem|hedef &yon=desc
  → {data[], current_page, last_page, per_page, total}
```

Satır (`data[]`):

```json
{
  "id": 981, "zaman": "2026-11-03T14:05:11+03:00", "tarih": "03.11.2026", "saat": "14:05:11", "once": "2 saat önce",
  "kullanici": {"id": 7, "ad": "Ayşe", "rol": "Hesap Sahibi", "tip": "yetkili", "bas_harf": "A", "renk": "#7c3aed"},
  "islem": {"kod": "randevu_sil", "etiket": "Randevu sil",
            "kategori": {"kod": "silme", "etiket": "Silme", "renk": "#ef4444", "zemin": "#fef2f2", "ikon": "bi-trash3-fill"}},
  "kaynak": {"kod": "mobil", "etiket": "Mobil", "ikon": "bi-phone"},
  "hedef": {"tip": "randevu", "tip_etiket": "Randevu", "id": 5512, "etiket": "Ahmet Y. - 03.11.2026 15:00"},
  "aciklama": "…", "detay": {"eski_durum": "onaylandi"}, "ip": "85.1.2.3"
}
```

Flutter: renk `Color(int.parse('0xFF' + renk.substring(1)))`; ikon `kategori.kod` / `kaynak.kod` üzerinden
eşlenir (`ikon` alanı web içindir). `user_agent` dönmez (veri minimizasyonu).

## Erişim

Eski ekranla birebir: kimlik + salon kapsamı + kullanıcının bu salonda **Yönetici (rol 4) veya Personel
(rol 5)** rolü YOK → Hesap Sahibi ve rol ataması olmayan yetkili görür; diğerleri 403. v2 menü öğesi
(`PanelMenu`) `v2_rota=/v2/log-hareketleri` ile bu sayfaya bağlı; personel rolünde gizli (`rol5_gizle`).

## Yeni hareket eklemek

1. İşlemin yapıldığı serviste tek çağrı:
   `AktiviteLog::yaz($salonId, $uid, 'kampanya_yayinla', 'kampanya', $id, $ad, 'açıklama', ['ek' => 'bilgi']);`
2. Başka hiçbir şey gerekmez — liste, filtreler, kategori, etiket kendiliğinden çalışır:
   etiket koddaki kelimelerden (`Kampanya yayınla`), kategori `HareketKategorisi` anahtar kelime kuralından.
3. Etiket yetersizse ya da kural yanlış kategoriye düşürüyorsa `HareketKatalogu::ISLEMLER`'e tek satır.
4. Koddaki bir kelime ASCII kalıyorsa `HareketKatalogu::KELIMELER`'e ekle (tüm kodlarda birden düzelir).
5. Yeni hedef tipi için isteğe bağlı `HareketKatalogu::HEDEFLER`.

Not: filtre seçenekleri 10 dk önbelleklidir; yeni bir kod listede/kategori filtresinde en geç 10 dk sonra görünür
(listede ise hemen görünür).

## Başka ekrana gömmek (ör. müşteri kartı "Hareketler" sekmesi)

```js
import { hareketTablosu } from '/js/lib/v2-hareket.js?v=1';
hareketTablosu(el('#kap'), { salonId, sabit: { hedef_tipi: 'musteri', hedef_id: musteri.id },
                             filtreler: false, anahtar: 'musteri_hareket' });
```

Erişim kuralı uçta aynı kalır (yalnız hesap sahibi); gömülü kullanımda başka bir yetki gerekecekse
`HareketController::denetle` tek yerde genişletilir.

## Eskiden bilinçli farklar

| Eski | v2 |
|---|---|
| İşlem rozeti ham kod (`randevu_sil`) | okunur etiket (`Randevu sil`), ham kod `title`'da |
| Tek gün filtresi (`whereDate`) | tarih aralığı, indeks dostu (`created_at >= / <`) |
| Kategori yalnız görsel | kategori + kaynak + hedef tipi filtreleri; renk açıklaması tıklanınca filtreler |
| Sabit 50 kayıt | 10/25/50/100 + sıralanabilir başlıklar |
| İstek başına self-heal `Schema::create` | yok (migration yalnız eski repoda) |
