<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Core\Annotation\CpAdminMenu;
use App\Core\Field\FieldDefinitionRegistry;
use App\Core\Field\FieldValuePersister;
use App\Core\Field\Form\FieldableFormBuilder;
use App\Core\Localization\LocaleProvider;
use App\Core\Localization\TranslationGroupResolver;
use App\Core\Taxonomy\Entity\Term;
use App\Core\Taxonomy\Entity\Vocabulary;
use App\Core\Taxonomy\Repository\TermRepository;
use App\Core\Taxonomy\Repository\VocabularyRepository;
use App\Core\Taxonomy\TaxonomyCapabilityRegistrar;
use App\Core\Taxonomy\VocabularyRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Vocabulary + Term admin (T1.3). Vocabularies stay plain-request POST-redirect-GET;
 * term custom fields use the Field API sub-form (same pattern as AACPUserController).
 * Vocab CRUD requires global taxonomy.manage; term CRUD accepts that or taxonomy.{vid}.manage.
 */
#[Route('/aacp/taxonomy', name: 'aacp_taxonomy_')]
final class AACPTaxonomyController extends AbstractController
{
    private const MACHINE_NAME_REQUIREMENT = '[a-z][a-z0-9_]{0,62}';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly VocabularyRepository $vocabularyRepository,
        private readonly TermRepository $termRepository,
        private readonly VocabularyRegistry $vocabularyRegistry,
        private readonly TaxonomyCapabilityRegistrar $capabilityRegistrar,
        private readonly LocaleProvider $localeProvider,
        private readonly TranslationGroupResolver $translationGroupResolver,
        private readonly TranslatorInterface $translator,
        private readonly FieldableFormBuilder $fieldableFormBuilder,
        private readonly FieldDefinitionRegistry $fieldDefinitions,
        private readonly FieldValuePersister $fieldValuePersister,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    #[IsGranted('taxonomy.manage')]
    #[CpAdminMenu(label: 'aacp.menu.taxonomy', icon: 'heroicons:tag', panel: 'aacp', priority: 21, capability: 'taxonomy.manage', parent: 'aacp_tools')]
    public function index(): Response
    {
        $vocabularies = $this->vocabularyRepository->findAllOrdered();
        $counts = [];
        foreach ($vocabularies as $vocabulary) {
            $counts[(int) $vocabulary->getId()] = $this->termRepository->countByVocabulary($vocabulary);
        }

        return $this->render('aacp/taxonomy/index.html.twig', [
            'vocabularies' => $vocabularies,
            'counts' => $counts,
        ]);
    }

    #[Route('/new', name: 'vocabulary_new', methods: ['GET', 'POST'])]
    #[IsGranted('taxonomy.manage')]
    public function newVocabulary(Request $request): Response
    {
        return $this->handleVocabularyForm($request, null);
    }

    #[Route('/{machineName}/edit', name: 'vocabulary_edit', methods: ['GET', 'POST'], requirements: ['machineName' => self::MACHINE_NAME_REQUIREMENT])]
    #[IsGranted('taxonomy.manage')]
    public function editVocabulary(string $machineName, Request $request): Response
    {
        return $this->handleVocabularyForm($request, $this->findVocabularyOrFail($machineName));
    }

    #[Route('/{machineName}/delete', name: 'vocabulary_delete', methods: ['POST'], requirements: ['machineName' => self::MACHINE_NAME_REQUIREMENT])]
    #[IsGranted('taxonomy.manage')]
    public function deleteVocabulary(string $machineName, Request $request): RedirectResponse
    {
        $vocabulary = $this->findVocabularyOrFail($machineName);
        $this->assertCsrf($request, 'aacp_taxonomy_vocabulary');

        if ($this->termRepository->countByVocabulary($vocabulary) > 0) {
            $this->addFlash('error', $this->translator->trans('aacp.taxonomy.error.vocabulary_not_empty'));

            return $this->redirectToRoute('aacp_taxonomy_index');
        }

        $this->entityManager->remove($vocabulary);
        $this->entityManager->flush();
        $this->vocabularyRegistry->invalidate();
        $this->capabilityRegistrar->sync();

        $this->addFlash('success', $this->translator->trans('aacp.taxonomy.flash.vocabulary_deleted', ['label' => $vocabulary->getLabel()]));

        return $this->redirectToRoute('aacp_taxonomy_index');
    }

