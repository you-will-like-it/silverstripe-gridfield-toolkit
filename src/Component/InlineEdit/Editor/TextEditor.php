<?php

declare(strict_types=1);

namespace YouWillLikeIT\GridFieldToolkit\Component\InlineEdit\Editor;

use InvalidArgumentException;
use LogicException;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBHTMLText;
use SilverStripe\ORM\FieldType\DBHTMLVarchar;
use SilverStripe\ORM\FieldType\DBString;
use SilverStripe\ORM\FieldType\DBVarchar;
use SilverStripe\View\HTML;
use YouWillLikeIT\GridFieldToolkit\Contract\InlineEditorInterface;

/**
 * Plain-text editor for Varchar/Text columns. HTML fields are rejected on purpose: a text input would
 * silently strip or mangle markup.
 */
final class TextEditor implements InlineEditorInterface
{
    /**
     * @param int|null $maxLength Explicit limit; defaults to the Varchar size of the column
     * @param bool     $multiline Textarea instead of input (Ctrl/Cmd+Enter commits)
     * @param bool     $required  Reject empty values
     * @param bool     $trim      Trim surrounding whitespace before validating
     */
    public function __construct(
        private readonly string $column,
        private readonly ?int $maxLength = null,
        private readonly bool $multiline = false,
        private readonly bool $required = false,
        private readonly bool $trim = true,
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
        return 'text';
    }

    #[\Override]
    public function getClientSchema(): array
    {
        return ['multiline' => $this->multiline, 'required' => $this->required];
    }

    #[\Override]
    public function getCellAttributes(GridField $gridField, DataObject $record): array
    {
        $max = $this->effectiveMaxLength($record);

        return $max === null ? [] : ['data-ywli-maxlength' => (string) $max];
    }

    #[\Override]
    public function renderCell(GridField $gridField, DataObject $record, string $displayHtml): string
    {
        return HTML::createTag('span', [
            'class' => 'ywli-value',
            'tabindex' => '0',
            'title' => _t(self::class . '.HINT', 'Double-click or press Enter to edit'),
            'data-ywli-value' => $this->readValue($record),
        ], $displayHtml);
    }

    #[\Override]
    public function readValue(DataObject $record): string
    {
        return (string) ($record->getField($this->column) ?? '');
    }

    #[\Override]
    public function coerce(mixed $raw, DataObject $record): string
    {
        if ($raw !== null && !is_string($raw) && !is_int($raw) && !is_float($raw)) {
            throw new InvalidArgumentException(_t(self::class . '.INVALID', 'Invalid text value.'));
        }

        $value = (string) ($raw ?? '');
        if (!mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
            throw new InvalidArgumentException(_t(self::class . '.INVALID', 'Invalid text value.'));
        }

        $value = str_replace(["\r\n", "\r"], "\n", $value);
        if (!$this->multiline && str_contains($value, "\n")) {
            throw new InvalidArgumentException(_t(self::class . '.NO_NEWLINES', 'Line breaks are not allowed here.'));
        }

        if ($this->trim) {
            $value = trim($value);
        }

        if ($this->required && $value === '') {
            throw new InvalidArgumentException(_t(self::class . '.REQUIRED', 'This field is required.'));
        }

        $max = $this->effectiveMaxLength($record);
        if ($max !== null && mb_strlen($value) > $max) {
            throw new InvalidArgumentException(_t(
                self::class . '.TOO_LONG',
                'Maximum length is {max} characters.',
                ['max' => $max]
            ));
        }

        return $value;
    }

    #[\Override]
    public function assign(DataObject $record, mixed $value): void
    {
        $field = $record->dbObject($this->column);
        if (!$field instanceof DBString || $field instanceof DBHTMLText || $field instanceof DBHTMLVarchar) {
            throw new LogicException(sprintf(
                '%s::%s is not a plain Varchar/Text field, TextEditor cannot edit it.',
                $record::class,
                $this->column
            ));
        }

        $record->setField($this->column, $value);
    }

    private function effectiveMaxLength(DataObject $record): ?int
    {
        if ($this->maxLength !== null) {
            return $this->maxLength;
        }

        $field = $record->dbObject($this->column);

        return $field instanceof DBVarchar ? $field->getSize() : null;
    }
}
