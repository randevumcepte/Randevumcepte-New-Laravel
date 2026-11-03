# Çağrı Merkezi — v2 (API-first, tek sekmeli sayfa)

Eski panelin **4 ayrı sayfası** — Çağrı Merkezi paneli (`arama-dashboard`: Performans + Liste Yönetimi),
Arama Randevu Takvimi (`arama-randevu-takvim`), Çağrı Kayıtları (`arama-cagri-kayitlari`), Atanan Aramalar /
Çalışma Ekranı (`arama-listelerim`) — ve Flutter `lib/yonetici/cagrimerkezi/` (`CagriApi` → `/api/v1/cagri-merkezi/*`)
artık **tek uç kümesinden** beslenir. Web: `/v2/cagri-merkezi?sube=&p=calisma|takvim|kayitlar|performans|listeler`.

## Sekmeler (sunucu kayıt defteri)
`Domain/CagriMerkezi/CagriMerkeziSekmeleri` sekme listesini ve görünürlüğünü tutar; `GET /cagri-merkezi/acilis`
ile web (`lib/v2-sekme.js`) ve Flutter (TabBar) aynı listeyi çizer. Personel (rol 5): Çalışma Ekranı, Arama
Randevuları, Çağrı Kayıtları. Yönetici: + Performans, Liste Yönetimi.

**Yeni sekme eklemek:** `CagriMerkeziSekmeleri::TANIMLAR`'a tek satır → `public/js/cagri-merkezi/<kod>.js`
(`export async function ciz(kap, ctx, params)`) → `cagri-merkezi-v2.js` `MODULLER`'e tek satır. Uçlar değişmez.

**Yeni log hareketi eklemek:** işlemin servisinde `AktiviteLog::yaz($salonId, $uid, 'cagri_<islem>', 'aranacak_musteri'|'arama_listesi', $id, $ad, $aciklama)`;
Log Hareketleri ekranında otomatik görünür. Etiket/kategori gerekirse `HareketKatalogu::ISLEMLER`'e tek satır.
Çağrı merkezindeki tüm yazmalar (sonuç, arama başlatma, arama randevusu ekle/ertele/iptal, randevu verildi,
telefonda satış, anket, liste durum/personel/taşı/sil, CSV) bu yolla loglanır (eski kodda çoğu loglanmıyordu).

## Katmanlar
| Katman | Dosya | Sorumluluk |
|---|---|---|
| Domain | `AramaSonucu` | sonuç kodu (0–9) → etiket, renk, agent seçebilir mi, takvimde mi; kuyruk / performans filtre grupları; segment sırası; istemci sözlüğü |
| Domain | `SonucKaydiPlani` | "Sonucu Kaydet" kararı (eski `santral_not_ekle`): nihai durum, zaman koru/temizle, hatırlatma bayrakları; tamamla / arama başladı / arama randevusu kolonları |
| Domain | `AramaRandevuDurumu` | takvim rengi+metni, ajanda durumu, hatırlatma fazı (saat dışarıdan) |
| Domain | `SantralKaydi` | çağrı merkezi CDR yorumu (originate dahil), ses kaydı adayları, not↔ses eşleme, URL |
| Domain | `CagriMerkeziErisim`, `ListeDurumu`, `CagriMerkeziSekmeleri` | erişim kapsamı + KVKK maskesi; liste durumu (1/3/2); sekmeler |
| Servis | `CagriMerkeziErisimServisi` | kimlik + kapsam + `uyelik_turu=3` + rol/dahili + **kayıt düzeyi** erişim (liste / aranacak müşteri) |
| Servis | `AramaListesiServisi`, `KuyrukServisi` | liste kartları + yönetim (segment, taşı, ata, durum, sil, CSV); kuyruk + durum çipleri |
| Servis | `GorusmeSonucServisi`, `GorusmeGecmisiServisi` | sonuç yazımı (tek transaction + görüşme geçmişi satırı); geçmiş + ses eşleme |
| Servis | `AramaRandevuServisi`, `PerformansServisi`, `CagriKayitlariServisi` | takvim / hatırlatma / ajanda; performans; çağrı kayıtları |
| Servis | `AramaBaslatmaServisi` + `Santral/SantralAmiIstemcisi` | KVKK uyumlu click-to-call (AMI originate, agent-first) |
| Servis | `TelefondaSatisServisi` → `Satis/TekKalemSatisServisi` | satış açma (Ön Görüşme "Satış Yapıldı" ile **aynı** yazıcı) + sonucu 7 işaretle |
| Servis | `AnketGonderimServisi` | outbox `anket_gonder` (eski monolit `v2:bildirim-isle` mevcut anket kodunu çalıştırır) |
| Controller | `Api/V2/CagriMerkezi*`, `CagriIslem*`, `CagriRapor*`, `CagriListeController` | ince; yazmalar `CAGRI_MERKEZI_V2_YAZMA_AKTIF` (varsayılan açık; kapalıyken 409) |
| Web | `cagri-merkezi-v2.js` + `cagri-merkezi/{ortak,calisma,takvim,kayitlar,performans,listeler}.js` | `ortak.js` görüşme kokpiti Çalışma Ekranı ve takvim modalında **aynı** bileşen |