    #[Route('/{machineName}', name: 'terms', methods: ['GET'], requirements: ['machineName' => self::MACHINE_NAME_REQUIREMENT])]
    public function terms(string $machineName, Request $request): Response
    {
        $vocabulary = $this->findVocabularyOrFail($machineName);
        $this->assertCanManageTerms($vocabulary);
        $locale = $this->resolveLocale($request->query->get('locale'));

        return $this->render('aacp/taxonomy/terms.html.twig', [
            'vocabulary' => $vocabulary,
            'locale' => $locale,
            'locales' => $this->localeProvider->getLocales(),
            'termTree' => $this->buildTree($this->termRepository->findByVocabulary($vocabulary, $locale)),
        ]);
    }

    #[Route('/{machineName}/terms/new', name: 'term_new', methods: ['GET', 'POST'], requirements: ['machineName' => self::MACHINE_NAME_REQUIREMENT])]
    public function newTerm(string $machineName, Request $request): Response
    {
        $vocabulary = $this->findVocabularyOrFail($machineName);
        $this->assertCanManageTerms($vocabulary);
        $bag = $request->isMethod('POST') ? $request->request : $request->query;
        $locale = $this->resolveLocale($bag->get('locale'));
        $source = $this->findTranslationSource($vocabulary, $bag->get('translation_of'));

        if ($request->isMethod('POST')) {
            return $this->saveTerm($request, $vocabulary, null, $locale, $source);
        }

        return $this->renderTermForm($vocabulary, null, $locale, $source, [
            'name' => $source?->getName() ?? '',
            'slug' => '',
            'parentId' => null,
            'weight' => 0,
        ]);
    }

    #[Route('/{machineName}/terms/{id}/edit', name: 'term_edit', methods: ['GET', 'POST'], requirements: ['machineName' => self::MACHINE_NAME_REQUIREMENT, 'id' => '\d+'])]
    public function editTerm(string $machineName, int $id, Request $request): Response
    {
        $vocabulary = $this->findVocabularyOrFail($machineName);
        $this->assertCanManageTerms($vocabulary);
        $term = $this->findTermOrFail($vocabulary, $id);

        if ($request->isMethod('POST')) {
            return $this->saveTerm($request, $vocabulary, $term, $term->getLocale(), null);
        }

        return $this->renderTermForm($vocabulary, $term, $term->getLocale(), null, [
            'name' => $term->getName(),
            'slug' => $term->getSlug(),
            'parentId' => $term->getParent()?->getId(),
            'weight' => $term->getWeight(),
        ]);
    }

    #[Route('/{machineName}/terms/{id}/delete', name: 'term_delete', methods: ['POST'], requirements: ['machineName' => self::MACHINE_NAME_REQUIREMENT, 'id' => '\d+'])]
    public function deleteTerm(string $machineName, int $id, Request $request): RedirectResponse
    {
        $vocabulary = $this->findVocabularyOrFail($machineName);
        $this->assertCanManageTerms($vocabulary);
        $term = $this->findTermOrFail($vocabulary, $id);
        $this->assertCsrf($request, 'aacp_taxonomy_term');

        $locale = $term->getLocale();
        $this->entityManager->remove($term);
        $this->entityManager->flush();

        $this->addFlash('success', $this->translator->trans('aacp.taxonomy.flash.term_deleted', ['name' => $term->getName()]));

        return $this->redirectToRoute('aacp_taxonomy_terms', ['machineName' => $machineName, 'locale' => $locale]);
    }

    // --- Vocabulary form ---------------------------------------------------

