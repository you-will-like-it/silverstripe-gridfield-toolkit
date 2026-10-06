<?php

declare(strict_types=1);

namespace YouWillLikeIT\GridFieldToolkit\Component\InlineEdit\Editor;

use InvalidArgumentException;
use LogicException;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBBoolean;
use SilverStripe\View\HTML;
use YouWillLikeIT\GridFieldToolkit\Contract\InlineEditorInterface;

/**
 * Boolean switch. Server-rendered <button role="switch">, so first paint works without JS.
 */
final class ToggleEditor implements InlineEditorInterface
{
    /**
     * @param string $column   DBBoolean field on the record
     * @param bool   $showText Render the cast display value (e.g. "Yes"/"No") next to the switch
     */
    public function __construct(
        private readonly string $column,
        private readonly bool $showText = false,
    ) {
    }

    #[\Override]
    public function getColumn(): string
    {
        return $this->column;
    }

    #[\Override]
    public function getType(): string
    {
        return 'toggle';
    }

    #[\Override]
    public function getClientSchema(): array
    {
        return [];
    }

    #[\Override]
    public function getCellAttributes(GridField $gridField, DataObject $record): array
    {
        return [];
    }

    #[\Override]
    public function renderCell(GridField $gridField, DataObject $record, string $displayHtml): string
    {
        $title = $gridField->getColumnMetadata($this->column)['title'] ?? null;

        $inner = '<span class="ywli-toggle__track" aria-hidden="true"><span class="ywli-toggle__thumb"></span></span>';
        if ($this->showText) {
            $inner .= HTML::createTag('span', ['class' => 'ywli-toggle__text'], $displayHtml);
        }

        return HTML::createTag('button', [
            'type' => 'button',
            'class' => 'ywli-toggle',
            'role' => 'switch',
            'aria-checked' => $this->readValue($record) ? 'true' : 'false',
            'aria-label' => (string) ($title ?: $this->column),
            // Value-less attribute: HTML::createTag drops empty-string attribute values.
            'data-ywli-toggle' => true,
        ], $inner);
    }

    #[\Override]
    public function readValue(DataObject $record): bool
    {
        return (bool) $record->getField($this->column);
    }

    #[\Override]
    public function coerce(mixed $raw, DataObject $record): bool
    {
        return match (true) {
            is_bool($raw) => $raw,
            $raw === 1, $raw === '1', $raw === 'true' => true,
            $raw === 0, $raw === '0', $raw === 'false' => false,
            default => throw new InvalidArgumentException(
                _t(self::class . '.INVALID', 'Invalid value for a toggle.')
            ),
        };
    }

    #[\Override]
    public function assign(DataObject $record, mixed $value): void
    {
        if (!$record->dbObject($this->column) instanceof DBBoolean) {
            // Misconfiguration, not user error: surfaces as a logged 500.
            throw new LogicException(sprintf(
                '%s::%s is not a DBBoolean field, ToggleEditor cannot edit it.',
                $record::class,
                $this->column
            ));
        }

        $record->setField($this->column, $value);
    }
}