## Uçlar (`auth:isletmeyonetim-api`, `salon_id` zorunlu, zarf `{basarili, veri, mesaj}`)
| v1 (`/api/v1/cagri-merkezi` + web `/isletmeyonetim`) | v2 (`/api/v2/cagri-merkezi`) | Not |
|---|---|---|
| — (sayfa render) | `GET /acilis` | erişim, sekmeler, sözlükler (sonuç/kuyruk grupları/takvim lejandı/liste durumları), scriptler, kategoriler, personeller |
| `GET arama-kartlarim` | `GET /listeler?arsiv=&tumu=` | `kartlar[]` alanları aynı (+`personel_id`, `kuyruk`); `tumu` yönetici kuyruğu boş listeleri de alır |
| `POST arama-liste-detay {arama_detay_id,page,perPage,arama}` | `GET /listeler/{id}/musteriler?grup=&ara=&page=&per_page=` | satır alanları Flutter `AranacakMusteri` ile aynı (`telefonGizlenmis` dahil) + `renk`; `gruplar[]` sayaçları sunucuda; ListeIstegi meta |
| — | `GET /kayit/{id}` | tek kuyruk satırı (+`liste_baslik`) |
| `POST musteri-gecmisi` | `GET /kayit/{id}/gecmis` | `gecmis[]` alanları aynı (+`renk`); `santral_hata` |
| `POST ongorusme-bilgi` | `GET /kayit/{id}/musteri` | `{id, ad, telefon}` — ad tam, telefon yalnız `musteri.telefon_gor` ile |
| `POST arama-baslat {aranacak_musteri_id}` | `POST /kayit/{id}/ara` | softphone modu yok (Bria originate) |
| `POST arama-baslat-numara {numara\|user_id}` | `POST /ara {user_id?, numara?}` | |
| `POST not-ekle {arama_detay_id, aranacak_musteri_id, noticerik, sonuc, santralnottarih, santralnotsaat, kategori_id, alt_kategori_id, satis_tutari, arama_personel_id}` | `POST /kayit/{id}/sonuc {sonuc, not, tarih, saat, kategori_id, alt_kategori_id, satis_tutari, arama_personel_id}` | → `{durum, durum_metin, arama_id}` |
| `POST randevu-verildi-isaretle` / `randevu-olusturuldu-isaretle` | `POST /kayit/{id}/randevu-verildi` | ikisi tek uç (rozet + 8 + geçmiş satırı) |
| `POST arama-randevu-iptal` | `POST /kayit/{id}/iptal` | |
| `POST arama-randevu-ertele {tarih,saat}` | `POST /kayit/{id}/ertele {tarih,saat}` | |
| `POST hizli-satis {musteri_id, kalem_tip, kalem_id, fiyat, odeme_yontemi, adet}` | `POST /kayit/{id}/satis {tur, katalog_id, fiyat?, seans?, adet?, personel_id?, not?, idempotency_key}` | → `{adisyon_id, toplam_tutar}`; **tahsilat v2 tahsilat ekranında**; katalog `GET /katalog` |
| `POST anket-gonder` | `POST /kayit/{id}/anket` | outbox |
| `POST arama-randevu-ekle {musteri_id, personel_id, tarih, saat, not}` | `POST /arama-randevusu` (aynı alanlar) | |
| `GET musteri-select?q` | `GET /musteri-ara?q=` | `[{id, ad, telefon}]` (randevu formuyla aynı) |
| `GET arama-randevu-takvim-verileri?start&end` | `GET /takvim?bas=&bit=` | `olaylar[]` Flutter `TakvimEvent` alanları aynı (+`durum_kod`, `islenmis`, `saat`) + `lejand[]` |
| `GET yaklasan-randevular` | `GET /yaklasan` | `randevular[]` aynı (`faz`) |
| `GET arama-randevularim?tarih` | `GET /ajanda?tarih=` | aynı |
| `GET dashboard-verileri` | `GET /performans` | `kartlar[]` aynı (+`ilerleme`) + `ozet` |
| `POST dashboard-personel-detay {personel_id}` | `GET /performans/{personelId}` | `notlar[]` aynı (+`renk`) + `gruplar[]` |
| web `arama-cagri-kayitlari-veri` | `GET /cagri-kayitlari?tarih1=&tarih2=&yon=&ara=&page=&per_page=` | sayfalı; `ozet{toplam,kayitli,gelen,giden}`, `hata`, `kesildi` |
| `GET liste-segmentler?arama_id` | `GET /listeler/{id}/segmentler` | + `toplam`, `varsayilan_aktarim`, segment `renk` |
| `POST segment-ata` + `POST toplu-aktar` | `POST /listeler/{id}/tasi {kodlar[], personel_id, baslik?}` | iki uç tek akış |
| `POST liste-personel-degistir` | `PATCH /listeler/{id}/personel {personel_id\|null}` | |
| `POST liste-durum` | `PATCH /listeler/{id}/durum {durum:1\|2\|3}` | |
| `POST liste-sil` | `DELETE /listeler/{id}` | |
| `POST segment-indir` | `GET /listeler/{id}/csv?kod=` | text/csv |
| `GET scriptler`, `GET kategoriler`, `GET personeller` | `GET /acilis` içinde | |

