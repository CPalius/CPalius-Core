<?php

declare(strict_types=1);

namespace App\Core\Diagnostics\Check;

use App\Core\Diagnostics\DoctorCheckInterface;
use App\Core\Diagnostics\DoctorFinding;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

/**
 * Compares translation catalogues key by key across locales.
 *
 * Symfony falls back to English when a key is missing, so an untranslated
 * string does not break anything — it just surfaces in the wrong language in
 * front of a user, usually on a page nobody on the team visits in that locale.
 * Line counts are not enough to catch this (two files can have equal line
 * counts and different keys), so the comparison is done on flattened key sets.
 *
 * Catalogues are read from disk rather than from the translator, because the
 * translator merges locales behind a fallback chain — which is precisely the
 * mechanism that hides the gap this check is looking for.
 */
final class TranslationParityCheck implements DoctorCheckInterface
{
    /** Reported as one finding per domain; more than this is listed as a count. */
    private const SAMPLE = 8;

    public function __construct(
        #[Autowire(param: 'kernel.project_dir')]
        private readonly string $projectDir,
    ) {
    }

    public function key(): string
    {
        return 'translations';
    }

    public function run(): array
    {
        $catalogues = $this->collect();

        if ($catalogues === []) {
            return [new DoctorFinding(
                id: 'translations.none',
                severity: DoctorFinding::SEVERITY_LOW,
                title: 'Translations',
                detail: 'No translation catalogue was found.',
                remedy: null,
            )];
        }

        $findings = [];
        $domainsChecked = 0;

        foreach ($catalogues as $domain => $byLocale) {
            if (\count($byLocale) < 2) {
                // A single-locale domain has nothing to be out of parity with.
                continue;
            }

            ++$domainsChecked;

            // The union is the reference: no locale is privileged, so a key
            // that exists only in Turkish is reported just like one that
            // exists only in English.
            $union = [];
            foreach ($byLocale as $keys) {
                $union += $keys;
            }

            foreach ($byLocale as $locale => $keys) {
                $missing = array_keys(array_diff_key($union, $keys));

                if ($missing === []) {
                    continue;
                }

                $findings[] = new DoctorFinding(
                    id: 'translations.missing_keys',
                    severity: DoctorFinding::SEVERITY_LOW,
                    title: sprintf('Missing translations in "%s" (%s)', $domain, $locale),
                    detail: sprintf(
                        '%d key(s) absent: %s',
                        \count($missing),
                        implode(', ', \array_slice($missing, 0, self::SAMPLE))
                            .(\count($missing) > self::SAMPLE ? sprintf(' … and %d more', \count($missing) - self::SAMPLE) : ''),
                    ),
                    remedy: 'Users in this locale see the fallback language for these strings.',
                );
            }
        }

        if ($findings === []) {
            $findings[] = DoctorFinding::pass(
                'translations.missing_keys',
                'Translations',
                sprintf('%d multi-locale domain(s) are in full parity.', $domainsChecked),
            );
        }

        return $findings;
    }

    /**
     * Flattened key sets, indexed by domain and then by locale.
     *
     * @return array<string, array<string, array<string, true>>>
     */
    private function collect(): array
    {
        $catalogues = [];

        foreach ($this->directories() as $directory) {
            foreach (glob($directory.'/*.yaml') ?: [] as $file) {
                // "messages+intl-icu.en.yaml" -> domain "messages+intl-icu", locale "en"
                if (!preg_match('/^(?<domain>.+)\.(?<locale>[a-z]{2}(?:[_-][A-Za-z]{2,4})?)\.ya?ml$/', basename($file), $m)) {
                    continue;
                }

                $parsed = $this->parse($file);

                if ($parsed === null) {
                    continue;
                }

                $keys = [];
                $this->flatten($parsed, '', $keys);
                $catalogues[$m['domain']][$m['locale']] = $keys;
            }
        }

        return $catalogues;
    }

    /**
     * @return list<string>
     */
    private function directories(): array
    {
        $directories = [$this->projectDir.'/cp-content/translations'];

        // Modules are bundles; Symfony auto-scans Resources/translations, so
        // the same layout is used here.
        foreach (glob($this->projectDir.'/cp-content/modules/*/Resources/translations', \GLOB_ONLYDIR) ?: [] as $moduleDirectory) {
            $directories[] = $moduleDirectory;
        }

        return array_values(array_filter($directories, 'is_dir'));
    }

    /**
     * @return array<mixed>|null null when the file is unreadable or malformed;
     *                           YAML validity is lint:yaml's job, not this check's
     */
    private function parse(string $file): ?array
    {
        try {
            $parsed = Yaml::parseFile($file);
        } catch (\Throwable) {
            return null;
        }

        return \is_array($parsed) ? $parsed : null;
    }

    /**
     * @param array<mixed>        $node
     * @param array<string, true> $keys
     */
    private function flatten(array $node, string $prefix, array &$keys): void
    {
        foreach ($node as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (\is_array($value) && $value !== [] && !array_is_list($value)) {
                $this->flatten($value, $path, $keys);

                continue;
            }

            $keys[$path] = true;
        }
    }
}
