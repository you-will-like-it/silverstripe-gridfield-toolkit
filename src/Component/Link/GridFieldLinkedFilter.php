<?php

declare(strict_types=1);

namespace YouWillLikeIT\GridFieldToolkit\Component\Link;

use SilverStripe\Forms\GridField\AbstractGridFieldComponent;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridField_DataManipulator;
use SilverStripe\Forms\GridField\GridField_HTMLProvider;
use SilverStripe\Forms\GridField\GridField_StateProvider;
use SilverStripe\Forms\GridField\GridState_Data;
use SilverStripe\Model\List\SS_List;
use YouWillLikeIT\GridFieldToolkit\Support\ClientMarker;

/**
 * Slave side of linked grids: the list is narrowed to rows whose $filterField equals the master row's ID.
 *
 * The master ID lives in the slave's own GridState (`YWLILinkedFilter.MasterID`), like sort and page, so it survives
 * every reload, paging and sorting request without extra plumbing. It is only a filter value: it never widens what the
 * slave's own list and permissions already allow.
 */
class GridFieldLinkedFilter extends AbstractGridFieldComponent implements GridField_DataManipulator, GridField_StateProvider, GridField_HTMLProvider
{
    public const FEATURE = 'linked-filter';

    public const STATE_KEY = 'YWLILinkedFilter';

    /**
     * @param string $filterField e.g. 'CategoryID' (or any DataList filter key)
     * @param bool $emptyUntilSelected Show no rows until a master row is selected (otherwise the full list)
     */
    public function __construct(
        private readonly string $filterField,
        private readonly bool $emptyUntilSelected = true,
        private readonly string $masterLabel = '',
    ) {
    }

    #[\Override]
    public function initDefaultState(GridState_Data $data): void
    {
        $data->{self::STATE_KEY}->initDefaults(['MasterID' => 0]);
    }

    #[\Override]
    public function getManipulatedData(GridField $gridField, SS_List $dataList)
    {
        $id = (int) $gridField->State->{self::STATE_KEY}->getData('MasterID', 0);

        if ($id > 0) {
            return $dataList->filter($this->filterField, $id);
        }

        return $this->emptyUntilSelected ? $dataList->filter('ID', 0) : $dataList;
    }

    #[\Override]
    public function getHTMLFragments($gridField): array
    {
        return [
            'before' => ClientMarker::html(self::FEATURE, [
                'stateKey' => self::STATE_KEY,
                'emptyUntilSelected' => $this->emptyUntilSelected,
                'strings' => [
                    'hint' => $this->masterLabel !== ''
                        ? sprintf(_t(self::class . '.HINT_NAMED', 'Select a row in "%s" to show its records.'), $this->masterLabel)
                        : _t(self::class . '.HINT', 'Select a row in the master list to show its records.'),
                ],
            ]),
        ];
    }
}
