<?php

declare(strict_types=1);

namespace YouWillLikeIT\GridFieldToolkit\Component\InlineEdit;

use InvalidArgumentException;
use JsonException;
use Psr\Log\LoggerInterface;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Validation\ValidationException;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridField_HTMLProvider;
use SilverStripe\Forms\GridField\GridField_URLHandler;
use SilverStripe\Forms\GridField\GridFieldDataColumns;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\Security\Member;
use SilverStripe\Security\Security;
use SilverStripe\Security\SecurityToken;
use SilverStripe\View\HTML;
use Throwable;
use YouWillLikeIT\GridFieldToolkit\Contract\DependentEditorInterface;
use YouWillLikeIT\GridFieldToolkit\Contract\InlineEditorInterface;
use YouWillLikeIT\GridFieldToolkit\Contract\OptionsEditorInterface;
use YouWillLikeIT\GridFieldToolkit\Service\UndoMemento;

/**
 * Owns the editable display columns and delegates base rendering to GridFieldDataColumns.
 *
 * Why a subclass instead of a sibling ColumnProvider: GridField::getColumnContent() concatenates the output of
 * every provider that handles a column, and getColumnAttributes() array_merges. A sibling would double-render.
 * Use GridFieldConfig_ToolkitBase::withInlineEdit(), or GridFieldInlineEdit::fromDataColumns() to swap it in.
 *
 * Endpoints (all relative to the GridField link, JSON in/out):
 *   POST ywli/inline/patch            {"id": 12, "etag": "...", "changes": {"IsActive": true}}  (X-SecurityID header)
 *   GET  ywli/inline/options/$Column  ?id=12   -> {"ok": true, "options": [{"value", "label"}]}
 *   Success: {"ok": true, "id", "etag", "cells": {col: html}, "changes", "previous", "undo": {...}|null}
 *   Failure: {"ok": false, "error": code, "message"}; 409 additionally carries the current "id", "etag" and "cells".
 */
class GridFieldInlineEdit extends GridFieldDataColumns implements GridField_URLHandler, GridField_HTMLProvider
{
    public const FEATURE = 'inline-edit';

    /** @var array<string, InlineEditorInterface> */
    private array $editors = [];

    private int $conflictPauseMs = 2500;

    /**
     * Copy display configuration from an existing GridFieldDataColumns so ModelAdmin/summary_fields,
     * casting, formatting and escaping survive the swap.
     */
    public static function fromDataColumns(GridFieldDataColumns $source): static
    {
        $self = static::create();
        // displayFields has no getter that works without a GridField; protected access is legal here because
        // GridFieldDataColumns declares it and this class extends it.
        $self->setDisplayFields($source->displayFields)
            ->setFieldCasting($source->getFieldCasting())
            ->setFieldFormatting($source->getFieldFormatting())
            ->setDoEscapeFields($source->getDoEscapeFields())
            ->setDisplayStatusFlags($source->getDisplayStatusFlags())
            ->setColumnsForStatusFlag($source->getColumnsForStatusFlag());

        return $self;
    }

    public function addEditor(InlineEditorInterface ...$editors): static
    {
        foreach ($editors as $editor) {
            $this->editors[$editor->getColumn()] = $editor;
        }

        return $this;
    }

    public function getEditor(string $column): ?InlineEditorInterface
    {
        return $this->editors[$column] ?? null;
    }

    /** Time the conflict toast is readable before the row is refreshed (clamped to 0-10s). */
    public function setConflictPause(int $milliseconds): static
    {
        $this->conflictPauseMs = max(0, min(10_000, $milliseconds));

        return $this;
    }

    // ---------------------------------------------------------------- GridField_ColumnProvider (delegating)

    #[\Override]
    public function getColumnContent($gridField, $record, $columnName)
    {
        $content = parent::getColumnContent($gridField, $record, $columnName);
        $editor = $this->editors[$columnName] ?? null;

        if ($editor === null || !$this->isEditable($gridField, $record)) {
            return $content;
        }

        return $editor->renderCell($gridField, $record, (string) $content);
    }

    #[\Override]
    public function getColumnAttributes($gridField, $record, $columnName): array
    {
        $attributes = parent::getColumnAttributes($gridField, $record, $columnName);
        // Every handled cell is addressable so refreshed cells can be swapped in by column name.
        $attributes['data-ywli-column'] = (string) $columnName;

        $editor = $this->editors[$columnName] ?? null;
        if ($editor === null || !$this->isEditable($gridField, $record)) {
            return $attributes;
        }

        return [
            ...$editor->getCellAttributes($gridField, $record),
            ...$attributes,
            'class' => trim(($attributes['class'] ?? '') . ' ywli-editable ywli-editable--' . $editor->getType()),
            'data-ywli-editor' => $editor->getType(),
            'data-ywli-id' => (string) $record->ID,
            'data-ywli-etag' => $this->computeEtag($record),
        ];
    }