Kapsam dışı (eski panelde kalır, sonraki faz): filtreyle **arama listesi oluşturma** (`arama_filtre_onizleme` +
`arama_listesi_ekle`, `AramaFiltreService`), script / kategori **tanımlama** ekranı (`cagri-ayarlari`), WhatsApp
manuel mesaj (`whatsapp-gonder`, yalnız mobil kullanıyordu), WebRTC softphone.

## Eskiye göre bilinçli farklar
- **Güvenlik:** v1/web'de `arama_liste_detay_getir`, `santral_not_ekle`, `cagri_musteri_gecmisi`, `arama_listesi_arandi_isaretle`
  kimlik/salon denetimi yapmıyordu; v2'de her kayıt erişimi salon + (personelde) liste sahipliğiyle denetlenir.
- **Personel** yalnız kendine atanan listenin kayıtlarını işler (eski `cagriListeYetkiliMi` salon üyeliğine bakıyordu).
- **KVKK:** maske tek kural (`CagriMerkeziErisim::maskele` = personel ∧ ¬`musteri.telefon_gor`) tüm uçlarda;
  takvim/ajanda/kayıtlar dahil. Telefon maskesi v2 standardı (`0532 *** ** 67`).
- **Telefonda satış** artık v2 satış yazıcısıyla açılır, tahsilat v2 tahsilat ekranında alınır (eski kod ödemeyi
  pivotsuz/komisyonsuz yazabiliyordu). Paket/hizmette katalogdan farklı fiyat `satis.indirim_uygula` ister.
- Sonuç yazımı + görüşme geçmişi satırı **tek transaction** (eskiden ayrıydı).
- Çağrı Kayıtları tarih aralığı seçilebilir (varsayılan 30 gün; eskiden sabit 60) ve sunucuda sayfalanır.
- AMI kimlik bilgileri koddan çıkarıldı → sunucu `.env`: `SANTRAL_AMI_HOST`, `SANTRAL_AMI_KULLANICI`, `SANTRAL_AMI_SECRET`.

## Flutter geçişi (kullanıcı "geçiş" deyince — şimdilik Flutter'a dokunulmuyor)
`CagriApi` base `/api/v1/cagri-merkezi` → `/api/v2/cagri-merkezi`; liste cevapları `veri.*` altında. `sube` yerine
`salon_id`. Model alan adları korunduğu için `cagri_models.dart` `fromJson`'ları değişmez; değişen yalnız istek
biçimleri (yukarıdaki tablo: id path'te, `noticerik`→`not`, `santralnottarih/saat`→`tarih/saat`, `kalem_tip/kalem_id`→`tur/katalog_id`).
Sekme listesi `acilis.sekmeler`'den çizilmeli (gate: mevcut `uyelik_turu==3` + rol kuralı sunucuda).