    private function handleVocabularyForm(Request $request, ?Vocabulary $vocabulary): Response
    {
        if ($request->isMethod('POST')) {
            $this->assertCsrf($request, 'aacp_taxonomy_vocabulary');

            $label = trim((string) $request->request->get('label'));
            $description = trim((string) $request->request->get('description'));
            $hierarchical = $request->request->get('hierarchical') !== null;
            $weight = (int) $request->request->get('weight');

            if ($label === '') {
                $this->addFlash('error', $this->translator->trans('aacp.taxonomy.error.label_required'));

                return $this->renderVocabularyForm($vocabulary, $request);
            }

            if ($vocabulary === null) {
                $machineName = $this->buildMachineName((string) $request->request->get('machine_name'), $label);
                if ($machineName === '' || $this->vocabularyRepository->findOneByMachineName($machineName) !== null) {
                    $this->addFlash('error', $this->translator->trans('aacp.taxonomy.error.machine_name_taken'));

                    return $this->renderVocabularyForm(null, $request);
                }

                $vocabulary = new Vocabulary($machineName, $label);
                $this->entityManager->persist($vocabulary);
            } else {
                $vocabulary->setLabel($label);
            }

            $vocabulary
                ->setDescription($description !== '' ? $description : null)
                ->setHierarchical($hierarchical)
                ->setWeight($weight);

            $this->entityManager->flush();
            $this->vocabularyRegistry->invalidate();
            $this->capabilityRegistrar->sync();

            $this->addFlash('success', $this->translator->trans('aacp.taxonomy.flash.vocabulary_saved', ['label' => $label]));

            return $this->redirectToRoute('aacp_taxonomy_index');
        }

        return $this->renderVocabularyForm($vocabulary, $request);
    }

    private function renderVocabularyForm(?Vocabulary $vocabulary, Request $request): Response
    {
        return $this->render('aacp/taxonomy/vocabulary_form.html.twig', [
            'vocabulary' => $vocabulary,
            'formValues' => [
                'machine_name' => $vocabulary?->getMachineName() ?? trim((string) $request->request->get('machine_name')),
                'label' => $vocabulary?->getLabel() ?? trim((string) $request->request->get('label')),
                'description' => $vocabulary?->getDescription() ?? trim((string) $request->request->get('description')),
                'hierarchical' => $vocabulary?->isHierarchical() ?? true,
                'weight' => $vocabulary?->getWeight() ?? 0,
            ],
        ]);
    }

    // --- Term form -----------------------------------------------------------

    private function saveTerm(Request $request, Vocabulary $vocabulary, ?Term $term, string $locale, ?Term $source): Response
    {
        $this->assertCsrf($request, 'aacp_taxonomy_term');

        $name = trim((string) $request->request->get('name'));
        $slugInput = trim((string) $request->request->get('slug'));
        $parentId = $this->intOrNull($request->request->get('parent_id'));
        $weight = (int) $request->request->get('weight');
        $formValues = [
            'name' => $name,
            'slug' => $slugInput,
            'parentId' => $parentId,
            'weight' => $weight,
        ];

        $fieldsForm = $this->createFieldsForm($vocabulary, $term, $locale);
        $fieldsForm->handleRequest($request);

        if ($name === '') {
            $this->addFlash('error', $this->translator->trans('aacp.taxonomy.error.name_required'));

            return $this->renderTermForm($vocabulary, $term, $locale, $source, $formValues, $fieldsForm);
        }

        $slug = $this->buildSlug($vocabulary, $slugInput !== '' ? $slugInput : $name, $locale, $term?->getId());
        $parent = $vocabulary->isHierarchical() ? $this->findParentInVocabulary($vocabulary, $parentId, $locale, $term) : null;

        if ($term === null) {
            $term = new Term($vocabulary, $name, $slug, $locale);
            $this->entityManager->persist($term);
        } else {
            $term->setName($name)->setSlug($slug);
        }

        $term->setParent($parent)->setWeight($weight);

        if ($source instanceof Term && $source->getLocale() !== $locale) {
            $this->translationGroupResolver->link($source, $term);
        }

        if ($fieldsForm->has('fields') && !$this->persistTermFields($term, $fieldsForm)) {
            return $this->renderTermForm($vocabulary, $term, $locale, $source, $formValues, $fieldsForm);
        }

        $this->entityManager->flush();

        $this->addFlash('success', $source instanceof Term
            ? $this->translator->trans('cp.translation_tabs.linked_flash', ['name' => $name, 'locale' => $locale])
            : $this->translator->trans('aacp.taxonomy.flash.term_saved', ['name' => $name]));

        return $this->redirectToRoute('aacp_taxonomy_terms', ['machineName' => $vocabulary->getMachineName(), 'locale' => $locale]);
    }

