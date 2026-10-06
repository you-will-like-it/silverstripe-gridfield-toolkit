<?php

declare(strict_types=1);

namespace YouWillLikeIT\GridFieldToolkit\Component\Layout;

use Closure;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Convert;
use SilverStripe\Forms\GridField\AbstractGridFieldComponent;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridField_ColumnProvider;
use SilverStripe\Forms\GridField\GridField_HTMLProvider;
use SilverStripe\Forms\GridField\GridField_URLHandler;
use SilverStripe\Model\ModelData;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Security;
use SilverStripe\View\HTML;
use YouWillLikeIT\GridFieldToolkit\Component\InlineEdit\GridFieldInlineEdit;
use YouWillLikeIT\GridFieldToolkit\Support\ClientMarker;

/**
 * Expandable detail row. Adds a leading chevron column; the detail is fetched lazily per row.
 *
 * The renderer receives the record and returns:
 *  - string          trusted HTML (the developer is responsible for escaping interpolated data)
 *  - array           label => value pairs, rendered as an escaped definition list
 *  - ModelData       rendered via forTemplate()
 *
 * Endpoint: GET {gridfield}/ywli/accordion/{id} -> {ok, html}. Requires canView() on the record.
 */
class GridFieldAccordion extends AbstractGridFieldComponent implements GridField_ColumnProvider, GridField_URLHandler, GridField_HTMLProvider
{
    public const FEATURE = 'accordion';

    public const COLUMN = 'ywli-accordion';

    /** @param Closure(DataObject): (string|array<string, mixed>|ModelData) $renderer */
    public function __construct(private readonly Closure $renderer, private readonly bool $multiple = true)
    {
    }

    // ---------------------------------------------------------------- ColumnProvider

    #[\Override]
    public function augmentColumns($gridField, &$columns)
    {
        if (!in_array(self::COLUMN, $columns, true)) {
            array_unshift($columns, self::COLUMN);
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
        return HTML::createTag('button', [
            'type' => 'button',
            'class' => 'ywli-accordion__toggle',
            'aria-expanded' => 'false',
            'data-ywli-accordion-id' => (string) $record->ID,
            'aria-label' => _t(self::class . '.EXPAND', 'Show details'),
        ], '<span class="ywli-accordion__chevron" aria-hidden="true"></span>');
    }

    #[\Override]
    public function getColumnAttributes($gridField, $record, $columnName)
    {
        return ['class' => 'ywli-accordion-cell'];
    }

    #[\Override]
    public function getColumnMetadata($gridField, $columnName)
    {
        return ['title' => ''];
    }

    // ---------------------------------------------------------------- HTMLProvider

    #[\Override]
    public function getHTMLFragments($gridField): array
    {
        return [
            'before' => ClientMarker::html(self::FEATURE, [
                'url' => $gridField->Link('ywli/accordion'),
                'multiple' => $this->multiple,
                'strings' => [
                    'expand' => _t(self::class . '.EXPAND', 'Show details'),
                    'collapse' => _t(self::class . '.COLLAPSE', 'Hide details'),
                    'loading' => _t(self::class . '.LOADING', 'Loading…'),
                    'loadFailed' => _t(self::class . '.LOAD_FAILED', 'Could not load the details.'),
                ],
            ]),
        ];
    }

    // ---------------------------------------------------------------- URLHandler

    #[\Override]
    public function getURLHandlers($gridField): array
    {
        return ['GET ywli/accordion/$ID' => 'handleDetail'];
    }

    public function handleDetail(GridField $gridField, HTTPRequest $request): HTTPResponse
    {
        $id = filter_var($request->param('ID'), FILTER_VALIDATE_INT);
        if ($id === false || $id < 1) {
            return GridFieldInlineEdit::jsonError('bad_request', 'Invalid record ID.', 400);
        }

        // Unmanipulated list: a row on another page or hidden by a filter is still this grid's record.
        $record = $gridField->getList()->byID($id);
        if (!$record instanceof DataObject) {
            return GridFieldInlineEdit::jsonError('not_found', 'Record not found.', 404);
        }
        if (!$record->canView(Security::getCurrentUser())) {
            return GridFieldInlineEdit::jsonError('forbidden', 'You may not view this record.', 403);
        }

        return GridFieldInlineEdit::json(['ok' => true, 'id' => $id, 'html' => $this->render($record)]);
    }

    private function render(DataObject $record): string
    {
        $result = ($this->renderer)($record);

        if ($result instanceof ModelData) {
            return (string) $result->forTemplate();
        }
        if (is_array($result)) {
            $html = '';
            foreach ($result as $label => $value) {
                $html .= '<dt>' . Convert::raw2xml((string) $label) . '</dt><dd>' . Convert::raw2xml((string) $value) . '</dd>';
            }

            return '<dl class="ywli-accordion__list">' . $html . '</dl>';
        }

        return (string) $result;
    }
}
