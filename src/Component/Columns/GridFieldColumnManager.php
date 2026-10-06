<?php

declare(strict_types=1);

namespace YouWillLikeIT\GridFieldToolkit\Component\Columns;

use SilverStripe\Forms\GridField\AbstractGridFieldComponent;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridField_HTMLProvider;
use SilverStripe\Forms\GridField\GridField_StateProvider;
use SilverStripe\Forms\GridField\GridFieldDataColumns;
use SilverStripe\Forms\GridField\GridState_Data;
use SilverStripe\View\HTML;
use YouWillLikeIT\GridFieldToolkit\Support\ClientMarker;

/**
 * Lets the user show/hide columns. Hidden columns are not rendered at all (server-side), and the choice lives in the
 * grid's own GridState (`YWLIColumns.Hidden`, a comma list), so it travels with every request and, together with
 * withViewStatePersister(), survives navigation.
 *
 * This component only removes columns from the set the other components contribute, so it has to come after them
 * (the default when added with withColumnManager()). Unknown names in the state are ignored. Reordering is not offered.
 */
class GridFieldColumnManager extends AbstractGridFieldComponent implements GridField_StateProvider, GridField_HTMLProvider, \SilverStripe\Forms\GridField\GridField_ColumnProvider
{
    public const FEATURE = 'column-manager';

    public const STATE_KEY = 'YWLIColumns';

    /**
     * @param list<string> $locked Columns that cannot be hidden
     * @param list<string> $defaultHidden Hidden until the user changes it
     */
    public function __construct(private readonly array $locked = [], private readonly array $defaultHidden = [])
    {
    }

    #[\Override]
    public function initDefaultState(GridState_Data $data): void
    {
        $data->{self::STATE_KEY}->initDefaults(['Hidden' => implode(',', $this->defaultHidden)]);
    }

    /** @return list<string> */
    public function getHiddenColumns(GridField $gridField): array
    {
        $raw = (string) $gridField->State->{self::STATE_KEY}->getData('Hidden', '');
        $hidden = array_filter(explode(',', $raw), fn (string $c): bool => $c !== '' && !in_array($c, $this->locked, true));

        return array_values(array_intersect($hidden, array_keys($this->manageable($gridField))));
    }

    // ---------------------------------------------------------------- ColumnProvider (removal only)

    #[\Override]
    public function augmentColumns($gridField, &$columns)
    {
        $columns = array_values(array_diff($columns, $this->getHiddenColumns($gridField)));
    }

    #[\Override]
    public function getColumnsHandled($gridField)
    {
        return [];
    }

    #[\Override]
    public function getColumnContent($gridField, $record, $columnName)
    {
        return null;
    }

    #[\Override]
    public function getColumnAttributes($gridField, $record, $columnName)
    {
        return [];
    }

    #[\Override]
    public function getColumnMetadata($gridField, $columnName)
    {
        return [];
    }

    // ---------------------------------------------------------------- HTMLProvider

    #[\Override]
    public function getHTMLFragments($gridField): array
    {
        $hidden = $this->getHiddenColumns($gridField);
        $columns = [];
        foreach ($this->manageable($gridField) as $name => $label) {
            $columns[] = [
                'name' => $name,
                'label' => $label,
                'hidden' => in_array($name, $hidden, true),
                'locked' => in_array($name, $this->locked, true),
            ];
        }

        $label = _t(self::class . '.COLUMNS', 'Columns');

        return [
            'buttons-before-right' => HTML::createTag('button', [
                'type' => 'button',
                'class' => 'btn btn-secondary ywli-columns-toggle',
                'data-ywli-columns-toggle' => true,
                'aria-haspopup' => 'true',
                'aria-expanded' => 'false',
            ], $label),
            'before' => ClientMarker::html(self::FEATURE, [
                'stateKey' => self::STATE_KEY,
                'columns' => $columns,
                'defaultHidden' => array_values($this->defaultHidden),
                'strings' => [
                    'columns' => $label,
                    'reset' => _t(self::class . '.RESET', 'Show all'),
                ],
            ]),
        ];
    }

    /** @return array<string, string> column name => label (every display field, visible or not) */
    private function manageable(GridField $gridField): array
    {
        $columns = $gridField->getConfig()->getComponentByType(GridFieldDataColumns::class);
        $fields = $columns instanceof GridFieldDataColumns ? $columns->getDisplayFields($gridField) : [];

        $result = [];
        foreach ($fields as $name => $spec) {
            $result[(string) $name] = is_array($spec) ? (string) ($spec['title'] ?? $name) : (string) $spec;
        }

        return $result;
    }
}
