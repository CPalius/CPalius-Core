<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Field;

use App\Core\Content\RichTextSanitizer;
use App\Core\Display\EntityDisplayRegistry;
use App\Core\Display\Repository\EntityDisplayRepository;
use App\Core\Display\ViewModeRegistry;
use App\Core\Field\Display\FieldFormatterResolver;
use App\Core\Field\Display\ReferenceBatchLoader;
use App\Core\Field\Entity\FieldDefinition;
use App\Core\Field\FieldContext;
use App\Core\Field\FieldDefinitionRegistry;
use App\Core\Field\FieldTypeRegistry;
use App\Core\Field\Form\FieldWidgetResolver;
use App\Core\Field\ReferenceTargetResolver;
use App\Core\Field\Repository\FieldDefinitionRepository;
use App\Core\Field\Type\BooleanFieldType;
use App\Core\Field\Type\DateFieldType;
use App\Core\Field\Type\DateTimeFieldType;
use App\Core\Field\Type\DecimalFieldType;
use App\Core\Field\Type\EmailFieldType;
use App\Core\Field\Type\EntityReferenceFieldType;
use App\Core\Field\Type\FileFieldType;
use App\Core\Field\Type\ImageFieldType;
use App\Core\Field\Type\IntegerFieldType;
use App\Core\Field\Type\RichTextFieldType;
use App\Core\Field\Type\SelectFieldType;
use App\Core\Field\Type\TextareaFieldType;
use App\Core\Field\Type\TextFieldType;
use App\Core\Field\Type\UrlFieldType;
use App\Core\Localization\LocaleProvider;
use App\Core\Media\AssetUrlGenerator;
use App\Core\Media\ImageProcessor;
use App\Core\Media\Twig\ImageThumbnailRuntime;
use App\Core\Settings\SettingsRegistry;
use App\Repository\LocaleRepository;
use App\Repository\SettingRepository;
use App\Core\Resource\ResourceRegistry;
use App\Core\Taxonomy\Repository\VocabularyRepository;
use App\Core\Taxonomy\VocabularyRegistry;
use App\Core\TextFormat\Filter\AutoLinkFilter;
use App\Core\TextFormat\Filter\HtmlRestrictFilter;
use App\Core\TextFormat\Filter\MarkdownFilter;
use App\Core\TextFormat\Filter\MediaEmbedFilter;
use App\Core\TextFormat\Filter\NewlineToBrFilter;
use App\Core\TextFormat\Filter\TokenFilter;
use App\Core\TextFormat\TextFormatAccess;
use App\Core\TextFormat\TextFormatProcessor;
use App\Core\TextFormat\TextFormatRegistry;
use App\Core\Token\TokenReplacer;
use App\Core\Token\TokenTypeRegistry;
use App\Repository\AssetRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\Translation\IdentityTranslator;
use Symfony\Component\Yaml\Yaml;

/**
 * Builds a real FieldTypeRegistry (all 14 core types) plus in-memory field
 * definitions, without booting the kernel.
 */
trait FieldTestTrait
{
    protected function richTextSanitizer(): RichTextSanitizer
    {
        $config = (new HtmlSanitizerConfig())
            ->allowSafeElements()
            ->allowRelativeLinks()
            ->allowRelativeMedias();

        return new RichTextSanitizer(new HtmlSanitizer($config));
    }

    protected function referenceResolver(): ReferenceTargetResolver
    {
        return new ReferenceTargetResolver(
            $this->createMock(EntityManagerInterface::class),
            new ResourceRegistry(),
            $this->vocabularyRegistry(),
        );
    }

    protected function vocabularyRegistry(): VocabularyRegistry
    {
        $repository = $this->createMock(VocabularyRepository::class);
        $repository->method('findAllOrdered')->willReturn([]);

        return new VocabularyRegistry($repository, new ArrayAdapter());
    }

    /**
     * EntityDisplayRegistry with no stored overrides — every field renders
     * with its own defaults, in every view mode (the zero-config behaviour).
     */
    protected function entityDisplayRegistry(FieldDefinitionRegistry $fieldDefinitions): EntityDisplayRegistry
    {
        $repository = $this->createMock(EntityDisplayRepository::class);
        $repository->method('findByBundleAndViewMode')->willReturn([]);
        $repository->method('distinctBundles')->willReturn([]);

        return new EntityDisplayRegistry($repository, $fieldDefinitions, new ViewModeRegistry(), new ArrayAdapter());
    }

    protected function assetRepository(): AssetRepository
    {
        return $this->createMock(AssetRepository::class);
    }

