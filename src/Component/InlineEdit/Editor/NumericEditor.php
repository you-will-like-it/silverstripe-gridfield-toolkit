<?php

declare(strict_types=1);

namespace YouWillLikeIT\GridFieldToolkit\Component\InlineEdit\Editor;

use InvalidArgumentException;
use LogicException;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBDecimal;
use SilverStripe\ORM\FieldType\DBFloat;
use SilverStripe\ORM\FieldType\DBInt;
use SilverStripe\View\HTML;
use YouWillLikeIT\GridFieldToolkit\Contract\InlineEditorInterface;

/**
 * Int / Decimal / Float editor. Integer columns reject fractions, Decimal columns are rounded to their scale.
 * Input is locale-independent (<input type=number> always submits a dot-decimal string).
 */
final class NumericEditor implements InlineEditorInterface
{
    public function __construct(
        private readonly string $column,
        private readonly int|float|null $min = null,
        private readonly int|float|null $max = null,
        private readonly int|float|null $step = null,
        private readonly bool $allowNull = false,
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
        return 'number';
    }

    #[\Override]
    public function getClientSchema(): array
    {
        return ['min' => $this->min, 'max' => $this->max, 'allowNull' => $this->allowNull];
    }

    #[\Override]
    public function getCellAttributes(GridField $gridField, DataObject $record): array
    {
        $step = $this->step ?? match (true) {
            $this->isInteger($record) => 1,
            ($scale = $this->scale($record)) !== null => 10 ** -$scale,
            default => 'any',
        };

        return ['data-ywli-step' => (string) $step];
    }

    #[\Override]
    public function renderCell(GridField $gridField, DataObject $record, string $displayHtml): string
    {
        return HTML::createTag('span', [
            'class' => 'ywli-value',
            'tabindex' => '0',
            'title' => _t(self::class . '.HINT', 'Double-click or press Enter to edit'),
            'data-ywli-value' => (string) ($this->readValue($record) ?? ''),
        ], $displayHtml);
    }

    #[\Override]
    public function readValue(DataObject $record): int|float|null
    {
        $raw = $record->getField($this->column);
        if ($raw === null || $raw === '') {
            return null;
        }

        return $this->isInteger($record) ? (int) $raw : (float) $raw;
    }

    #[\Override]
    public function coerce(mixed $raw, DataObject $record): int|float|null
    {
        if ($raw === null || (is_string($raw) && trim($raw) === '')) {
            return $this->allowNull
                ? null
                : throw new InvalidArgumentException(_t(self::class . '.REQUIRED', 'A number is required.'));
        }

        if (!is_int($raw) && !is_float($raw) && !(is_string($raw) && is_numeric($raw))) {
            throw new InvalidArgumentException(_t(self::class . '.INVALID', 'Enter a valid number.'));
        }

        $number = $raw + 0;
        if (is_float($number) && !is_finite($number)) {
            throw new InvalidArgumentException(_t(self::class . '.INVALID', 'Enter a valid number.'));
        }

        if ($this->isInteger($record)) {
            if ($number != floor((float) $number)) {
                throw new InvalidArgumentException(_t(self::class . '.INTEGER', 'Enter a whole number.'));
            }
            $number = (int) $number;
        } elseif (($scale = $this->scale($record)) !== null) {
            $number = round((float) $number, $scale);
        } else {
            $number = (float) $number;
        }

        if ($this->min !== null && $number < $this->min) {
            throw new InvalidArgumentException(_t(
                self::class . '.MIN',
                'Minimum value is {min}.',
                ['min' => $this->min]
            ));
        }
        if ($this->max !== null && $number > $this->max) {
            throw new InvalidArgumentException(_t(
                self::class . '.MAX',
                'Maximum value is {max}.',
                ['max' => $this->max]
            ));
        }

        return $number;
    }

    #[\Override]
    public function assign(DataObject $record, mixed $value): void
    {
        $field = $record->dbObject($this->column);
        if (!$field instanceof DBInt && !$field instanceof DBDecimal && !$field instanceof DBFloat) {
            throw new LogicException(sprintf(
                '%s::%s is not an Int/Decimal/Float field, NumericEditor cannot edit it.',
                $record::class,
                $this->column
            ));
        }

        $record->setField($this->column, $value);
    }

    private function isInteger(DataObject $record): bool
    {
        return $record->dbObject($this->column) instanceof DBInt;
    }

    private function scale(DataObject $record): ?int
    {
        $field = $record->dbObject($this->column);

        return $field instanceof DBDecimal ? $field->getDecimalSize() : null;
    }
}
