<?php

declare(strict_types=1);

namespace Modules\Blog\Controller\Admin;

use App\Core\Annotation\CpAdminMenu;
use App\Core\Content\RichTextSanitizer;
use App\Core\Content\SlugGenerator;
use App\Core\Pagination\Paginator;
use App\Core\Security\QueryScopeApplier;
use App\Entity\Node;
use App\Entity\User;
use App\Repository\AssetRepository;
use App\Repository\CategoryRepository;
use App\Repository\LocaleRepository;
use App\Repository\NodeRepository;
use App\Repository\TagRepository;
use Doctrine\ORM\EntityManagerInterface;
use Modules\Blog\Form\DTO\PostFormModel;
use Modules\Blog\Form\PostType;
use Modules\Blog\PostSubType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Studio'nun blog/post CRUD ekranları. Node::$type = 'post' olan içerikler
 * için özelleşmiş bir yönetim ekranıdır — Manifesto Law 3.1 gereği içerik
 * hâlâ tek bir Node tablosunda yaşar, bu controller sadece "post" tipine
 * bir bakış açısı (görünüm) sunar.
 *
 * Blog modülüne aittir (Modules\Blog): modül devre dışı bırakılırsa bu
 * controller ve route'ları hiç yüklenmez, Studio menüsünden de kaybolur
 * (bkz. SafeModuleRouteLoader, AdminMenuRuntime).
 *
 * Yetkilendirme her zaman CPaliusVoter üzerinden dinamik capability ile
 * yapılır (ROLE_* YASAK, bkz. Manifesto Law 4 ve CPaliusVoter docblock'u):
 *   - Listeleme: kaba "own veya any" kapı kontrolü + QueryScopeApplier'ın
 *     SQL seviyesinde satır bazlı daraltması (bkz. index() içindeki not)
 *   - Oluşturma: node.post.create
 *   - Düzenleme/Silme: assertOwnOrAny() — önce ".any", olmazsa subject
 *     (Node) ile ".own" (CPaliusVoter + OwnableInterface)
 *
 * create()/edit(): Ham Request::request->get() okuma tamamen kaldırıldı;
 * form katmanı artık Modules\Blog\Form\PostType (PostFormModel DTO'suna
 * map edilir) üzerinden akar. Node'a yazım tek, denetlenebilir bir
 * yardımcı metotta toplanır: mapDtoToNode().
 */
#[Route('/admin/posts', name: 'admin_posts_')]
final class PostAdminController extends AbstractController
{
    private const NODE_TYPE = 'post';
    private const DEFAULT_LOCALE = 'tr';
    private const ADMIN_PER_PAGE = 20;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly NodeRepository $nodeRepository,
        private readonly QueryScopeApplier $queryScopeApplier,
        private readonly SlugGenerator $slugGenerator,
        private readonly CategoryRepository $categoryRepository,
        private readonly TagRepository $tagRepository,
        private readonly RichTextSanitizer $richTextSanitizer,
        private readonly AssetRepository $assetRepository,
        private readonly Paginator $paginator,
        private readonly LocaleRepository $localeRepository,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * Listeleme: QueryScopeApplier, "node.post.view.any" yetkisi olmayan
     * (yalnızca ".own" yetkisine sahip) bir kullanıcının sorgusuna SQL
     * seviyesinde "AND author = :me" kısıtı ekler — Manifesto Law 6.2
     * (Voter to SQL): 100 satırlık bir listede her satır için ayrı ayrı
     * CPaliusVoter::vote() çağırmak yerine, kısıt sorgu ÇALIŞMADAN ÖNCE
     * WHERE koşuluna gömülür.
     */
    #[Route('', name: 'index', methods: ['GET'])]
    #[CpAdminMenu(label: 'Blog Yazıları', icon: 'heroicons:document-text', panel: 'studio', priority: 20, capability: 'node.post.view.own|node.post.view.any', group: 'İçerik')]
    public function index(Request $request): Response
    {
        // Kapı kontrolü kasıtlı olarak KABADIR (own/any ayrımı yapmaz):
        // #[IsGranted('...own', subject: null)] burada YANLIŞ olurdu, çünkü
        // CPaliusVoter subject'siz bir ".own" kontrolünü OwnableInterface
        // sağlayamadığı için her zaman reddeder (fail-safe) — "own" yetkili
        // bir kullanıcı hiç listeye giremezdi. İnce taneli own/any ayrımı
        // zaten satır bazında QueryScopeApplier::apply() tarafından SQL
        // seviyesinde uygulanıyor (Manifesto Law 6.2); burada sadece
        // kullanıcının ikisinden BİRİNE sahip olduğunu doğruluyoruz.
        if (!$this->isGranted('node.post.view.own') && !$this->isGranted('node.post.view.any')) {
            throw $this->createAccessDeniedException($this->translator->trans('blog.posts.error.view_denied'));
        }

        $qb = $this->nodeRepository->createQueryBuilder('n')
            ->andWhere('n.type = :type')
            ->andWhere('n.deletedAt IS NULL')
            ->setParameter('type', self::NODE_TYPE)
            ->orderBy('n.updatedAt', 'DESC');

        $this->queryScopeApplier->apply($qb, 'n', 'node.post.view', 'author');

        $result = $this->paginator->paginate($qb, $request->query->getInt('page', 1), self::ADMIN_PER_PAGE);

        return $this->render('@BlogModule/admin/posts/index.html.twig', [
            'posts' => $result,
        ]);
    }

    /**
     * ?translation_group={uuid}&locale={code} query param'ları geldiyse
     * (dil sekmelerinden "henüz çevrilmedi" rozetine tıklanmışsa), yeni
     * Node bu koda ve mevcut çeviri grubuna joinTranslationGroup() ile
     * bağlanır. Aksi halde mevcut davranış (translationGroupId = null)
     * korunur — Node çekirdek olarak grupsuz içeriği destekler.
     */
    #[Route('/create', name: 'create', methods: ['GET', 'POST'])]
    public function create(Request $request): Response
    {
        $this->denyAccessUnlessGranted('node.post.create', null, $this->translator->trans('blog.posts.error.create_denied'));

        $translationGroupId = $this->parseTranslationGroupId($request->query->get('translation_group'));
        $targetLocale = trim((string) $request->query->get('locale')) ?: self::DEFAULT_LOCALE;

        $dto = new PostFormModel();
        $form = $this->createPostForm($dto);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $node = new Node($dto->title, $this->resolveSlugForCreate($dto, $targetLocale), self::NODE_TYPE, $targetLocale);

            if ($translationGroupId !== null) {
                $node->joinTranslationGroup($translationGroupId);
            }

            $user = $this->getUser();
            if ($user instanceof User) {
                $node->setAuthor($user);
            }

            $this->mapDtoToNode($dto, $node);

            $this->entityManager->persist($node);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('blog.posts.flash.created', ['title' => $node->getTitle()]));

            return $this->redirectToRoute('admin_posts_index');
        }

        return $this->render('@BlogModule/admin/posts/form.html.twig', [
            'post' => null,
            'form' => $form,
            'featuredImageUrl' => $this->resolveAssetUrl($dto->featuredImageAssetId),
            'activeLocales' => $this->localeRepository->findActive(),
            'currentLocale' => $targetLocale,
            'translations' => [],
            'translationGroupId' => $translationGroupId,
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(int $id, Request $request): Response
    {
        $node = $this->findPostOrFail($id);
        $this->assertOwnOrAny('node.post.edit', $node, $this->translator->trans('blog.posts.error.edit_denied'));

        $dto = $this->buildDtoFromNode($node);
        $form = $this->createPostForm($dto);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $submittedSlug = trim((string) $dto->slug);
            $slug = $submittedSlug !== '' && $submittedSlug !== $node->getSlug()
                ? $this->slugGenerator->generate($submittedSlug, $node->getLocale(), $node->getId())
                : $node->getSlug();

            $node->setTitle($dto->title);
            $node->setSlug($slug);

            $this->mapDtoToNode($dto, $node);

            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('blog.posts.flash.updated', ['title' => $node->getTitle()]));

            return $this->redirectToRoute('admin_posts_index');
        }

        return $this->render('@BlogModule/admin/posts/form.html.twig', [
            'post' => $node,
            'form' => $form,
            'featuredImageUrl' => $this->resolveAssetUrl($dto->featuredImageAssetId),
            'activeLocales' => $this->localeRepository->findActive(),
            'currentLocale' => $node->getLocale(),
            'translations' => $this->buildTranslationsMap($node),
            'translationGroupId' => $node->getTranslationGroupId(),
        ]);
    }

    /**
     * Bu Node henüz hiçbir çeviri grubuna dahil değilse (translationGroupId
     * null), onu yeni ve boş bir gruba atar ve edit ekranına geri döner —
     * bu sayede form.html.twig'deki dil rozetleri artık diğer dillerde
     * "henüz çevrilmedi, oluştur" linkini translation_group query param'ı
     * ile üretebilir (bkz. Node::assignToNewTranslationGroup()).
     */
    #[Route('/{id}/assign-translation-group', name: 'assign_translation_group', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function assignTranslationGroup(int $id, Request $request): Response
    {
        $node = $this->findPostOrFail($id);
        $this->assertOwnOrAny('node.post.edit', $node, $this->translator->trans('blog.posts.error.edit_denied'));
        $this->assertValidCsrf($request, 'admin_post_form');

        if ($node->getTranslationGroupId() === null) {
            $node->assignToNewTranslationGroup();
            $this->entityManager->flush();
        }

        return $this->redirectToRoute('admin_posts_edit', ['id' => $node->getId()]);
    }

    /**
     * PostType formunu, mevcut yayınlanmış kategorilerin (DEFAULT_LOCALE)
     * choice listesiyle inşa eder — EntityType'ın kendi query_builder'ı
     * yerine burada tek merkezden geçirilir, çünkü CategoryRepository
     * çağrısı zaten controller seviyesinde locale'e duyarlı yapılıyordu
     * (eski kod ile davranış paritesini korur).
     */
    private function createPostForm(PostFormModel $dto): FormInterface
    {
        $categories = $this->categoryRepository->findBy(['locale' => self::DEFAULT_LOCALE]);
        $categoryChoices = [];
        foreach ($categories as $category) {
            $categoryChoices[$category->getName()] = $category;
        }

        return $this->createForm(PostType::class, $dto, [
            'category_choices' => $categoryChoices,
        ]);
    }

    private function resolveSlugForCreate(PostFormModel $dto, string $locale): string
    {
        $submittedSlug = trim((string) $dto->slug);

        return $submittedSlug !== ''
            ? $this->slugGenerator->generate($submittedSlug, $locale)
            : $this->slugGenerator->generate($dto->title, $locale);
    }

    /**
     * Node'un translationGroupId'si varsa, aynı gruptaki diğer dillerdeki
     * Node'ları locale => Node map'ine çevirir — form.html.twig'deki dil
     * rozetlerinin "bu dilde zaten bir çeviri var mı" sorusuna cevap
     * vermesi için (bkz. NodeRepository::findTranslations()).
     *
     * @return array<string, Node>
     */
    private function buildTranslationsMap(Node $node): array
    {
        $groupId = $node->getTranslationGroupId();
        if ($groupId === null) {
            return [];
        }

        $map = [];
        foreach ($this->nodeRepository->findTranslations($groupId) as $translation) {
            $map[$translation->getLocale()] = $translation;
        }

        return $map;
    }

    private function parseTranslationGroupId(mixed $value): ?Uuid
    {
        $value = trim((string) $value);
        if ($value === '' || !Uuid::isValid($value)) {
            return null;
        }

        return Uuid::fromString($value);
    }

    /**
     * PostFormModel DTO'sundaki doğrulanmış veriyi Node entity'sine ve onun
     * dinamik JSON data alanına güvenli biçimde aktarır; kategori/etiket
     * many-to-many ilişkilerini senkronlar. create()/edit() arasında
     * paylaşılan TEK yazma yolu — Manifesto Law 5.3 (mass assignment
     * allowlist + XSS sanitizasyonu) burada uygulanır:
     * - 'body' RichTextSanitizer'dan GEÇMEDEN asla Node::data'ya yazılmaz.
     * - Yazılan anahtarlar DTO'nun tanımladığı sabit bir allowlisttir,
     *   request'ten keyfi bir alan asla doğrudan JSON'a sızamaz.
     */
    private function mapDtoToNode(PostFormModel $dto, Node $node): void
    {
        $this->applyPublicationSchedule($dto, $node);

        $node->setDataValue('excerpt', trim((string) $dto->excerpt));
        $node->setDataValue('body', $this->richTextSanitizer->sanitize($dto->body));
        // is_featured, QueryableFieldsRegistry'de 'post' tipi için
        // indekslenmesi tanımlı bir alan (TYPE_INT) — persist sonrası
        // NodeIndexListener::postPersist bunu otomatik olarak
        // NodeFieldIndex tablosuna "düz" bir satır olarak senkronlar
        // (bkz. Manifesto Law 6.3, NodeIndexListener docblock'u).
        $node->setDataValue('is_featured', $dto->isFeatured ? 1 : 0);

        // post_sub_type de aynı şekilde QueryableFieldsRegistry'de
        // TYPE_STRING olarak tanımlı ve otomatik indekslenir.
        $node->setDataValue('post_sub_type', $dto->postSubType);
        $node->setDataValue('type_fields', $this->buildTypeFields($dto));

        $this->syncTaxonomy($dto, $node);

        $node->setDataValue('featured_image_asset_id', $dto->featuredImageAssetId);
        $node->setDataValue('seo', [
            'meta_description' => trim((string) $dto->seoMetaDescription) ?: null,
            'focus_keyword' => trim((string) $dto->seoFocusKeyword) ?: null,
            'og_image_asset_id' => null,
            'canonical_url' => trim((string) $dto->seoCanonicalUrl) ?: null,
            'schema_type' => 'BlogPosting',
            'noindex' => $dto->seoNoindex,
        ]);
    }

    /**
     * "Akıllı Zamanlama Motoru": Studio'da seçilen ham $dto->status,
     * $dto->publishedAt ile çapraz kontrol edilmeden Node'a asla
     * doğrudan yazılmaz. Üç kural, bu sırayla ve BİRBİRİNİ EZMEDEN
     * uygulanır:
     *
     *   1. status === draft  → tarih ne olursa olsun Node taslak kalır.
     *      (Bir taslağın "gelecekte yayınlanacak" bir publishedAt'i olması
     *      zararsızdır — durum draft olduğu sürece hiçbir zamanlama
     *      komutu/ön yüz sorgusu bu Node'u yayında saymaz.)
     *   2. status === published VE publishedAt gelecekte bir zaman →
     *      Node otomatik olarak "scheduled" durumuna düşürülür. Kullanıcı
     *      "Yayınlandı"yı seçmiş olsa bile, ileri tarihli bir Node asla
     *      published olarak persist edilmez — aksi halde ön yüz
     *      (createPublishedByTypeAndLocaleQueryBuilder vb.) status='published'
     *      filtresine güvendiği için içerik "sızıp" zamanından ÖNCE
     *      yayında görünürdü.
     *   3. status === published VE publishedAt geçmiş/şimdi (veya boş,
     *      "şimdi" varsayılır) → gerçek anlamda published, Node::publish()
     *      ile publishedAt damgalanır.
     *   4. status === scheduled → publishedAt her zaman saklanır (boşsa
     *      "şimdi" ile doldurulur, "blog.publish_scheduled" cron görevi
     *      zaten bir sonraki çalışmasında bunu hemen yayına alır —
     *      kullanıcı "Zamanlandı" seçip tarihi boş bırakırsa sistem donmaz).
     *
     * Bu metod "blog.publish_scheduled" cron görevinin (bkz.
     * Modules\Blog\Cron\PublishScheduledPostsTask) ve NodeRepository'nin
     * published/scheduled sorgularının varsayımlarıyla (status kolonu HER
     * ZAMAN gerçek yayın durumunu yansıtır) tutarlılığı garanti eder.
     */
    private function applyPublicationSchedule(PostFormModel $dto, Node $node): void
    {
        $requestedAt = $dto->publishedAt;

        if ($dto->status === Node::STATUS_DRAFT) {
            $node->setStatus(Node::STATUS_DRAFT);

            return;
        }

        if ($dto->status === Node::STATUS_SCHEDULED) {
            $node->setStatus(Node::STATUS_SCHEDULED);
            $node->setDataValue('scheduled_for', ($requestedAt ?? new \DateTimeImmutable())->format(DATE_ATOM));

            return;
        }

        // $dto->status === Node::STATUS_PUBLISHED
        $now = new \DateTimeImmutable();

        if ($requestedAt !== null && $requestedAt > $now) {
            // Kullanıcı "Yayınlandı" dedi ama tarih gelecekte: sessizce
            // "scheduled"a düşürülür — Node::publish() ÇAĞRILMAZ, çünkü
            // o metod status'ü doğrudan 'published' yapar.
            $node->setStatus(Node::STATUS_SCHEDULED);
            $node->setDataValue('scheduled_for', $requestedAt->format(DATE_ATOM));

            return;
        }

        $node->publish($requestedAt ?? $now);
    }

    /**
     * postSubType'a göre YALNIZCA o türe ait alanları içeren düz bir
     * dizi üretir — diğer türlerin form alanları (JS ile gizli olsa da
     * request'te gelmiş olabilir) sessizce yok sayılır. Bu, Manifesto
     * Law 5.3 (mass assignment allowlist) ilkesinin post_sub_type'a özel
     * uygulamasıdır: Node::data['type_fields'] asla "proje" alanlarıyla
     * "yazılım" alanlarının karışık halini içermez.
     *
     * @return array<string, string|null>
     */
    private function buildTypeFields(PostFormModel $dto): array
    {
        return match ($dto->postSubType) {
            PostSubType::PROJECT => [
                'repo_url' => trim((string) $dto->projectRepoUrl) ?: null,
                'demo_url' => trim((string) $dto->projectDemoUrl) ?: null,
            ],
            PostSubType::SOFTWARE => [
                'version' => trim((string) $dto->softwareVersion) ?: null,
                'download_url' => trim((string) $dto->softwareDownloadUrl) ?: null,
            ],
            PostSubType::NOTE => [
                // Bilinçli olarak RichTextSanitizer'dan GEÇMEZ: bu alan
                // hiçbir zaman HTML olarak yorumlanmayacak, düz metin
                // olarak <pre><code> içinde auto-escape ile basılacak
                // (bkz. PostFormModel sınıf üstü doküman notu).
                'code_snippet' => $dto->noteCodeSnippet !== null ? trim($dto->noteCodeSnippet) : null,
            ],
            default => [],
        };
    }

    /**
     * Node::$categories (çoklu kategori) ve Node::$category (birincil
     * kategori — breadcrumb/URL için) ilişkilerini DTO'daki categoryIds ile,
     * Node::$tags ilişkisini de serbest metin 'tags' girişiyle senkronlar.
     */
    private function syncTaxonomy(PostFormModel $dto, Node $node): void
    {
        foreach ($node->getCategories()->toArray() as $existing) {
            $node->removeCategory($existing);
        }

        if ($dto->categoryIds !== []) {
            foreach ($this->categoryRepository->findBy(['id' => $dto->categoryIds]) as $category) {
                $node->addCategory($category);
            }
            $node->setCategory($this->categoryRepository->find($dto->categoryIds[0]));
        } else {
            $node->setCategory(null);
        }

        $tagNames = array_values(array_filter(array_map('trim', explode(',', (string) $dto->tags))));
        foreach ($node->getTags()->toArray() as $existing) {
            $node->removeTag($existing);
        }
        foreach ($this->tagRepository->findOrCreateByNames($tagNames, $node->getLocale()) as $tag) {
            $node->addTag($tag);
        }
    }

    /**
     * Var olan bir Node'dan (edit ekranının GET aşaması) PostFormModel
     * DTO'sunu doldurur — formun POST_SET_DATA'sı burada Symfony Form
     * bileşeninin kendi property-access mekanizmasıyla değil, açıkça
     * elle yapılır çünkü kaynak Node::data (JSON) iken hedef DTO düz
     * property'lerdir; iki model arasında otomatik map edilebilecek
     * birebir bir şema yoktur.
     */
    private function buildDtoFromNode(Node $node): PostFormModel
    {
        $dto = new PostFormModel();
        $dto->title = $node->getTitle();
        $dto->slug = $node->getSlug();
        $dto->excerpt = (string) $node->getDataValue('excerpt', '');
        $dto->body = (string) $node->getDataValue('body', '');
        $dto->isFeatured = (bool) $node->getDataValue('is_featured', false);
        $dto->status = $node->getStatus();
        $dto->publishedAt = $node->getPublishedAt() ?? $this->resolveScheduledForAsDate($node);
        $dto->categoryIds = array_map(static fn ($c) => $c->getId(), $node->getCategories()->toArray());
        $dto->tags = implode(', ', array_map(static fn ($t) => $t->getName(), $node->getTags()->toArray()));

        $featuredAssetId = $node->getDataValue('featured_image_asset_id');
        $dto->featuredImageAssetId = is_numeric($featuredAssetId) ? (int) $featuredAssetId : null;

        $seo = $node->getDataValue('seo', []);
        $dto->seoMetaDescription = is_array($seo) ? (string) ($seo['meta_description'] ?? '') : '';
        $dto->seoFocusKeyword = is_array($seo) ? (string) ($seo['focus_keyword'] ?? '') : '';
        $dto->seoCanonicalUrl = is_array($seo) ? (string) ($seo['canonical_url'] ?? '') : '';
        $dto->seoNoindex = is_array($seo) && (bool) ($seo['noindex'] ?? false);

        $subType = (string) $node->getDataValue('post_sub_type', PostSubType::ARTICLE);
        $dto->postSubType = PostSubType::isValid($subType) ? $subType : PostSubType::ARTICLE;

        $typeFields = $node->getDataValue('type_fields', []);
        if (is_array($typeFields)) {
            $dto->projectRepoUrl = (string) ($typeFields['repo_url'] ?? '') ?: null;
            $dto->projectDemoUrl = (string) ($typeFields['demo_url'] ?? '') ?: null;
            $dto->softwareVersion = (string) ($typeFields['version'] ?? '') ?: null;
            $dto->softwareDownloadUrl = (string) ($typeFields['download_url'] ?? '') ?: null;
            $dto->noteCodeSnippet = (string) ($typeFields['code_snippet'] ?? '') ?: null;
        }

        return $dto;
    }

    /**
     * "Zamanlandı" durumundaki bir Node'un Node::$publishedAt'i henüz
     * NULL'dur ("blog.publish_scheduled" cron görevi onu gerçek yayına
     * aldığında damgalanır, bkz. PublishScheduledPostsTask) — bu yüzden edit ekranının
     * "Yayınlanma Tarihi" alanı boş görünmesin diye applyPublicationSchedule()
     * tarafından yazılan Node::data['scheduled_for'] buradan geri okunur.
     */
    private function resolveScheduledForAsDate(Node $node): ?\DateTimeImmutable
    {
        $scheduledFor = $node->getDataValue('scheduled_for');
        if (!is_string($scheduledFor) || $scheduledFor === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($scheduledFor);
        } catch (\Exception) {
            return null;
        }
    }

    private function resolveAssetUrl(?int $assetId): ?string
    {
        if ($assetId === null) {
            return null;
        }

        $asset = $this->assetRepository->find($assetId);

        return $asset?->getStorageKey() !== null ? '/uploads/'.$asset->getStorageKey() : null;
    }

    #[Route('/{id}/publish', name: 'publish', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function publish(int $id, Request $request): Response
    {
        $node = $this->findPostOrFail($id);

        $this->denyAccessUnlessGranted('node.post.publish', null, $this->translator->trans('blog.posts.error.publish_denied'));
        $this->assertValidCsrf($request, 'admin_post_form');

        $node->publish();
        $this->entityManager->flush();

        $this->addFlash('success', $this->translator->trans('blog.posts.flash.published', ['title' => $node->getTitle()]));

        return $this->redirectToRoute('admin_posts_index');
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(int $id, Request $request): Response
    {
        $node = $this->findPostOrFail($id);
        $this->assertOwnOrAny('node.post.delete', $node, $this->translator->trans('blog.posts.error.delete_denied'));
        $this->assertValidCsrf($request, 'admin_post_form');

        // Manifesto'nun #[SoftDeletable] davranışı: gerçek DELETE yerine
        // deletedAt damgalanır (Çöp Kutusu desteği, bkz. SoftDeletableTrait).
        $node->softDelete();
        $this->entityManager->flush();

        $this->addFlash('success', $this->translator->trans('blog.posts.flash.trashed', ['title' => $node->getTitle()]));

        return $this->redirectToRoute('admin_posts_index');
    }

    private function findPostOrFail(int $id): Node
    {
        $node = $this->nodeRepository->find($id);

        if (!$node instanceof Node || $node->getType() !== self::NODE_TYPE || $node->getDeletedAt() !== null) {
            throw new NotFoundHttpException($this->translator->trans('blog.posts.error.not_found'));
        }

        return $node;
    }

    private function assertValidCsrf(Request $request, string $tokenId): void
    {
        $submitted = (string) $request->request->get('_token');
        if (!$this->isCsrfTokenValid($tokenId, $submitted)) {
            throw $this->createAccessDeniedException($this->translator->trans('aacp.common.error.invalid_csrf'));
        }
    }

    /**
     * "<base>.own"/"<base>.any" ikilisini doğru sırayla dener: ÖNCE ".any"
     * (subject'siz — sahiplik kontrolüne hiç girmez, herkesin içeriğine
     * izin verir), o başarısız olursa ".own" (subject İLE — CPaliusVoter
     * burada OwnableInterface üzerinden gerçek sahiplik eşleşmesi arar).
     *
     * Sıra ÖNEMLİDİR: sadece ".own" denenseydi, "*.any" yetkisine sahip
     * (ör. admin) ama subject'in SAHİBİ OLMAYAN bir kullanıcı, subject
     * sahiplik eşleşmesi tutmadığı için yanlışlıkla reddedilirdi — "*"
     * joker rolü zaten hem ".own" hem ".any" yetkisini genişlettiği için
     * bu hata sessizce (fail-safe reddiyle) ortaya çıkardı.
     */
    private function assertOwnOrAny(string $capabilityBase, Node $subject, string $message): void
    {
        if ($this->isGranted($capabilityBase.'.any')) {
            return;
        }

        $this->denyAccessUnlessGranted($capabilityBase.'.own', $subject, $message);
    }
}
