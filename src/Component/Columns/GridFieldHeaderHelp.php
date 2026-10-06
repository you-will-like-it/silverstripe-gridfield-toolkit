<?php

declare(strict_types=1);

namespace YouWillLikeIT\GridFieldToolkit\Component\Columns;

use SilverStripe\Forms\GridField\AbstractGridFieldComponent;
use SilverStripe\Forms\GridField\GridField_HTMLProvider;
use YouWillLikeIT\GridFieldToolkit\Support\ClientMarker;

/**
 * Adds a small "?" to column headers; hover, focus or click shows the help text (plain text, Esc closes).
 * Pass already translated strings: `['Status' => _t('Order.STATUS_HELP', 'Where the order is in its life cycle')]`.
 */
class GridFieldHeaderHelp extends AbstractGridFieldComponent implements GridField_HTMLProvider
{
    public const FEATURE = 'header-help';

    /** @param array<string, string> $help column name => text */
    public function __construct(private readonly array $help)
    {
    }

    /** @return array<string, string> */
    public function getHelp(): array
    {
        return $this->help;
    }

    #[\Override]
    public function getHTMLFragments($gridField): array
    {
        if ($this->help === []) {
            return [];
        }

        return [
            'before' => ClientMarker::html(self::FEATURE, [
                'help' => array_map('strval', $this->help),
                'strings' => ['label' => _t(self::class . '.LABEL', 'Help for column {column}')],
            ]),
        ];
    }
}
