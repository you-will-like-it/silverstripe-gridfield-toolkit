<?php

declare(strict_types=1);

namespace YouWillLikeIT\GridFieldToolkit\Component\InlineEdit\Editor;

use Closure;
use InvalidArgumentException;
use SilverStripe\Core\Convert;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\ORM\DataObject;
use YouWillLikeIT\GridFieldToolkit\Contract\DependentEditorInterface;

/**
 * Select whose options depend on another column of the same record (e.g. City by Country).
 *
 * Server-authoritative: whenever the parent column changes, a child value that is no longer a valid option is
 * cleared to $emptyValue inside the same transaction, and the refreshed cells are returned to the client.
 * The child column should therefore accept the empty value.
 *
 * Usage: new DependentSelectEditor('City', 'Country', fn (string $country, DataObject $r) => City::forCountry($country))
 */
final class DependentSelectEditor extends SelectEditor implements DependentEditorInterface
{
    /**
     * @param Closure(string, DataObject): array<string|int, string> $optionsFor Receives the current parent value
     */
    public function __construct(
        string $column,
        private readonly string $dependsOn,
        Closure $optionsFor,
        bool $allowEmpty = true,
        string $emptyLabel = '',
        string|int|null $emptyValue = '',
    ) {
        parent::__construct(
            $column,
            static fn (DataObject $record): array => $optionsFor((string) ($record->getField($dependsOn) ?? ''), $record),
            $allowEmpty,
            $emptyLabel,
            $emptyValue,
        );
    }

    #[\Override]
    public function getDependsOn(): string
    {
        return $this->dependsOn;
    }

    #[\Override]
    public function getClientSchema(): array
    {
        return ['dynamic' => true, 'dependsOn' => $this->dependsOn];
    }

    #[\Override]
    public function renderCell(GridField $gridField, DataObject $record, string $displayHtml): string
    {
        $value = $this->readValue($record);
        if (($value === ''  || $value === null || $value === $this->emptyValue || $value == 0) && $this->allowEmpty && $this->emptyLabel !== '') {
            $displayHtml = Convert::raw2xml($this->emptyLabel);
        } elseif ($value !== '') {
            foreach ($this->getOptions($record) as $option) {
                if ($option['value'] === $value) {
                    $displayHtml = Convert::raw2xml($option['label']);
                    break;
                }
            }
        }

        return parent::renderCell($gridField, $record, $displayHtml);
    }

    #[\Override]
    public function reconcile(DataObject $record, bool $explicit): bool
    {
        $current = $this->readValue($record);
        foreach ($this->getOptions($record) as $option) {
            if ($option['value'] === $current) {
                return false;
            }
        }

        if ($explicit) {
            throw new InvalidArgumentException(_t(
                self::class . '.INVALID_FOR_PARENT',
                'This choice is not available for the current {parent} value.',
                ['parent' => $this->dependsOn]
            ));
        }

        if ($current === '') {
            return false;
        }

        $this->assign($record, '');

        return true;
    }
}
