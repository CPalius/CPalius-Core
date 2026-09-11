<?php

declare(strict_types=1);

namespace App\Core\Module;

/**
 * Every installable module must ship an English catalogue.
 * The translator fallback chain is locale → en (see translation.yaml).
 */
final class ModuleTranslationContract
{
    public const ENGLISH_CATALOGUE = 'messages+intl-icu.en.yaml';

    public static function englishCataloguePath(string $moduleDir): string
    {
        return rtrim($moduleDir, '/\\').'/Resources/translations/'.self::ENGLISH_CATALOGUE;
    }

    /**
     * @return list<string> Empty when the contract is satisfied.
     */
    public static function problems(string $moduleDir): array
    {
        $path = self::englishCataloguePath($moduleDir);

        if (!is_file($path)) {
            return [sprintf('Missing required English catalogue: Resources/translations/%s', self::ENGLISH_CATALOGUE)];
        }

        if (!is_readable($path)) {
            return [sprintf('English catalogue is not readable: %s', self::ENGLISH_CATALOGUE)];
        }

        $contents = file_get_contents($path);
        if ($contents === false || trim($contents) === '') {
            return [sprintf('English catalogue is empty: Resources/translations/%s', self::ENGLISH_CATALOGUE)];
        }

        return [];
    }

    public static function installerPath(string $moduleDir): string
    {
        return rtrim($moduleDir, '/\\').'/Install/ModuleInstaller.php';
    }

    /**
     * @return list<string>
     */
    public static function packageProblems(string $moduleDir): array
    {
        return ModulePackageContract::problems($moduleDir);
    }
}
