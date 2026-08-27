/**
 * Studio post formu: başlık yazıldıkça slug alanını otomatik türetir.
 *
 * Bilinçli olarak vanilla JS (AACP'nin "sıfır bağımlılık" ruhuyla aynı,
 * bkz. aacp-system.js): Studio'nun form etkileşimleri Stimulus/Turbo gibi
 * ek paketlere ihtiyaç duymayacak kadar basit, importmap üzerinden tek
 * dosya olarak yüklenir.
 *
 * Kurallar:
 *   - Slug alanı kullanıcı tarafından ELLE değiştirilene kadar başlığı
 *     otomatik takip eder ("dirty" izleme). Kullanıcı slug'ı bir kez
 *     değiştirdiyse, o andan sonra başlık değişse bile slug ÜZERİNE
 *     YAZILMAZ — aksi halde kullanıcının kasıtlı düzenlemesi kaybolur.
 *   - Gerçek benzersizlik kontrolü ve nihai normalizasyon SUNUCU
 *     tarafında SlugGenerator ile yapılır (bkz. PostAdminController);
 *     burada sadece kullanıcıya anlık bir önizleme sunulur.
 */
function slugifyPreview(value) {
    const turkishMap = { ç: 'c', Ç: 'c', ğ: 'g', Ğ: 'g', ı: 'i', İ: 'i', ö: 'o', Ö: 'o', ş: 's', Ş: 's', ü: 'u', Ü: 'u' };

    return value
        .replace(/[çÇğĞıİöÖşŞüÜ]/g, (char) => turkishMap[char] ?? char)
        .toLowerCase()
        .trim()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');
}

function initPostForm(form) {
    const titleInput = form.querySelector('[data-post-form-target="title"]');
    const slugInput = form.querySelector('[data-post-form-target="slug"]');

    if (!titleInput || !slugInput) {
        return;
    }

    let slugManuallyEdited = slugInput.value.trim() !== '';

    slugInput.addEventListener('input', () => {
        slugManuallyEdited = true;
    });

    titleInput.addEventListener('input', () => {
        if (slugManuallyEdited) {
            return;
        }

        slugInput.value = slugifyPreview(titleInput.value);
    });
}

/**
 * SEO meta açıklaması için basit karakter sayacı (160 karakter önerisi).
 * Ayrı bir component/kütüphane gerektirmeyecek kadar basit (YAGNI).
 */
function initSeoCharCounters(root) {
    root.querySelectorAll('[data-seo-char-counter]').forEach((textarea) => {
        const counter = textarea.closest('div')?.querySelector('[data-seo-char-count]');
        if (!counter) {
            return;
        }

        textarea.addEventListener('input', () => {
            counter.textContent = String(textarea.value.length);
        });
    });
}

/**
 * İçerik türüne göre (Faz 2 — Modules\Blog\PostSubType) türe özel form
 * bloklarının/panellerinin gösterilip gizlenmesi.
 *
 * İki farklı DOM seviyesi hedeflenir:
 *   - [data-post-sub-type-block]: tekil form satırları (form_row ile
 *     üretilen <div>'in kendisi bulunamaz, bu yüzden alanın kendisine
 *     (input/textarea) attribute basılır ve en yakın form-row sarmalayıcı
 *     gizlenir/gösterilir — form_row'un ürettiği dış <div> class'sız
 *     olduğu için en güvenilir hedef, attribute'un bizzat üzerinde
 *     olduğu elemandır).
 *   - [data-post-sub-type-panel]: PostType.php form alanlarını saran
 *     twig:studio:card blokları (form.html.twig'de kart seviyesinde
 *     işaretlenmiştir) — doğrudan gizlenir/gösterilir.
 *
 * Sayfa yüklendiğinde bir kez ve her postSubType değişiminde çalışır;
 * böylece hem "yeni kayıt" (varsayılan tür) hem "düzenle" (kayıtlı tür)
 * ekranlarında JS'siz başlangıç durumu zaten sunucu tarafında doğru
 * olsa bile (form_row'lar normalde görünür basılır), JS aktifken anında
 * doğru göz önünde bulunan bloklara indirgenir.
 */
function initPostSubTypeToggle(form) {
    const select = form.querySelector('[data-post-form-target="postSubType"]');
    if (!select) {
        return;
    }

    const blocks = Array.from(form.querySelectorAll('[data-post-sub-type-block]'));
    const panels = Array.from(form.querySelectorAll('[data-post-sub-type-panel]'));

    function applyVisibility() {
        const activeType = select.value;

        blocks.forEach((field) => {
            const isActive = field.dataset.postSubTypeBlock === activeType;
            // studio_flat_theme.html.twig'in form_row bloğu her alanı
            // "<div class='mb-sp-sm'>label+widget+help+errors</div>"
            // içine sarar (bkz. cp-core/templates/form/studio_flat_theme.html.twig) —
            // en yakın bu sarmalayıcı gizlenir/gösterilir ki label ve
            // hata mesajları da alanla birlikte gitsin.
            const row = field.closest('div.mb-sp-sm') ?? field.parentElement;
            if (row) {
                row.classList.toggle('hidden', !isActive);
            } else {
                field.classList.toggle('hidden', !isActive);
            }
        });

        panels.forEach((panel) => {
            panel.classList.toggle('hidden', panel.dataset.postSubTypePanel !== activeType);
        });
    }

    select.addEventListener('change', applyVisibility);
    applyVisibility();
}

