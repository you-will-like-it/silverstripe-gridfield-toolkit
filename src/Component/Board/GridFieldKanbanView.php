<?php

declare(strict_types=1);

namespace YouWillLikeIT\GridFieldToolkit\Component\Board;

use Closure;
use JsonException;
use SilverStripe\Core\Validation\ValidationException;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Forms\GridField\AbstractGridFieldComponent;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridField_DataManipulator;
use SilverStripe\Forms\GridField\GridField_HTMLProvider;
use SilverStripe\Forms\GridField\GridField_URLHandler;
use SilverStripe\Forms\GridField\GridFieldDetailForm;
use SilverStripe\Forms\GridField\GridFieldPaginator;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\FieldType\DBEnum;
use SilverStripe\Security\Security;
use SilverStripe\Security\SecurityToken;
use SilverStripe\View\HTML;
use Throwable;
use YouWillLikeIT\GridFieldToolkit\Component\InlineEdit\GridFieldInlineEdit;
use YouWillLikeIT\GridFieldToolkit\Support\ClientMarker;

/**
 * Board (Kanban) view of the same list: one lane per value of $groupField, drag a card to change that value.
 *
 * Columns: an array (value => label), a Closure(GridField): array, or null to use the values of an Enum field.
 * The board shows the full list as shaped by the other manipulators except pagination (capped at $limit; the
 * client is told when it is truncated). Header filter/sort *state* is not carried over to the board.
 *
 * Endpoints (CSRF via X-SecurityID on POST):
 *   GET  ywli/kanban/data  -> {ok, columns:[{value,label,locked}], cards:[{id,group,title,fields,link,canEdit}], truncated, total}
 *   POST ywli/kanban/move  {id, to, from} -> {ok, id, group}; 409 if the card's current lane is not `from`.
 */
class GridFieldKanbanView extends AbstractGridFieldComponent implements GridField_URLHandler, GridField_HTMLProvider
{
    public const FEATURE = 'kanban';

    /**
     * @param array<string, string>|Closure(GridField): array<string, string>|null $columns
     * @param list<string> $cardFields Extra fields shown on each card (relField paths allowed)
     */
    public function __construct(
        private readonly string $groupField,
        private readonly string $titleField = 'Title',
        private readonly array $cardFields = [],
        private readonly array|Closure|null $columns = null,
        private readonly int $limit = 500,
    ) {
    }

    // ---------------------------------------------------------------- HTMLProvider

    #[\Override]
    public function getHTMLFragments($gridField): array
    {
        $board = _t(self::class . '.BOARD', 'Board');

        return [
            'buttons-before-right' => HTML::createTag('button', [
                'type' => 'button',
                'class' => 'btn btn-secondary ywli-kanban-toggle',
                'data-ywli-kanban-toggle' => true,
                'aria-pressed' => 'false',
            ], $board),
            'before' => ClientMarker::html(self::FEATURE, [
                'dataUrl' => $gridField->Link('ywli/kanban/data'),
                'moveUrl' => $gridField->Link('ywli/kanban/move'),
                'securityID' => SecurityToken::inst()->getValue(),
                'strings' => [
                    'board' => $board,
                    'list' => _t(self::class . '.LIST', 'List'),
                    'loading' => _t(self::class . '.LOADING', 'Loading…'),
                    'loadFailed' => _t(self::class . '.LOAD_FAILED', 'Could not load the board.'),
                    'moveFailed' => _t(self::class . '.MOVE_FAILED', 'Could not move the card.'),
                    'conflict' => _t(self::class . '.CONFLICT', 'This card was changed by someone else. Board refreshed.'),
                    'truncated' => _t(self::class . '.TRUNCATED', 'Only the first cards are shown.'),
                    'empty' => _t(self::class . '.EMPTY', 'No cards'),
                    'moved' => _t(self::class . '.MOVED', 'Moved to'),
                    'help' => _t(self::class . '.HELP', 'Drag cards between lanes, or focus a card and press Alt + Left / Right.'),
                ],
            ]),
        ];
    }

    // ---------------------------------------------------------------- URLHandler

    #[\Override]
    public function getURLHandlers($gridField): array
    {
        return [
            'GET ywli/kanban/data' => 'handleData',
            'POST ywli/kanban/move' => 'handleMove',
        ];
    }