    // ---------------------------------------------------------------- GridField_HTMLProvider (client mount)

    #[\Override]
    public function getHTMLFragments($gridField): array
    {
        if ($this->editors === []) {
            return [];
        }

        $config = [
            'patchUrl' => $gridField->Link('ywli/inline/patch'),
            'optionsUrl' => $gridField->Link('ywli/inline/options'),
            'securityID' => SecurityToken::inst()->getValue(),
            'conflictPauseMs' => $this->conflictPauseMs,
            'editors' => array_map(
                static fn (InlineEditorInterface $editor): array => ['type' => $editor->getType()] + $editor->getClientSchema(),
                $this->editors
            ),
            'strings' => [
                'conflict' => $this->t('CONFLICT', 'Conflict: record modified by another user.'),
                'saveFailed' => $this->t('SAVE_FAILED', 'Could not save the change.'),
                'notFound' => $this->t('NOT_FOUND', 'This record no longer exists.'),
                'loadFailed' => $this->t('LOAD_FAILED', 'Could not load the choices.'),
                'undone' => $this->t('UNDONE', 'Change undone.'),
            ],
        ];

        // Marker element is the Entwine mount point: it appears and disappears with every GridField reload.
        return [
            'before' => HTML::createTag('span', [
                'class' => 'ywli-marker',
                'hidden' => true,
                'data-ywli-feature' => self::FEATURE,
                'data-ywli-config' => json_encode($config, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            ]),
        ];
    }

    // ---------------------------------------------------------------- GridField_URLHandler

    #[\Override]
    public function getURLHandlers($gridField): array
    {
        return [
            'POST ywli/inline/patch' => 'handlePatch',
            'GET ywli/inline/options/$Column' => 'handleOptions',
        ];
    }

    public function handlePatch(GridField $gridField, HTTPRequest $request): HTTPResponse
    {
        if (!SecurityToken::inst()->checkRequest($request)) {
            return self::jsonError('csrf', $this->t('CSRF', 'Security token expired. Reload the page and try again.'), 403);
        }

        try {
            $body = json_decode($request->getBody(), true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return self::jsonError('bad_request', 'Malformed JSON body.', 400);
        }

        $id = is_array($body) ? filter_var($body['id'] ?? null, FILTER_VALIDATE_INT) : false;
        $changes = is_array($body) ? ($body['changes'] ?? null) : null;
        $etag = is_array($body) ? ($body['etag'] ?? null) : null;

        if ($id === false || $id < 1 || !is_array($changes) || $changes === [] || array_is_list($changes)
            || !is_string($etag) || $etag === ''
        ) {
            return self::jsonError('bad_request', 'Expected {id, etag, changes: {column: value}}.', 400);
        }

        foreach (array_keys($changes) as $column) {
            if (!isset($this->editors[(string) $column])) {
                return self::jsonError('column_not_editable', sprintf('Column "%s" is not inline-editable.', $column), 422);
            }
        }

        $member = Security::getCurrentUser();
        $record = $this->findEditableRecord($gridField, $id, $member, $notFoundOrForbidden);
        if ($record === null) {
            return $notFoundOrForbidden;
        }

        if (!hash_equals($this->computeEtag($record), $etag)) {
            return self::json([
                'ok' => false,
                'error' => 'conflict',
                'message' => $this->t('CONFLICT', 'Conflict: record modified by another user.'),
            ] + $this->rowPayload($gridField, $record), 409);
        }

        try {
            // Database::withTransaction() discards the callback's return value, so results leave via reference.
            $previous = $current = [];
            DB::get_conn()->withTransaction(function () use ($record, $changes, $member, &$previous, &$current): void {
                [$previous, $current] = $this->applyChanges($record, $changes, $member);
            });
        } catch (InvalidArgumentException | ValidationException $e) {
            return self::jsonError('invalid', $e->getMessage(), 422);
        } catch (Throwable $e) {
            Injector::inst()->get(LoggerInterface::class)->error($e->getMessage(), ['exception' => $e]);

            return self::jsonError('server', $this->t('SAVE_FAILED', 'Could not save the change.'), 500);
        }

        // Report what was actually stored (the database may normalise values), not what was assigned in memory.
        $fresh = $this->reloaded($gridField, $record);
        $stored = $this->readAll($fresh);
        foreach (array_keys($current) as $column) {
            $current[$column] = $stored[$column];
        }
        $payload = $this->rowPayload($gridField, $fresh);

        $undo = null;
        $undoManager = $gridField->getConfig()->getComponentByType(GridFieldUndoManager::class);
        if ($previous !== [] && $undoManager instanceof GridFieldUndoManager) {
            $undo = $undoManager->remember($gridField, $request, $record, $previous, $payload['etag'], $member);
        }

        return self::json([
            'ok' => true,
            'changes' => $current,
            'previous' => $previous,
            'undo' => $undo,
        ] + $payload);
    }

    public function handleOptions(GridField $gridField, HTTPRequest $request): HTTPResponse
    {
        $editor = $this->editors[(string) $request->param('Column')] ?? null;
        if (!$editor instanceof OptionsEditorInterface) {
            return self::jsonError('column_not_editable', 'Column has no options.', 422);
        }

        $id = filter_var($request->getVar('id'), FILTER_VALIDATE_INT);
        $record = $id === false ? null : $this->findEditableRecord($gridField, $id, Security::getCurrentUser(), $error);
        if ($record === null) {
            return $error ?? self::jsonError('bad_request', 'Expected ?id=<record id>.', 400);
        }

        return self::json(['ok' => true, 'options' => $editor->getOptions($record)]);
    }

    /**
     * Restore the values stored in an undo memento. Called by GridFieldUndoManager after it consumed the token.
     */
    public function revert(GridField $gridField, UndoMemento $memento, ?Member $member): HTTPResponse
    {
        $record = $this->findEditableRecord($gridField, $memento->recordID, $member, $error);
        if ($record === null) {
            return $error;
        }

        if ($record::class !== $memento->recordClass || !hash_equals($this->computeEtag($record), $memento->etag)) {
            return self::json([
                'ok' => false,
                'error' => 'conflict',
                'message' => $this->t('UNDO_CONFLICT', 'Cannot undo: the record was changed in the meantime.'),
            ] + $this->rowPayload($gridField, $record), 409);
        }

        try {
            // extend() takes its arguments by reference, which a readonly property cannot satisfy: use a local copy.
            $restored = $memento->previous;
            DB::get_conn()->withTransaction(function () use ($record, &$restored, $member): void {
                foreach ($restored as $column => $value) {
                    $editor = $this->editors[(string) $column] ?? null;
                    if ($editor === null) {
                        continue; // editor was removed from the config since; leave that column alone
                    }
                    $editor->assign($record, $value);
                }
                $record->write();
                $record->extend('onAfterInlineEditUndo', $restored, $member);
            });
        } catch (InvalidArgumentException | ValidationException $e) {
            return self::jsonError('invalid', $e->getMessage(), 422);
        } catch (Throwable $e) {
            Injector::inst()->get(LoggerInterface::class)->error($e->getMessage(), ['exception' => $e]);

            return self::jsonError('server', $this->t('SAVE_FAILED', 'Could not save the change.'), 500);
        }

        return self::json(['ok' => true, 'changes' => $memento->previous, 'previous' => [], 'undo' => null]
            + $this->rowPayload($gridField, $this->reloaded($gridField, $record)));
    }

    public static function jsonError(string $code, string $message, int $status): HTTPResponse
    {
        return self::json(['ok' => false, 'error' => $code, 'message' => $message], $status);
    }

    // ---------------------------------------------------------------- internals

    /**
     * Record lookup scoped to this GridField's list (unmanipulated, so pagination cannot hide the row) with the
     * edit permission check. On failure returns null and sets $error to the response to send.
     */
    private function findEditableRecord(GridField $gridField, int $id, ?Member $member, ?HTTPResponse &$error): ?DataObject
    {
        $error = null;

        if ($id < 1) {
            $error = self::jsonError('bad_request', 'Invalid id.', 400);

            return null;
        }

        $record = $gridField->getList()->byID($id);
        if (!$record instanceof DataObject) {
            $error = self::jsonError('not_found', $this->t('NOT_FOUND', 'This record no longer exists.'), 404);

            return null;
        }

        if ($gridField->isReadonly() || $gridField->isDisabled() || !$record->canEdit($member)) {
            $error = self::jsonError('forbidden', $this->t('FORBIDDEN', 'You are not allowed to edit this record.'), 403);

            return null;
        }

        return $record;
    }

    /**
     * Assign, reconcile dependents, write. Returns the columns whose value actually changed.
     *
     * @param array<array-key, mixed> $changes
     * @return array{0: array<string, mixed>, 1: array<string, mixed>} [previous, current]
     */
    private function applyChanges(DataObject $record, array $changes, ?Member $member): array
    {
        $before = $this->readAll($record);

        // Parents before dependents: a dependent's options are evaluated against the already-assigned parent value,
        // so the order of keys in the request body must not matter.
        $explicit = [];
        foreach ($this->dependencyOrder(array_map('strval', array_keys($changes))) as $column) {
            $editor = $this->editors[$column];
            $editor->assign($record, $editor->coerce($changes[$column], $record));
            $explicit[$column] = true;
        }

        // Dependent selects: clear (or reject) values that no longer fit the changed parent. Iterate so chains
        // (C depends on B depends on A) settle regardless of registration order.
        $touched = $explicit;
        for ($pass = 0; $pass < 5; $pass++) {
            $modified = false;
            foreach ($this->editors as $column => $editor) {
                if (!$editor instanceof DependentEditorInterface) {
                    continue;
                }
                if (!isset($touched[$column]) && !isset($touched[$editor->getDependsOn()])) {
                    continue;
                }
                if ($editor->reconcile($record, isset($explicit[$column]))) {
                    $touched[$column] = true;
                    $modified = true;
                }
            }
            if (!$modified) {
                break;
            }
        }

        $after = $this->readAll($record);
        $previous = [];
        $current = [];
        foreach ($after as $column => $value) {
            if ($value !== $before[$column]) {
                $previous[$column] = $before[$column];
                $current[$column] = $value;
            }
        }

        if ($current !== []) {
            // Versioned records are written to the current stage (draft in the CMS); publishing stays explicit.
            $record->write();
            $record->extend('onAfterInlineEdit', $current, $previous, $member);
        }

        return [$previous, $current];
    }

    /**
     * Stable sort by dependency depth (plain editors 0, a dependent of a plain editor 1, ...).
     *
     * @param list<string> $columns
     * @return list<string>
     */
    private function dependencyOrder(array $columns): array
    {
        $depth = function (string $column, int $level = 0) use (&$depth): int {
            $editor = $this->editors[$column] ?? null;
            if (!$editor instanceof DependentEditorInterface || $level > 5) {
                return 0;
            }

            return 1 + $depth($editor->getDependsOn(), $level + 1);
        };

        $indexed = array_map(static fn (string $column, int $i): array => [$column, $i], $columns, array_keys($columns));
        usort($indexed, static fn (array $a, array $b): int => [$depth($a[0]), $a[1]] <=> [$depth($b[0]), $b[1]]);

        return array_column($indexed, 0);
    }

    /**
     * Fresh copy from the database, so payload etag/cells are computed from the same state the next request will load.
     */
    private function reloaded(GridField $gridField, DataObject $record): DataObject
    {
        $fresh = $gridField->getList()->byID($record->ID);

        return $fresh instanceof DataObject ? $fresh : $record;
    }

    /** @return array<string, mixed> */
    private function readAll(DataObject $record): array
    {
        return array_map(
            static fn (InlineEditorInterface $editor): mixed => $editor->readValue($record),
            $this->editors
        );
    }

    private function isEditable(GridField $gridField, mixed $record): bool
    {
        return $record instanceof DataObject
            && !$gridField->isReadonly()
            && !$gridField->isDisabled()
            && $record->canEdit();
    }

    /**
     * Optimistic-concurrency token. LastEdited has 1s resolution, so the editable values are hashed in too:
     * two edits to the same cell inside one second still conflict. Best effort: there is no row lock between
     * this check and the write.
     */
    private function computeEtag(DataObject $record): string
    {
        // Everything is cast to string: the same record yields int/string variants depending on whether it was just
        // written or freshly loaded, and the etag must be identical either way.
        $state = [
            $record::class,
            (string) $record->ID,
            (string) $record->getField('LastEdited'),
            (string) $record->getField('Version'),
            $this->readAll($record),
        ];

        return sha1(json_encode($state, JSON_THROW_ON_ERROR));
    }

    /** @return array{id: int, etag: string, cells: array<string, string>} */
    private function rowPayload(GridField $gridField, DataObject $record): array
    {
        $cells = [];
        foreach ($this->getColumnsHandled($gridField) as $column) {
            $cells[$column] = (string) $this->getColumnContent($gridField, $record, $column);
        }

        return ['id' => (int) $record->ID, 'etag' => $this->computeEtag($record), 'cells' => $cells];
    }

    public static function json(array $data, int $status = 200): HTTPResponse
    {
        $flags = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;

        return HTTPResponse::create(json_encode($data, $flags), $status)
            ->addHeader('Content-Type', 'application/json; charset=utf-8')
            ->addHeader('Cache-Control', 'no-store');
    }

    private function t(string $key, string $default): string
    {
        return _t(self::class . '.' . $key, $default);
    }
}
