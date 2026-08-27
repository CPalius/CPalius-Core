<?php

declare(strict_types=1);

namespace Modules\Blog\Form\DTO;

use App\Entity\Node;
use Modules\Blog\PostSubType;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * PostAdminController'ın Node entity'sine/JSON data alanına yazmadan önce
 * kullandığı form-katmanı DTO'su. Manifesto Law 3.1 gereği Node hâlâ tek
 * tablo + hibrit JSON modelidir; bu sınıf bir Doctrine entity DEĞİLDİR,
 * yalnızca PostType formunun map edildiği geçici bir veri taşıyıcısıdır
 * (bkz. PostAdminController::mapDtoToNode()).
 *
 * category_ids: Node::$categories (ManyToMany join table) ile senkronlanır.
 * tags: serbest metin (virgülle ayrılmış), TagRepository::findOrCreateByNames()
 *   ile Tag entity'lerine çözülür — Slawman'daki Tom Select/AJAX yaklaşımı
 *   yerine CPalius'un mevcut basit metin girişi korunur.
 * body: form seviyesinde ham HTML olarak taşınır; sanitizasyon bilinçli
 *   olarak burada DEĞİL, mapDtoToNode() içinde RichTextSanitizer ile yapılır
 *   (Manifesto Law 5.3) — DTO saf veri taşıyıcısı olarak kalır.
 *
 * postSubType + type_fields (projectRepoUrl/projectDemoUrl/softwareVersion/
 * softwareDownloadUrl/noteCodeSnippet): Modules\Blog\PostSubType ile
 * senkron 4 türlü ikincil sınıflandırma. noteCodeSnippet BİLİNÇLİ OLARAK
 * hiçbir zaman RichTextSanitizer'dan GEÇMEZ ve hiçbir zaman Twig'te "|raw"
 * ile basılmaz (bkz. blog/cards/_not.html.twig, blog/show/_not.html.twig)
 * — düz metin olarak <pre><code> içinde auto-escape ile render edilir,
 * bu yüzden HTML sanitizasyonuna hiç ihtiyaç duymaz (Manifesto Law 5.3).
 */
#[Assert\Callback('validatePostSubTypeFields')]
final class PostFormModel
{
    #[Assert\NotBlank(message: 'Başlık zorunludur.')]
    #[Assert\Length(max: 255, maxMessage: 'Başlık en fazla {{ limit }} karakter olabilir.')]
    public string $title = '';