    public function handleData(GridField $gridField, HTTPRequest $request): HTTPResponse
    {
        $member = Security::getCurrentUser();
        if (!singleton($gridField->getModelClass())->canView($member)) {
            return GridFieldInlineEdit::jsonError('forbidden', 'Not allowed.', 403);
        }

        $lanes = $this->lanes($gridField);
        $list = $this->boardList($gridField);
        $total = $list->count();
        $detail = $gridField->getConfig()->getComponentByType(GridFieldDetailForm::class);

        $cards = [];
        $known = array_keys($lanes);
        $hasUnmapped = false;
        foreach ($list->limit($this->limit) as $record) {
            if (!$record->canView($member)) {
                continue;
            }
            $group = $this->groupOf($record);
            if (!in_array($group, $known, true)) {
                $hasUnmapped = true;
            }
            $fields = [];
            foreach ($this->cardFields as $field) {
                $fields[] = ['label' => $this->labelFor($record, $field), 'value' => $this->valueOf($record, $field)];
            }
            $cards[] = [
                'id' => (int) $record->ID,
                'group' => in_array($group, $known, true) ? $group : '',
                'title' => $this->valueOf($record, $this->titleField),
                'fields' => $fields,
                'link' => $detail ? $gridField->Link('item/' . $record->ID) : null,
                'canEdit' => $record->canEdit($member),
            ];
        }

        $columns = [];
        foreach ($lanes as $value => $label) {
            $columns[] = ['value' => (string) $value, 'label' => $label, 'locked' => false];
        }
        if ($hasUnmapped) {
            $columns[] = ['value' => '', 'label' => _t(self::class . '.OTHER', 'Other'), 'locked' => true];
        }

        return GridFieldInlineEdit::json([
            'ok' => true,
            'columns' => $columns,
            'cards' => $cards,
            'total' => $total,
            'truncated' => $total > $this->limit,
        ]);
    }

    public function handleMove(GridField $gridField, HTTPRequest $request): HTTPResponse
    {
        if (!SecurityToken::inst()->checkRequest($request)) {
            return GridFieldInlineEdit::jsonError('csrf', 'Security token expired. Reload the page and try again.', 403);
        }

        try {
            $body = json_decode($request->getBody(), true, 6, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return GridFieldInlineEdit::jsonError('bad_request', 'Malformed JSON body.', 400);
        }

        $id = is_array($body) ? filter_var($body['id'] ?? null, FILTER_VALIDATE_INT) : false;
        $to = is_array($body) ? ($body['to'] ?? null) : null;
        $from = is_array($body) ? ($body['from'] ?? null) : null;
        if ($id === false || $id < 1 || !is_string($to) || !is_string($from)) {
            return GridFieldInlineEdit::jsonError('bad_request', 'Expected {id, to, from}.', 400);
        }

        if (!array_key_exists($to, $this->lanes($gridField))) {
            return GridFieldInlineEdit::jsonError('invalid_target', 'Unknown target lane.', 422);
        }

        $member = Security::getCurrentUser();
        $record = $gridField->getList()->byID($id);
        if (!$record instanceof DataObject) {
            return GridFieldInlineEdit::jsonError('not_found', 'Record not found.', 404);
        }
        if (!$record->canEdit($member)) {
            return GridFieldInlineEdit::jsonError('forbidden', 'You may not edit this record.', 403);
        }

        $current = $this->groupOf($record);
        if ($current !== $from) {
            return GridFieldInlineEdit::json(['ok' => false, 'error' => 'conflict', 'message' => 'Card moved elsewhere.', 'group' => $current], 409);
        }
        if ($current === $to) {
            return GridFieldInlineEdit::json(['ok' => true, 'id' => $id, 'group' => $to]);
        }

        try {
            DB::get_conn()->withTransaction(function () use ($record, $to): void {
                $record->setField($this->groupField, $to);
                $record->write();
            });
        } catch (ValidationException $e) {
            return GridFieldInlineEdit::jsonError('validation', $e->getMessage(), 422);
        } catch (Throwable $e) {
            return GridFieldInlineEdit::jsonError('server_error', 'Could not move the card.', 500);
        }

        return GridFieldInlineEdit::json(['ok' => true, 'id' => $id, 'group' => $this->groupOf($record)]);
    }

    // ---------------------------------------------------------------- internals

    /** @return array<string, string> value => label */
    private function lanes(GridField $gridField): array
    {
        $columns = $this->columns;
        if ($columns instanceof Closure) {
            $columns = $columns($gridField);
        }
        if ($columns === null) {
            $field = singleton($gridField->getModelClass())->dbObject($this->groupField);
            $columns = $field instanceof DBEnum ? $field->enumValues(false) : [];
        }

        $lanes = [];
        foreach ($columns as $value => $label) {
            // A list ['a', 'b'] means value == label.
            $key = is_int($value) ? (string) $label : (string) $value;
            $lanes[$key] = (string) $label;
        }

        return $lanes;
    }

    /** The list after every manipulator except pagination. */
    private function boardList(GridField $gridField): \SilverStripe\Model\List\SS_List
    {
        $list = $gridField->getList();
        foreach ($gridField->getConfig()->getComponents() as $component) {
            if ($component instanceof GridField_DataManipulator && !$component instanceof GridFieldPaginator) {
                $list = $component->getManipulatedData($gridField, $list);
            }
        }

        return $list;
    }

    private function groupOf(DataObject $record): string
    {
        return (string) $record->getField($this->groupField);
    }

    private function valueOf(DataObject $record, string $field): string
    {
        try {
            return trim((string) $record->relField($field));
        } catch (Throwable) {
            return '';
        }
    }

    private function labelFor(DataObject $record, string $field): string
    {
        return (string) ($record->fieldLabels()[$field] ?? $field);
    }
}