    protected function fieldTypeRegistry(): FieldTypeRegistry
    {
        $types = [
            TextFieldType::class => fn () => new TextFieldType(),
            TextareaFieldType::class => fn () => new TextareaFieldType(),
            RichTextFieldType::class => fn () => new RichTextFieldType(
                $this->textFormatProcessor(),
                $this->textFormatAccess(),
                $this->textFormatRegistry(),
            ),
            BooleanFieldType::class => fn () => new BooleanFieldType(),
            IntegerFieldType::class => fn () => new IntegerFieldType(),
            DecimalFieldType::class => fn () => new DecimalFieldType(),
            EmailFieldType::class => fn () => new EmailFieldType(),
            UrlFieldType::class => fn () => new UrlFieldType(),
            DateFieldType::class => fn () => new DateFieldType(),
            DateTimeFieldType::class => fn () => new DateTimeFieldType(),
            SelectFieldType::class => fn () => new SelectFieldType(),
            EntityReferenceFieldType::class => fn () => new EntityReferenceFieldType($this->referenceResolver()),
            ImageFieldType::class => fn () => new ImageFieldType($this->assetRepository()),
            FileFieldType::class => fn () => new FileFieldType($this->assetRepository()),
        ];

        $serviceByType = [];
        foreach ($types as $class => $_) {
            $serviceByType[$class::id()] = $class;
        }

        return new FieldTypeRegistry(new ServiceLocator($types), $serviceByType);
    }

    /**
     * @param list<FieldDefinition> $definitions
     */
    protected function fieldDefinitionRegistry(array $definitions): FieldDefinitionRegistry
    {
        $byBundle = [];
        foreach ($definitions as $definition) {
            $byBundle[$definition->getBundle()][] = $definition;
        }

        $repository = $this->createMock(FieldDefinitionRepository::class);
        $repository->method('findByBundle')->willReturnCallback(
            static fn (string $bundle): array => $byBundle[$bundle] ?? [],
        );

        return new FieldDefinitionRegistry($repository, new ArrayAdapter());
    }

    protected function definition(
        string $bundle,
        string $name,
        string $type,
        int $cardinality = 1,
        bool $required = false,
        bool $queryable = false,
        array $settings = [],
    ): FieldDefinition {
        $def = new FieldDefinition($bundle, $name, $type, ucfirst($name));
        $def->setCardinality($cardinality)->setRequired($required)->setQueryable($queryable)->setSettings($settings);

        return $def;
    }

    protected function context(FieldDefinition $definition, string $locale = 'en'): FieldContext
    {
        return new FieldContext($definition, $locale);
    }

    protected function referenceBatchLoader(?ReferenceTargetResolver $resolver = null): ReferenceBatchLoader
    {
        return new ReferenceBatchLoader($resolver ?? $this->referenceResolver());
    }

    /**
     * A URL generator with no settings behind it, which is to say with the CDN
     * off — field formatter tests assert on "/uploads/..." paths and have no
     * opinion about hostnames.
     */
    protected function assetUrlGenerator(): AssetUrlGenerator
    {
        $settingRepository = $this->createMock(SettingRepository::class);
        $settingRepository->method('findAllAsMap')->willReturn([]);

        $localeRepository = $this->createMock(LocaleRepository::class);
        $localeRepository->method('findActive')->willReturn([]);

        return new AssetUrlGenerator(
            new SettingsRegistry(
                $settingRepository,
                new LocaleProvider($localeRepository, new ArrayAdapter(), 'tr', 'tr'),
                new RequestStack(),
                new ArrayAdapter(),
            ),
        );
    }

    protected function formatterResolver(?ReferenceBatchLoader $references = null): FieldFormatterResolver
    {
        return new FieldFormatterResolver(
            $this->fieldTypeRegistry(),
            $references ?? $this->referenceBatchLoader(),
            new ImageThumbnailRuntime(
                new ImageProcessor(sys_get_temp_dir()),
                $this->assetRepository(),
                $this->assetUrlGenerator(),
            ),
            $this->assetRepository(),
            new IdentityTranslator(),
            $this->textFormatProcessor(),
        );
    }

    protected function textFormatRegistry(): TextFormatRegistry
    {
        $registry = new TextFormatRegistry();
        $file = \dirname(__DIR__, 4).'/config/text_formats.yaml';
        if (is_file($file)) {
            $data = Yaml::parseFile($file);
            foreach (($data['text_formats'] ?? []) as $id => $spec) {
                if (\is_string($id) && \is_array($spec)) {
                    $registry->register($id, $spec);
                }
            }
        }

        return $registry;
    }

    protected function textFormatProcessor(): TextFormatProcessor
    {
        $restrict = new HtmlRestrictFilter();

        return new TextFormatProcessor(
            [
                $restrict,
                new NewlineToBrFilter(),
                new AutoLinkFilter(),
                new MarkdownFilter(),
                new MediaEmbedFilter(),
                new TokenFilter(new TokenReplacer([], new TokenTypeRegistry())),
            ],
            $this->textFormatRegistry(),
            $restrict,
        );
    }

    protected function textFormatAccess(): TextFormatAccess
    {
        $security = $this->createMock(Security::class);
        $security->method('isGranted')->willReturn(true);
        $security->method('getUser')->willReturn(null);

        return new TextFormatAccess($this->textFormatRegistry(), $security);
    }

    protected function fieldWidgetResolver(): FieldWidgetResolver
    {
        return new FieldWidgetResolver(
            $this->fieldTypeRegistry(),
            $this->textFormatAccess(),
            $this->textFormatRegistry(),
            new IdentityTranslator(),
        );
    }
}
