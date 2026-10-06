<?php

declare(strict_types=1);

namespace YouWillLikeIT\GridFieldToolkit\Component\Link;

use SilverStripe\Forms\GridField\AbstractGridFieldComponent;
use SilverStripe\Forms\GridField\GridField_HTMLProvider;
use YouWillLikeIT\GridFieldToolkit\Support\ClientMarker;

/**
 * Master side of linked grids: clicking a row selects it and points the slave grids at that record's ID.
 * Pair with GridFieldLinkedFilter on each slave. The edit/view links of the row still navigate as usual.
 */
class GridFieldMasterSelect extends AbstractGridFieldComponent implements GridField_HTMLProvider
{
    public const FEATURE = 'master-select';

    /** @var list<string> */
    private readonly array $slaves;

    /** @param string ...$slaves Names of the slave GridFields (the name given to GridField::create()) */
    public function __construct(string ...$slaves)
    {
        $this->slaves = array_values($slaves);
    }

    /** @return list<string> */
    public function getSlaves(): array
    {
        return $this->slaves;
    }

    #[\Override]
    public function getHTMLFragments($gridField): array
    {
        return [
            'before' => ClientMarker::html(self::FEATURE, ['slaves' => $this->slaves]),
        ];
    }
}