    /**
     * @param array{name: string, slug: string, parentId: ?int, weight: int} $formValues
     */
    private function renderTermForm(
        Vocabulary $vocabulary,
        ?Term $term,
        string $locale,
        ?Term $source,
        array $formValues,
        ?FormInterface $fieldsForm = null,
    ): Response {
        $parentOptions = array_values(array_filter(
            $this->termRepository->findByVocabulary($vocabulary, $locale),
            static fn (Term $candidate): bool => $term === null || $candidate->getId() !== $term->getId(),
        ));

        $fieldsForm ??= $this->createFieldsForm($vocabulary, $term, $locale);

        return $this->render('aacp/taxonomy/term_form.html.twig', [
            'vocabulary' => $vocabulary,
            'term' => $term,
            'locale' => $locale,
            'parentOptions' => $parentOptions,
            'formValues' => $formValues,
            'fieldsForm' => $fieldsForm,
            'sourceId' => $source?->getId(),
            'translationTabs' => $term instanceof Term
                ? $this->translationGroupResolver->tabsFor($term)
                : ($source instanceof Term ? $this->translationGroupResolver->tabsFor($source) : []),
        ]);
    }

    private function createFieldsForm(Vocabulary $vocabulary, ?Term $term, string $locale): FormInterface
    {
        $builder = $this->container->get('form.factory')->createNamedBuilder(
            '',
            FormType::class,
            null,
            ['csrf_protection' => false],
        );
        $this->fieldableFormBuilder->add($builder, $vocabulary->getMachineName(), $locale);
        $form = $builder->getForm();

        if ($form->has('fields')) {
            $form->get('fields')->setData($this->currentFieldValues($term, $vocabulary->getMachineName()));
        }

        return $form;
    }

    /**
     * @return array<string, mixed>
     */
    private function currentFieldValues(?Term $term, string $bundle): array
    {
        if ($term === null) {
            return [];
        }

        $data = $term->getFieldableData();
        $values = [];
        foreach ($this->fieldDefinitions->getFieldsForBundle($bundle) as $definition) {
            if (\array_key_exists($definition->getName(), $data)) {
                $values[$definition->getName()] = $data[$definition->getName()];
            }
        }

        return $values;
    }

    private function persistTermFields(Term $term, FormInterface $form): bool
    {
        if (!$form->has('fields')) {
            return true;
        }

        $submitted = $form->get('fields')->getData();
        $errors = $this->fieldValuePersister->persist($term, \is_array($submitted) ? $submitted : []);

        foreach ($errors as $fieldName => $violations) {
            if (!$form->get('fields')->has($fieldName)) {
                continue;
            }
            foreach ($violations as $violation) {
                $form->get('fields')->get($fieldName)->addError(new FormError($this->translator->trans($violation)));
            }
        }

        return $errors === [];
    }

    private function assertCanManageTerms(Vocabulary $vocabulary): void
    {
        if ($this->isGranted('taxonomy.manage')
            || $this->isGranted(TaxonomyCapabilityRegistrar::capabilityFor($vocabulary->getMachineName()))) {
            return;
        }

        throw $this->createAccessDeniedException();
    }