    /**
     * Boş bırakılırsa PostAdminController, title'dan SlugGenerator ile
     * otomatik üretir — bu yüzden burada NotBlank YOKTUR, sadece format/
     * uzunluk kısıtlanır.
     */
    #[Assert\Length(max: 255, maxMessage: 'Slug en fazla {{ limit }} karakter olabilir.')]
    #[Assert\Regex(
        pattern: '/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
        message: 'Slug yalnızca küçük harf, rakam ve tire (-) içerebilir.',
        match: true,
    )]
    public ?string $slug = null;

    #[Assert\Length(max: 500, maxMessage: 'Özet en fazla {{ limit }} karakter olabilir.')]
    public ?string $excerpt = null;

    #[Assert\NotBlank(message: 'İçerik zorunludur.')]
    public string $body = '';

    public bool $isFeatured = false;

    /**
     * Node::STATUS_* sabitlerinden biri — Studio'daki "Yayın Durumu"
     * seçimini taşır. NOT: burada seçilen değer Node'a HAM olarak
     * yazılmaz; PostAdminController::resolveEffectiveStatus() bunu
     * publishedAt ile birlikte değerlendirip nihai durumu hesaplar
     * (bkz. o metodun doküman notu — "Yayınlandı + gelecek tarih"
     * kombinasyonu otomatik olarak "scheduled"a döner).
     */
    #[Assert\Choice(
        choices: [Node::STATUS_DRAFT, Node::STATUS_PUBLISHED, Node::STATUS_SCHEDULED],
        message: 'Geçersiz yayın durumu.',
    )]
    public string $status = Node::STATUS_DRAFT;

    /**
     * Yayınlanma tarihi (kullanıcının Studio'da seçtiği). Boş bırakılırsa
     * ve durum "Yayınlandı" ise PostAdminController "şimdi" kabul eder;
     * "Zamanlandı" durumunda ise bu alan mantıksal olarak zorunludur
     * (bkz. validatePostSubTypeFields() değil, ayrı bir Callback burada
     * gerekmez çünkü resolveEffectiveStatus() eksik tarihi "şimdi" kabul
     * ederek fail-safe davranır — kullanıcı gelecekte bir tarih GİRMEDEN
     * "Zamanlandı" seçemez zaten, ön yüzde required olarak işaretlenir).
     */
    public ?\DateTimeImmutable $publishedAt = null;

    #[Assert\Length(max: 160, maxMessage: 'Meta açıklama en fazla {{ limit }} karakter olabilir.')]
    public ?string $seoMetaDescription = null;

    #[Assert\Length(max: 255, maxMessage: 'Odak anahtar kelime en fazla {{ limit }} karakter olabilir.')]
    public ?string $seoFocusKeyword = null;

    #[Assert\Length(max: 255, maxMessage: 'Canonical URL en fazla {{ limit }} karakter olabilir.')]
    #[Assert\Url(message: 'Geçerli bir URL girin.', requireTld: true)]
    public ?string $seoCanonicalUrl = null;

    public bool $seoNoindex = false;

    /**
     * Node::$categories (ManyToMany) için seçilen Category ID listesi.
     * PostType, EntityType(multiple: true, expanded: true) ile bunu
     * doğrudan Category entity koleksiyonuna map eder; controller
     * mapDtoToNode() içinde ilk seçilen kategoriyi Node::$category
     * (birincil kategori) olarak da atar.
     *
     * @var list<int>
     */
    public array $categoryIds = [];

    /**
     * Virgülle ayrılmış serbest metin etiket girişi (ör. "php, symfony, cms").
     * TagRepository::findOrCreateByNames() ile çözülür.
     */
    public ?string $tags = null;

    public ?int $featuredImageAssetId = null;

    /**
     * Modules\Blog\PostSubType sabitlerinden biri. Assert\Choice, sabit bir
     * dizi yerine PostSubType::choices() callback'ini kullanır — böylece
     * geçerli değer listesi tek bir kaynaktan (PostSubType) türetilir,
     * DTO ile PostSubType arasında kopya bir liste bakımı gerekmez.
     */
    #[Assert\Choice(callback: [PostSubType::class, 'values'], message: 'Geçersiz içerik türü.')]
    public string $postSubType = PostSubType::ARTICLE;

    /**
     * postSubType === PostSubType::PROJECT olduğunda anlamlıdır (bkz.
     * validatePostSubTypeFields()). Diğer türlerde form alanı JS ile
     * gizlenir ve gönderilen değer sessizce yok sayılır (mapDtoToNode()
     * sadece ilgili türün alanlarını Node::data['type_fields']'a yazar).
     */
    #[Assert\Length(max: 255, maxMessage: 'Depo URL\'si en fazla {{ limit }} karakter olabilir.')]
    #[Assert\Url(message: 'Geçerli bir URL girin.', requireTld: true)]
    public ?string $projectRepoUrl = null;

    #[Assert\Length(max: 255, maxMessage: 'Demo URL\'si en fazla {{ limit }} karakter olabilir.')]
    #[Assert\Url(message: 'Geçerli bir URL girin.', requireTld: true)]
    public ?string $projectDemoUrl = null;

    #[Assert\Length(max: 50, maxMessage: 'Versiyon en fazla {{ limit }} karakter olabilir.')]
    public ?string $softwareVersion = null;

    #[Assert\Length(max: 255, maxMessage: 'İndirme URL\'si en fazla {{ limit }} karakter olabilir.')]
    #[Assert\Url(message: 'Geçerli bir URL girin.', requireTld: true)]
    public ?string $softwareDownloadUrl = null;

    /**
     * "Not" türü için minimalist kod paylaşım alanı. HTML DEĞİL, düz metin
     * olarak taşınır — bkz. sınıf üstü doküman notu (XSS güvenliği).
     */
    public ?string $noteCodeSnippet = null;

    /**
     * Tür bazlı koşullu zorunluluk kontrolü: sabit #[Assert\NotBlank]
     * yerine bilinçli olarak burada, tek bir yerde toplanır — çünkü
     * "hangi alan zorunlu" kararı postSubType'ın çalışma zamanı değerine
     * bağlıdır ve statik attribute'larla ifade edilemez. Symfony'nin
     * GroupSequenceProviderInterface'i burada FAZLA soyutlama getirirdi
     * (dinamik grup ataması gerektirir); tek metotluk bir #[Assert\Callback]
     * hem yeterli hem de PostAdminController'daki mevcut düz-form
     * felsefesiyle tutarlıdır.
     */
    public function validatePostSubTypeFields(ExecutionContextInterface $context): void
    {
        match ($this->postSubType) {
            PostSubType::PROJECT => $this->validateProjectFields($context),
            PostSubType::SOFTWARE => $this->validateSoftwareFields($context),
            PostSubType::NOTE => $this->validateNoteFields($context),
            default => null,
        };
    }

    private function validateProjectFields(ExecutionContextInterface $context): void
    {
        if (trim((string) $this->projectRepoUrl) === '') {
            $context->buildViolation('Proje türü için depo (GitHub) URL\'si zorunludur.')
                ->atPath('projectRepoUrl')
                ->addViolation();
        }

        if (trim((string) $this->projectDemoUrl) === '') {
            $context->buildViolation('Proje türü için demo URL\'si zorunludur.')
                ->atPath('projectDemoUrl')
                ->addViolation();
        }
    }

    private function validateSoftwareFields(ExecutionContextInterface $context): void
    {
        if (trim((string) $this->softwareVersion) === '') {
            $context->buildViolation('Yazılım türü için versiyon bilgisi zorunludur.')
                ->atPath('softwareVersion')
                ->addViolation();
        }
    }

    private function validateNoteFields(ExecutionContextInterface $context): void
    {
        if (trim((string) $this->noteCodeSnippet) === '') {
            $context->buildViolation('Not türü için kod paylaşım alanı zorunludur.')
                ->atPath('noteCodeSnippet')
                ->addViolation();
        }
    }
}
