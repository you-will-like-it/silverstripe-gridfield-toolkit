<?php

declare(strict_types=1);

namespace YouWillLikeIT\GridFieldToolkit\Contract;

use SilverStripe\ORM\DataObject;

/** Source of per-locale translation state, so the grid column does not depend on one localisation module. */
interface TranslationStatusProviderInterface
{
    public const PUBLISHED = 'published';

    public const DRAFT = 'draft';

    public const MISSING = 'missing';

    /** @return array<string, string> locale code => label */
    public function getLocales(): array;

    /** @return 'published'|'draft'|'missing' */
    public function getStatus(DataObject $record, string $locale): string;
}