/**
 * Sayfa ilk yüklendiğinde VE her AJAX swap sonrası çalıştırılması gereken
 * TÜM init fonksiyonlarını tek yerde toplar. media-picker.js kasıtlı
 * olarak buna dahil DEĞİLDİR: o dosya document düzeyinde event delegation
 * kullanır ([data-media-picker-trigger] tıklamalarını global olarak
 * dinler), bu yüzden DOM'a yeni eklenen elemanlar için otomatik çalışır —
 * yeniden init gerekmez.
 *
 * cp-editor-init.js (CKEditor) İLK SAYFA YÜKLEMESİNDE bu fonksiyondan
 * BAĞIMSIZ olarak kendi [data-cpeditor] taramasını zaten yapar (dosyanın
 * kendi altında, importmap sırası garantisiz olduğu için initAll(document)
 * ile YARIŞ DURUMUNA girip aynı textarea'yı iki kez CKEditor'a çevirme
 * riski taşırdı — "geçersiz form elemanına odaklanılamaz" hatasının
 * sebebiydi). Bu yüzden $skipCkEditor=true ile çağrılan initAll(document)
 * hiç CKEditor init ETMEZ; SADECE swap sonrası initTranslationTabs'ın
 * çağırdığı initAll(newContainer, {ckEditor: true}) yeni eklenen
 * textarea'ları CKEditor'a çevirir (o an cp-editor-init.js zaten yüklü ve
 * window.CPaliusCpEditor kesin tanımlıdır, çünkü sayfa tam yüklenmiştir).
 */
function initAll(root, options) {
    const opts = options || {};

    root.querySelectorAll('[data-post-form]').forEach((form) => {
        initPostForm(form);
        initPostSubTypeToggle(form);
    });
    initSeoCharCounters(root);
    initTranslationTabs(root);

    if (opts.ckEditor) {
        root.querySelectorAll('[data-cpeditor]').forEach((el) => {
            if (window.CPaliusCpEditor && !el.__cpEditor) {
                window.CPaliusCpEditor.init(el).catch((err) => {
                    console.error('[CPalius CKEditor] Baslatma hatasi:', err);
                });
            }
        });
    }
}

/**
 * Dil sekmeleri + "bu içeriği çevir" formu için AJAX partial swap.
 *
 * Neden tam bir SPA-router yerine bu basit yaklaşım: admin_posts_create/
 * admin_posts_edit route'ları zaten tam HTML sayfası döndürüyor (Symfony
 * Form + server-side validasyon akışı bozulmasın diye JSON API'ye
 * çevrilmediler, bkz. PostAdminController). Bu fonksiyon sadece dönen
 * HTML'den #post-form-container'ı kesip mevcut DOM'daki aynı elemanla
 * DEĞİŞTİRİR — sunucu tarafı hiçbir şey bilmez, tam sayfa render etmeye
 * devam eder, sadece tarayıcı tam navigasyon yapmaz.
 *
 * Fetch başarısız olursa (ağ hatası, 500, beklenmeyen response — ör.
 * oturum düşüp login'e yönlendirilmiş olması) fallback olarak normal
 * navigasyon yapılır: AJAX bir progressive enhancement'tır, kırılırsa
 * eski (her zaman çalışan) davranışa düşülür.
 */
function initTranslationTabs(root) {
    const container = root.querySelector('#post-form-container') ?? (root.id === 'post-form-container' ? root : null);
    if (!container) {
        return;
    }

    async function swapTo(url, options) {
        let html;
        try {
            const response = await fetch(url, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                ...options,
            });
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }
            html = await response.text();
        } catch (err) {
            console.error('[CPalius Post Form] AJAX geçiş başarısız, normal navigasyona düşülüyor:', err);
            window.location.href = url;
            return;
        }

        const parsed = new DOMParser().parseFromString(html, 'text/html');
        const newContainer = parsed.querySelector('#post-form-container');

        if (!newContainer) {
            // Beklenmeyen response (ör. login sayfası) — güvenli tarafta
            // kal, tam navigasyon yap.
            window.location.href = url;
            return;
        }

        const current = document.querySelector('#post-form-container');
        if (!current) {
            window.location.href = url;
            return;
        }

        current.replaceWith(newContainer);
        initAll(newContainer, { ckEditor: true });

        const finalUrl = newContainer.dataset.currentUrl || url;
        window.history.pushState({}, '', finalUrl);
    }

    container.addEventListener('click', (event) => {
        const locked = event.target.closest('[data-post-translation-locked]');
        if (locked) {
            // Bilerek tıklanamaz bırakılmış bir rozet — sessiz "opacity-50"
            // tek başına yeterince açık değildi (kullanıcı bunu bir hata
            // sanabiliyordu), bu yüzden tıklandığında NEDENİNİ açıkça
            // söyleyen bir uyarı gösteriyoruz.
            event.preventDefault();
            window.alert('Önce bu yazıyı kaydedin, ardından diğer dillere çeviri ekleyebilirsiniz.');
            return;
        }

        const link = event.target.closest('[data-post-translation-link]');
        if (!link) {
            return;
        }
        event.preventDefault();
        swapTo(link.getAttribute('href'), { method: 'GET' });
    });

    container.addEventListener('submit', (event) => {
        const form = event.target.closest('[data-post-translation-assign-form]');
        if (!form) {
            return;
        }
        event.preventDefault();
        swapTo(form.getAttribute('action'), { method: 'POST', body: new FormData(form) });
    });
}

initAll(document);

window.addEventListener('popstate', () => {
    // Geri/ileri tuşu: history.pushState ile URL değişmişti ama DOM
    // güncellenmedi, tam navigasyon en güvenli çözüm (kapsamı büyütmeden).
    window.location.reload();
});
