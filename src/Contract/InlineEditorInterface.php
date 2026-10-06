<?php

declare(strict_types=1);

namespace YouWillLikeIT\GridFieldToolkit\Contract;

use InvalidArgumentException;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\ORM\DataObject;

/**
 * Strategy for one inline-editable column. Registered on GridFieldInlineEdit, which owns the column
 * (it extends GridFieldDataColumns, so base rendering/casting is delegated via parent::).
 */
interface InlineEditorInterface
{
    /** Display-field name this editor owns. Plain DB field (or has-one "FooID"); relation paths are not supported. */
    public function getColumn(): string;

    /** Client-side editor key; emitted as data-ywli-editor and used for JS dispatch (toggle|text|number|select). */
    public function getType(): string;

    /** JSON-serialisable, editor-specific client config (merged into the mount config). */
    public function getClientSchema(): array;

    /** Extra <td> attributes. `class` and `data-ywli-*` keys set by the component win on collision. */
    public function getCellAttributes(GridField $gridField, DataObject $record): array;

    /**
     * Cell HTML for an editable record.
     *
     * @param string $displayHtml Already cast/formatted/escaped output of GridFieldDataColumns
     */
    public function renderCell(GridField $gridField, DataObject $record, string $displayHtml): string;

    /** Current value, JSON-serialisable and type-stable. Also feeds the etag. */
    public function readValue(DataObject $record): mixed;

    /**
     * Normalise and validate untrusted client input.
     *
     * @throws InvalidArgumentException Message is returned to the client (HTTP 422)
     */
    public function coerce(mixed $raw, DataObject $record): mixed;

    /** Apply a coerced value to the record. The caller writes. */
    public function assign(DataObject $record, mixed $value): void;
}
