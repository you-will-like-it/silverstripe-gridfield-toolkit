<?php

declare(strict_types=1);

namespace YouWillLikeIT\GridFieldToolkit\Component\Relation;

use SilverStripe\Forms\GridField\AbstractGridFieldComponent;
use SilverStripe\Forms\GridField\GridField_ColumnProvider;
use SilverStripe\Forms\GridField\GridField_HTMLProvider;
use SilverStripe\Forms\GridField\GridFieldDetailForm;
use SilverStripe\View\HTML;
use YouWillLikeIT\GridFieldToolkit\Support\ClientMarker;

/**
 * "Items (3)" button per row that opens the record's own edit form, on the tab holding the relation, in a modal.
 * It reuses the stock GridFieldDetailForm (`item/{id}`), so the nested relation grid is the normal one with all its
 * components and permissions. Needs GridFieldDetailForm and a relation GridField on that edit form.
 */
class GridFieldNestedRelation extends AbstractGridFieldComponent implements GridField_ColumnProvider, GridField_HTMLProvider
{
    public const FEATURE = 'nested-relation';

    public const COLUMN = 'ywli-nested';

    /**
     * @param string $relation has_many / many_many name, used for the count (e.g. 'Items')
     * @param string|null $tab Tab to select on the edit form (e.g. 'Root_Items'); defaults to Root_{relation}
     */
    public function __construct(
        private readonly string $relation,
        private readonly ?string $label = null,
        private readonly ?string $tab = null,
    ) {
    }

    public function getTab(): string
    {
        return $this->tab ?? 'Root_' . $this->relation;
    }

    #[\Override]
    public function augmentColumns($gridField, &$columns)
    {
        if (!in_array(self::COLUMN, $columns, true)) {
            $columns[] = self::COLUMN;
        }
    }

    #[\Override]
    public function getColumnsHandled($gridField)
    {
        return [self::COLUMN];
    }

    #[\Override]
    public function getColumnContent($gridField, $record, $columnName)
    {
        if ($gridField->getConfig()->getComponentByType(GridFieldDetailForm::class) === null
            || !$record->hasMethod($this->relation)
            || !$record->canView()
        ) {
            return '';
        }

        $count = (int) $record->{$this->relation}()->count();

        return HTML::createTag('button', [
            'type' => 'button',
            'class' => 'btn btn-sm btn-outline-secondary ywli-nested__open',
            'data-ywli-nested-url' => $gridField->Link('item/' . $record->ID),
            'data-ywli-nested-title' => (string) $record->getTitle(),
        ], htmlspecialchars($this->label ?? $this->relation) . ' <span class="ywli-nested__count">(' . $count . ')</span>');
    }

    #[\Override]
    public function getColumnAttributes($gridField, $record, $columnName)
    {
        return ['class' => 'ywli-nested-cell'];
    }

    #[\Override]
    public function getColumnMetadata($gridField, $columnName)
    {
        return ['title' => $this->label ?? $this->relation];
    }

    #[\Override]
    public function getHTMLFragments($gridField): array
    {
        return [
            'before' => ClientMarker::html(self::FEATURE, [
                'tab' => $this->getTab(),
                'strings' => [
                    'close' => _t(self::class . '.CLOSE', 'Close'),
                    'loading' => _t(self::class . '.LOADING', 'Loading…'),
                    'confirmLeave' => _t(self::class . '.CONFIRM_LEAVE', 'This record has unsaved changes. Discard them?'),
                ],
            ]),
        ];
    }
}
