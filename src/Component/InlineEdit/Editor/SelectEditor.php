<?php

declare(strict_types=1);

namespace YouWillLikeIT\GridFieldToolkit\Component\InlineEdit\Editor;

use Closure;
use InvalidArgumentException;
use SilverStripe\Core\Convert;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\ORM\DataObject;
use SilverStripe\View\HTML;
use YouWillLikeIT\GridFieldToolkit\Contract\OptionsEditorInterface;

/**
 * Dropdown editor for Enum / Varchar / has-one ("FooID") columns.
 *
 * Options are either a static map value => label, or a Closure(DataObject): array<string|int, string>
 * evaluated per record on demand (fetched when the editor opens; the display value is then not mapped to a label,
 * to avoid evaluating the closure once per row).
 */
class SelectEditor implements OptionsEditorInterface
{
    /** @var array<string|int, string>|Closure(DataObject): array<string|int, string> */
    protected readonly array|Closure $options;

    /**
     * @param array<string|int, string>|Closure(DataObject): array<string|int, string> $options
     * @param bool                $allowEmpty Offer an empty choice
     * @param string              $emptyLabel Label of the empty choice
     * @param string|int|null     $emptyValue Value written to the record for the empty choice (0 for has-one IDs)
     */
    public function __construct(
        protected readonly string $column,
        array|Closure $options,
        protected readonly bool $allowEmpty = false,
        protected readonly string $emptyLabel = '',
        protected readonly string|int|null $emptyValue = '',
    ) {
        $this->options = $options;
    }

    #[\Override]
    public function getColumn(): string
    {
        return $this->column;
    }

    #[\Override]
    public function getType(): string
    {
        return 'select';
    }

    #[\Override]
    public function isStatic(): bool
    {
        return is_array($this->options);
    }

    #[\Override]
    public function getClientSchema(): array
    {
        return $this->isStatic()
            ? ['options' => $this->buildOptions($this->options)]
            : ['dynamic' => true];
    }

    #[\Override]
    public function getOptions(DataObject $record): array
    {
        $map = $this->options instanceof Closure ? ($this->options)($record) : $this->options;

        return $this->buildOptions($map);
    }

    #[\Override]
    public function getCellAttributes(GridField $gridField, DataObject $record): array
    {
        return [];
    }

    #[\Override]
    public function renderCell(GridField $gridField, DataObject $record, string $displayHtml): string
    {
        $value = $this->readValue($record);
        if (($value === ''  || $value === null || $value === $this->emptyValue || $value == 0) && $this->allowEmpty && $this->emptyLabel !== '') {
            $displayHtml = Convert::raw2xml($this->emptyLabel);
        } elseif ($this->isStatic()) {
            foreach ($this->buildOptions($this->options) as $option) {
                if ($option['value'] === $value && $value !== '') {
                    $displayHtml = Convert::raw2xml($option['label']);
                    break;
                }
            }
        }

        return HTML::createTag('span', [
            'class' => 'ywli-value',
            'tabindex' => '0',
            'title' => _t(self::class . '.HINT', 'Double-click or press Enter to edit'),
            'data-ywli-value' => $value,
        ], $displayHtml);
    }

    #[\Override]
    public function readValue(DataObject $record): string
    {
        $value = (string) ($record->getField($this->column) ?? '');

        return $value === (string) $this->emptyValue ? '' : $value;
    }

    #[\Override]
    public function coerce(mixed $raw, DataObject $record): string
    {
        if ($raw !== null && !is_string($raw) && !is_int($raw)) {
            throw new InvalidArgumentException(_t(self::class . '.INVALID', 'Invalid choice.'));
        }

        $value = (string) ($raw ?? '');
        foreach ($this->getOptions($record) as $option) {
            if ($option['value'] === $value) {
                return $value;
            }
        }

        throw new InvalidArgumentException(_t(self::class . '.INVALID', 'Invalid choice.'));
    }

    #[\Override]
    public function assign(DataObject $record, mixed $value): void
    {
        $record->setField($this->column, $value === '' ? $this->emptyValue : $value);
    }

    /**
     * @param array<string|int, string> $map
     * @return list<array{value: string, label: string}>
     */
    protected function buildOptions(array $map): array
    {
        $list = [];
        if ($this->allowEmpty) {
            $list[] = ['value' => '', 'label' => $this->emptyLabel];
        }
        foreach ($map as $value => $label) {
            $list[] = ['value' => (string) $value, 'label' => (string) $label];
        }

        return $list;
    }
}
