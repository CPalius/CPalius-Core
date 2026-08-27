import suneditor, { plugins } from 'suneditor';
import 'suneditor/css';
import './media-picker.js';

/**
 * [data-suneditor] işaretli her textarea'yı SunEditor (MIT lisanslı) ile
 * zenginleştirir. Eski GPL lisanslı zengin metin editörü buradan tamamen
 * kaldırıldı — kurumsal/ticari (ERP/CRM) altlık projelerde GPL editör
 * lisans riski istenmiyor (bkz. "SCENARIO B" kararı). Medya Kütüphanesi
 * entegrasyonu, media-picker.js'teki AYNI window.CPaliusMediaPicker
 * köprüsünü kullanır — tek kod yolu korunur.
 *
 * SunEditor 3.x — Class tabanlı plugin API:
 * Eklentiler artık düz nesne değil, temel sınıfları extend eden ES class'lar
 * olarak tanımlanmalıdır. Command tipi eklentiler "command" static type'a
 * sahip olmalı ve action() metodunu implement etmelidir.
 * plugins seçeneği constructor (class) array formatında verilir.
 */

/**
 * Medya Galerisi'ni açmak için özel SunEditor 3.x eklentisi.
 * "command" tipi: toolbar butonuna tıklandığında bir eylem gerçekleştirir.
 */
class CpGalleryPlugin {
    static type = 'command';
    static key = 'cpGallery';
    static className = '';
    static options = {};

    constructor(core) {
        this.$ = core ? core.$ : null;
        this.title = 'Medya Kütüphanesi';
        this.icon = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M22 16V4c0-1.1-.9-2-2-2H8c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2zm-11-4l2.03 2.71L16 11l4 5H8l3-4zM2 6v14c0 1.1.9 2 2 2h14v-2H4V6H2z"/></svg>';
        this.inner = null;
        this.beforeItem = null;
        this.afterItem = null;
        this.replaceButton = null;
        this._editorRef = null;
    }

    action() {
        if (!window.CPaliusMediaPicker || !this._editorRef) return;
        const editorInstance = this._editorRef;
        window.CPaliusMediaPicker.open((asset) => {
            editorInstance.insertImage([{
                src: asset.url,
                name: asset.originalName || 'image',
                alt: asset.originalName || 'image',
            }]);
        });
    }
}

function initSunEditor(textarea) {
    // plugins seçeneği SunEditor 3.x'te CLASS (constructor) array'i bekler
    const allPluginClasses = Object.values(plugins);

    const editor = suneditor.create(textarea, {
        plugins: [...allPluginClasses, CpGalleryPlugin],
        height: 'auto',
        minHeight: '550px',
        width: '100%',
        buttonList: [
            ['undo', 'redo'],
            [':p-Metin-default.more_paragraph', 'paragraphStyle', 'font', 'fontSize', 'fontColor', 'backgroundColor'],
            ['bold', 'underline', 'italic', 'strike'],
            [':t-Biçim-default.more_text', 'subscript', 'superscript', 'removeFormat'],
            ['outdent', 'indent', 'align', 'list', 'lineHeight'],
            [':e-Ekle-default.more_plus', 'cpGallery', 'image', 'video', 'table', 'link', 'hr'],
            ['fullScreen', 'showBlocks', 'codeView'],
            ['preview', 'print'],
        ],
        // Resim: sürükle-bırak zaten çekirdek davranış, boyutlandırma
        // tutamaçları piksel/yüzde ikisini de destekler (resizingBar).
        imageResizing: true,
        imageHeightShow: true,
        imageAlignShow: true,
        imageWidth: '100%',
        imageMultipleFile: true,
        // Yapıştırılan/eklenen HTML, SunEditor'ın kendi temiz çıktısına
        // normalize edilir; ekstra inline style/attribute üretilmez —
        // RichTextSanitizer zaten sunucu tarafında son güvenlik katmanı
        // olarak kalır (bkz. PostAdminController::mapDtoToNode()).
        pasteTagsWhitelist: 'p|div|span|br|a|img|table|thead|tbody|tr|th|td|ul|ol|li|h1|h2|h3|h4|h5|h6|blockquote|pre|code|strong|b|em|i|u|s',
        tabDisable: false,
    });

    // cpGallery plugin instance'ına editor referansını ilet (medya seçici için)
    editor.onload = () => {
        try {
            const pm = editor.core?.pluginManager;
            if (pm) {
                const inst = pm.get('cpGallery');
                if (inst) inst._editorRef = editor;
            }
        } catch (_) {
            // Fail-safe: erişim başarısız olursa cpGallery sessizce devre dışı
        }
    };

    editor.onImageUploadBefore = (files, info, uploadHandler) => {
        if (!window.CPaliusMediaPicker) {
            return true;
        }

        window.CPaliusMediaPicker.open((asset) => {
            editor.insertImage([{ src: asset.url, name: asset.originalName || 'image' }]);
        });

        // Kütüphaneden seçim akışını kullanıyoruz; SunEditor'ın kendi
        // upload isteğini iptal ederiz (false = varsayılan upload'ı durdur).
        return false;
    };

    return editor;
}

document.querySelectorAll('[data-suneditor]').forEach((textarea) => initSunEditor(textarea));

window.CPaliusSunEditor = { init: initSunEditor };