    // --- Helpers ---------------------------------------------------------

    /**
     * @param list<Term> $terms
     *
     * @return list<array{term: Term, children: mixed}>
     */
    private function buildTree(array $terms, ?int $parentId = null): array
    {
        $branch = [];
        foreach ($terms as $term) {
            $currentParentId = $term->getParent()?->getId();
            if ($currentParentId !== $parentId) {
                continue;
            }
            $branch[] = ['term' => $term, 'children' => $this->buildTree($terms, $term->getId())];
        }

        return $branch;
    }

    private function findParentInVocabulary(Vocabulary $vocabulary, ?int $parentId, string $locale, ?Term $editing): ?Term
    {
        if ($parentId === null || ($editing !== null && $parentId === $editing->getId())) {
            return null;
        }

        $parent = $this->termRepository->find($parentId);

        return $parent instanceof Term
            && $parent->getVocabulary()->getId() === $vocabulary->getId()
            && $parent->getLocale() === $locale
            ? $parent
            : null;
    }

    private function findTranslationSource(Vocabulary $vocabulary, mixed $rawId): ?Term
    {
        $id = $this->intOrNull($rawId);
        if ($id === null) {
            return null;
        }

        $term = $this->termRepository->find($id);

        return $term instanceof Term && $term->getVocabulary()->getId() === $vocabulary->getId() ? $term : null;
    }

    private function buildMachineName(string $submitted, string $label): string
    {
        $slugger = new AsciiSlugger();
        $base = strtolower(str_replace('-', '_', $slugger->slug($submitted !== '' ? $submitted : $label)->toString()));
        $base = preg_replace('/[^a-z0-9_]/', '', (string) $base) ?? '';
        if ($base === '' || preg_match(Vocabulary::MACHINE_NAME_PATTERN, $base) !== 1) {
            return '';
        }

        return mb_substr($base, 0, 63);
    }

    private function buildSlug(Vocabulary $vocabulary, string $text, string $locale, ?int $excludeId): string
    {
        $slugger = new AsciiSlugger($locale);
        $baseSlug = strtolower($slugger->slug($text)->toString());
        if ($baseSlug === '') {
            $baseSlug = 't-'.substr(bin2hex(random_bytes(4)), 0, 8);
        }

        $slug = $baseSlug;
        $suffix = 2;
        while (($existing = $this->termRepository->findOneBySlug($vocabulary, $slug, $locale)) !== null && $existing->getId() !== $excludeId) {
            $slug = $baseSlug.'-'.$suffix;
            ++$suffix;
        }

        return $slug;
    }

    private function resolveLocale(mixed $raw): string
    {
        return $this->localeProvider->resolve(\is_string($raw) ? $raw : null);
    }

    private function intOrNull(mixed $raw): ?int
    {
        return $raw !== null && ctype_digit((string) $raw) ? (int) $raw : null;
    }

    private function findVocabularyOrFail(string $machineName): Vocabulary
    {
        $vocabulary = $this->vocabularyRepository->findOneByMachineName($machineName);
        if (!$vocabulary instanceof Vocabulary) {
            throw new NotFoundHttpException($this->translator->trans('aacp.taxonomy.error.vocabulary_not_found'));
        }

        return $vocabulary;
    }

    private function findTermOrFail(Vocabulary $vocabulary, int $id): Term
    {
        $term = $this->termRepository->find($id);
        if (!$term instanceof Term || $term->getVocabulary()->getId() !== $vocabulary->getId()) {
            throw new NotFoundHttpException($this->translator->trans('aacp.taxonomy.error.term_not_found'));
        }

        return $term;
    }

    private function assertCsrf(Request $request, string $tokenId): void
    {
        if (!$this->isCsrfTokenValid($tokenId, (string) $request->request->get('_token'))) {
            throw new BadRequestHttpException($this->translator->trans('aacp.common.error.invalid_csrf'));
        }
    }
}
