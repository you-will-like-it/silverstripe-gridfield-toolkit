<?php

declare(strict_types=1);

namespace YouWillLikeIT\GridFieldToolkit\Component\Bulk;

use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Forms\GridField\AbstractGridFieldComponent;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridField_ColumnProvider;
use SilverStripe\Forms\GridField\GridField_HTMLProvider;
use SilverStripe\Forms\GridField\GridField_URLHandler;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Security;
use SilverStripe\Security\SecurityToken;
use SilverStripe\View\HTML;
use JsonException;
use Psr\Log\LoggerInterface;
use SilverStripe\Core\Validation\ValidationException;
use Throwable;
use YouWillLikeIT\GridFieldToolkit\Component\InlineEdit\GridFieldInlineEdit;
use YouWillLikeIT\GridFieldToolkit\Contract\BulkActionInterface;
use YouWillLikeIT\GridFieldToolkit\Support\ClientMarker;

/**
 * Bulk actions on a selection that is "stashed" in the browser: it survives paging, sorting, filtering and reloads
 * (per grid, until the page is left), so the user can collect rows across pages and act once.
 *
 * Selection is by record ID. Every ID is re-checked server-side against this grid's list and against the action's
 * per-record permission; partial success is reported per record (there is no all-or-nothing transaction).
 *
 * Endpoint: POST {gridfield}/ywli/stash/run/{action}  {ids: int[]}  ->  {ok, processed, failed: [{id, message}], message}
 */
class GridFieldStashBulk extends AbstractGridFieldComponent implements GridField_ColumnProvider, GridField_HTMLProvider, GridField_URLHandler
{
    public const FEATURE = 'stash';

    public const COLUMN = 'ywli-stash';

    /** @var array<string, BulkActionInterface> */
    private array $actions = [];

    public function __construct(private readonly int $maxSelection = 500, BulkActionInterface ...$actions)
    {
        $this->addAction(...$actions);
    }

    public function addAction(BulkActionInterface ...$actions): static
    {
        foreach ($actions as $action) {
            $this->actions[$action->getName()] = $action;
        }

        return $this;
    }

    /** @return array<string, BulkActionInterface> */
    public function getActions(): array
    {
        return $this->actions;
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
        return HTML::createTag('input', [
            'type' => 'checkbox',
            'class' => 'ywli-stash__box',
            'data-ywli-stash-id' => (string) $record->ID,
            'aria-label' => _t(self::class . '.SELECT_ROW', 'Select row'),
        ]);
    }

    #[\Override]
    public function getColumnAttributes($gridField, $record, $columnName)
    {
        return ['class' => 'ywli-stash-cell'];
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
        if ($this->actions === []) {
            return [];
        }

        $actions = [];
        foreach ($this->actions as $action) {
            $actions[] = [
                'name' => $action->getName(),
                'label' => $action->getLabel(),
                'destructive' => $action->isDestructive(),
                'confirm' => $action->getConfirmMessage(),
            ];
        }

        return [
            'before' => ClientMarker::html(self::FEATURE, [
                'url' => $gridField->Link('ywli/stash/run'),
                'securityID' => SecurityToken::inst()->getValue(),
                'max' => $this->maxSelection,
                'actions' => $actions,
                'strings' => [
                    'selected' => _t(self::class . '.SELECTED', '{count} selected'),
                    'clear' => _t(self::class . '.CLEAR', 'Clear'),
                    'selectAll' => _t(self::class . '.SELECT_ALL', 'Select all on this page'),
                    'confirmDefault' => _t(self::class . '.CONFIRM', 'Run this action on {count} records?'),
                    'done' => _t(self::class . '.DONE', '{count} done.'),
                    'partial' => _t(self::class . '.PARTIAL', '{count} done, {failed} failed.'),
                    'failed' => _t(self::class . '.FAILED', 'The action failed.'),
                    'limit' => _t(self::class . '.LIMIT', 'At most {max} records can be selected.'),
                ],
            ]),
        ];
    }

    // ---------------------------------------------------------------- URLHandler

    #[\Override]
    public function getURLHandlers($gridField): array
    {
        return ['POST ywli/stash/run/$Action' => 'handleRun'];
    }

    public function handleRun(GridField $gridField, HTTPRequest $request): HTTPResponse
    {
        if (!SecurityToken::inst()->checkRequest($request)) {
            return GridFieldInlineEdit::jsonError('csrf', 'Security token expired. Reload the page and try again.', 403);
        }

        $action = $this->actions[(string) $request->param('Action')] ?? null;
        if (!$action instanceof BulkActionInterface) {
            return GridFieldInlineEdit::jsonError('unknown_action', 'Unknown action.', 404);
        }

        try {
            $body = json_decode($request->getBody(), true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return GridFieldInlineEdit::jsonError('bad_request', 'Malformed JSON body.', 400);
        }

        $raw = is_array($body) ? ($body['ids'] ?? null) : null;
        if (!is_array($raw) || $raw === [] || !array_is_list($raw)) {
            return GridFieldInlineEdit::jsonError('bad_request', 'Expected {ids: [..]}.', 400);
        }
        $ids = [];
        foreach ($raw as $value) {
            $id = filter_var($value, FILTER_VALIDATE_INT);
            if ($id === false || $id < 1) {
                return GridFieldInlineEdit::jsonError('bad_request', 'IDs must be positive integers.', 400);
            }
            $ids[$id] = $id;
        }
        if (count($ids) > $this->maxSelection) {
            return GridFieldInlineEdit::jsonError('too_many', 'Too many records selected.', 413);
        }

        $member = Security::getCurrentUser();
        $records = [];
        // Unmanipulated list: the selection may span pages and filters, but never leaves this grid's own list.
        foreach ($gridField->getList()->filter('ID', array_values($ids)) as $record) {
            $records[(int) $record->ID] = $record;
        }

        $processed = 0;
        $failed = [];
        foreach ($ids as $id) {
            $record = $records[$id] ?? null;
            if (!$record instanceof DataObject) {
                $failed[] = ['id' => $id, 'message' => 'Record not found.'];
                continue;
            }
            if (!$action->canRun($record, $member)) {
                $failed[] = ['id' => $id, 'message' => 'Not allowed.'];
                continue;
            }
            try {
                $action->run($record, $member);
                ++$processed;
            } catch (ValidationException $e) {
                $failed[] = ['id' => $id, 'message' => $e->getMessage()];
            } catch (Throwable $e) {
                Injector::inst()->get(LoggerInterface::class)->error($e->getMessage(), ['exception' => $e]);
                $failed[] = ['id' => $id, 'message' => 'The action failed for this record.'];
            }
        }

        return GridFieldInlineEdit::json(['ok' => true, 'processed' => $processed, 'failed' => $failed]);
    }
}
