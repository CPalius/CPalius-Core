# Githeme

GitHub "dimmed" gri paletinden (referans: thedevgri) türetilmiş koyu/açık tema. Koyu varsayılan, açık ikiz; açıkta header, hero ve footer koyu kalır.

Tasarım sözleşmesi: 1px keskin çizgiler, 6px köşe, gölge/degrade/hap düğme yok. Renk yalnızca anlam taşır: mavi = bağlantı, yeşil = birincil eylem, turuncu = etkin sekme, kırmızı = tehlike. Yoğun, taranabilir listeler; gri başlık şeritli kutular; iki satırlı header (üstte marka, arama, araçlar; altta altı çizgili sekmeler).

## Dosyalar

- `dist/githeme.css`: token katmanı (`--gh-*`, koyu ve açık). Default'un `--slw-*`, font, boşluk ve boyut token'larını da bu paletle yeniden tanımlar. Operatörün Studio font/renk seçimi bunun üstüne biner.
- `dist/githeme-recolor.css`: **üretilmiş**. Default'un `style.css` ve `forum.css` dosyalarını ve DnsTools/Messages/Showcase modüllerinin satır içi stil parçalarını okur, her rengi rolüne göre (zemin, metin, kenarlık, marka, durum) `--gh-*` token'ına yönlendirir. Elle düzenlemeyin.
- `dist/githeme-ui.css`: elle yazılan bileşen katmanı (iki satırlı header, sekmeler, düğmeler, forum kutuları, hero, form alanları, footer).
- `dist/githeme.js`: tema anahtarı (`data-theme`, localStorage `gh-theme`). Adres çubuğunda `?gh-theme=dark|light` geçici olarak temayı zorlar (ekran görüntüsü ve test için).
- `tools/recolor.php`: üretici. Default CSS'i ya da modül stilleri değişince yeniden çalıştırın: `php tools/recolor.php`.
- `Resources/translations/`: temanın kendi çevirileri.

Şablonlar yalnızca `layout`, `partials/header` ve `partials/portal/hero` için geçersiz kılınır; kalanı `@Theme` yedeğiyle Default temadan gelir, bu yüzden Default kurulu kalmalıdır.

Dosyalar `cp_theme_url()` ile sürümlü sunulur ve `public/themes/githeme/` altına ilk kullanımda otomatik kopyalanır.
