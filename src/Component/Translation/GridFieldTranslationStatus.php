<?php

declare(strict_types=1);

namespace YouWillLikeIT\GridFieldToolkit\Component\Translation;

use SilverStripe\Forms\GridField\AbstractGridFieldComponent;
use SilverStripe\Forms\GridField\GridField_ColumnProvider;
use SilverStripe\Forms\GridField\GridField_HTMLProvider;
use SilverStripe\View\HTML;
use YouWillLikeIT\GridFieldToolkit\Contract\TranslationStatusProviderInterface;
use YouWillLikeIT\GridFieldToolkit\Support\ClientMarker;

/**
 * Column with one badge per locale: published / draft / missing. Needs a provider (FluentStatusProvider for Fluent).
 * Cost: one status lookup per row and locale; keep page sizes modest on sites with many locales.
 */
class GridFieldTranslationStatus extends AbstractGridFieldComponent implements GridField_ColumnProvider, GridField_HTMLProvider
{
    public const FEATURE = 'translation-status';

    public const COLUMN = 'ywli-translations';

    /** @var array<string, string>|null */
    private ?array $locales = null;

    public function __construct(private readonly TranslationStatusProviderInterface $provider)
    {
    }

    /** @return array<string, string> */
    private function locales(): array
    {
        return $this->locales ??= $this->provider->getLocales();
    }

    #[\Override]
    public function augmentColumns($gridField, &$columns)
    {
        if ($this->locales() !== [] && !in_array(self::COLUMN, $columns, true)) {
            $columns[] = self::COLUMN;
        }
    }

    #[\Override]
    public function getColumnsHandled($gridField)
    {
        return $this->locales() === [] ? [] : [self::COLUMN];
    }

    #[\Override]
    public function getColumnContent($gridField, $record, $columnName)
    {
        $html = '';
        foreach ($this->locales() as $code => $label) {
            $status = $this->provider->getStatus($record, $code);
            $text = $this->statusLabel($status);
            $html .= HTML::createTag('span', [
                'class' => 'ywli-lang ywli-lang--' . $status,
                'title' => $label . ': ' . $text,
                'data-ywli-locale' => $code,
                'data-ywli-status' => $status,
            ], htmlspecialchars(strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $code) ?? $code, 0, 2)))
                . '<span class="ywli-sr-only"> ' . htmlspecialchars($label . ': ' . $text) . '</span>');
        }

        return '<span class="ywli-langs">' . $html . '</span>';
    }

    #[\Override]
    public function getColumnAttributes($gridField, $record, $columnName)
    {
        return ['class' => 'ywli-translations-cell'];
    }

    #[\Override]
    public function getColumnMetadata($gridField, $columnName)
    {
        return ['title' => _t(self::class . '.TITLE', 'Translations')];
    }

    #[\Override]
    public function getHTMLFragments($gridField): array
    {
        return [];
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            TranslationStatusProviderInterface::PUBLISHED => _t(self::class . '.PUBLISHED', 'translated'),
            TranslationStatusProviderInterface::DRAFT => _t(self::class . '.DRAFT', 'draft'),
            default => _t(self::class . '.MISSING', 'missing'),
        };
    }
}
