# WordPress için Local TTS

**English:** [README.md](README.md)

Local TTS, WordPress yazılarına **Dinle** düğmesi ekler. Ses, açık metin-konuşma modelleriyle
(ONNX Runtime Web + WebAssembly) **okurun tarayıcısında** üretilir. Yazı metni hiçbir seslendirme
servisine gönderilmez; sitenin GPU'ya ya da API anahtarına ihtiyacı yoktur.

Local TTS, yerel ses modellerini WordPress'te çalıştırmak için bir araçtır. **Hiçbir ses modeli
içermez.** Site sahibi sesleri eklentinin Ses modelleri sayfasından seçer; eklenti onları
yayımcılarından indirir, doğrular ve okurlara sunar. Her ses kendi lisansına tabidir.

## Özellikler

- Yazının haritası (paragraf başına bir çizgi, başlıklar işaretli), paragrafa atlama, kalan süre,
  0.8×–1.4× hız, okunan paragrafın vurgulanması ve yüzen mini çubuk.
- İlk cümle hazır olunca başlar; çalan cümlenin yalnız bir sonrası hazırlanır.
- Sesler tarayıcıda önbelleğe alınır (Cache Storage) ve SHA-256 ile denetlenir.
- Her yazıyı kendi dilinde okur (yazı ayarı, Polylang/WPML ya da site dili).
- Motorlar: **EMA Lightning** (Türkçe) ve **Piper** (VITS + eSpeak NG fonemleyici, 40'tan fazla dil).
- Sesler: hazır ön ayarlar, [Piper ses kütüphanesinden](https://huggingface.co/rhasspy/piper-voices)
  100'den fazla ses ya da kendi adreslerinizden Piper uyumlu herhangi bir ses. Yönetim ekranından
  eklenip çıkarılır.
- 4 MB'lık adımlarla sürdürülebilir kurulum, kaynak yedekleme; sunucu indiremiyorsa FTP ile elle yükleme.
- Yönetim arayüzü: İngilizce, Türkçe, Almanca, Fransızca, İspanyolca.
- Güncellemeler bu deponun GitHub sürümlerinden (Releases) gelir.

## Kurulum

1. [Son sürümden](https://github.com/m4v3r4/Wordpress-Local-TTS/releases/latest) `local-tts-<sürüm>.zip` dosyasını indirin.
2. **Eklentiler → Yeni ekle → Eklenti yükle** ile kurup etkinleştirin.
3. **Local TTS → Ses modelleri → Önerilen sesi yükle** (ya da istediğiniz sesleri ekleyin).
4. **Local TTS → Görünüm**: düğmenin nerede görüneceğini seçin.

Gereksinimler: WordPress 6.5+, PHP 7.4+, HTTPS ve WebAssembly ile modül Worker destekleyen güncel
bir tarayıcı. Sunucu `.wasm` dosyalarını `application/wasm`, `.mjs` dosyalarını `text/javascript`
olarak göndermeli; **Local TTS → Durum** sayfası bunu denetler.

## Lisanslar

Eklenti **GNU GPL v3.0 veya sonrası** ile lisanslıdır ([LICENSE](LICENSE)).
`assets/js/engine/ema-lightning.js`, Apache-2.0 lisanslı
[ozcancelik/ema-lightning-web](https://github.com/ozcancelik/ema-lightning-web) kodundan
uyarlanmıştır; Apache-2.0, GPLv3 ile uyumludur ve bildirimi [local-tts/licenses](local-tts/licenses)
içinde korunur.

Ses modelleri, ONNX Runtime (MIT) ve Piper fonemleyicisi (eSpeak NG, GPL-3.0-or-later) eklentinin
parçası değildir; site sahibi tarafından indirilir. **Sunduğunuz seslerin lisanslarına uymak sizin
sorumluluğunuzdadır.** Yönetim ekranı her sesin lisansını kurulumdan önce gösterir, her bileşenin
yanına yayımcının lisans metinleriyle bir `CREDITS.txt` yazar ve oynatıcının altında atıf satırı
gösterebilir. Almanca, Fransızca ve İspanyolca Piper ön ayarları “lessac” sesinden türetilmiştir;
Blizzard 2013 veri seti lisansı ticari kullanımı kısıtlayabilir. Ayrıntılar: [NOTICE](local-tts/NOTICE).

## Geliştirme

```bash
bash tests/run.sh                 # sözdizimi, kaynak denetimi, PHP ve JS testleri
python3 tools/i18n.py build       # metinler değişince çeviri dosyaları
python3 tools/build.py            # dist/local-tts-<sürüm>.zip
```

Yeni sürüm: `local-tts/local-tts.php` (başlık ve `LTTS_VERSION`) ile `readme.txt` içindeki
`Stable tag` güncellenir, `git tag v0.5.0 && git push origin v0.5.0` yapılır. **Release** iş akışı
testleri çalıştırır, ZIP'i üretir ve GitHub sürümüne ekler; eklentinin kurulu olduğu siteler
güncellemeyi Eklentiler sayfasında görür.
