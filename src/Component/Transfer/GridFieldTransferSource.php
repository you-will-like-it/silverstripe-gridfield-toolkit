<?php

declare(strict_types=1);

namespace YouWillLikeIT\GridFieldToolkit\Component\Transfer;

use SilverStripe\Forms\GridField\AbstractGridFieldComponent;
use SilverStripe\Forms\GridField\GridField_HTMLProvider;
use YouWillLikeIT\GridFieldToolkit\Support\ClientMarker;

/** Makes the rows of this grid draggable onto a GridFieldTransferTarget grid (moves with the stash selection when the row is part of it). */
class GridFieldTransferSource extends AbstractGridFieldComponent implements GridField_HTMLProvider
{
    public const FEATURE = 'transfer-source';

    #[\Override]
    public function getHTMLFragments($gridField): array
    {
        return ['before' => ClientMarker::html(self::FEATURE, [])];
    }
}
