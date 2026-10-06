<?php

declare(strict_types=1);

namespace YouWillLikeIT\GridFieldToolkit\Component\Translation;

use SilverStripe\ORM\DataObject;
use YouWillLikeIT\GridFieldToolkit\Contract\TranslationStatusProviderInterface;

/**
 * Translation state from tractorcow/silverstripe-fluent, resolved at runtime so Fluent stays an optional dependency.
 * Uses Fluent's `isPublishedInLocale()` / `isDraftedInLocale()` / `existsInLocale()` record methods when present.
 *
 * NOTE: not exercised against a real Fluent install in this module's test suite (Fluent is not a dependency);
 * the component itself is tested through TranslationStatusProviderInterface.
 */
class FluentStatusProvider implements TranslationStatusProviderInterface
{
    private const LOCALE_CLASS = 'TractorCow\\Fluent\\Model\\Locale';

    /** @param list<string>|null $only Restrict to these locale codes */
    public function __construct(private readonly ?array $only = null)
    {
    }

    public static function isAvailable(): bool
    {
        return class_exists(self::LOCALE_CLASS);
    }

    #[\Override]
    public function getLocales(): array
    {
        if (!self::isAvailable()) {
            return [];
        }

        $class = self::LOCALE_CLASS;
        $locales = [];
        foreach ($class::getLocales() as $locale) {
            $code = (string) $locale->Locale;
            if ($this->only === null || in_array($code, $this->only, true)) {
                $locales[$code] = (string) ($locale->Title ?: $code);
            }
        }

        return $locales;
    }

    #[\Override]
    public function getStatus(DataObject $record, string $locale): string
    {
        if ($record->hasMethod('isPublishedInLocale') && $record->isPublishedInLocale($locale)) {
            return self::PUBLISHED;
        }
        if ($record->hasMethod('isDraftedInLocale') && $record->isDraftedInLocale($locale)) {
            return self::DRAFT;
        }
        if (!$record->hasMethod('isDraftedInLocale') && $record->hasMethod('existsInLocale') && $record->existsInLocale($locale)) {
            return self::PUBLISHED;
        }

        return self::MISSING;
    }
}
