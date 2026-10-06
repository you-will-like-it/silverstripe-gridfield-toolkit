<?php

declare(strict_types=1);

namespace YouWillLikeIT\GridFieldToolkit\Component\Layout;

use SilverStripe\Forms\GridField\AbstractGridFieldComponent;
use SilverStripe\Forms\GridField\GridField_HTMLProvider;
use YouWillLikeIT\GridFieldToolkit\Support\ClientMarker;

/**
 * Split view: clicking a row opens the record's standard edit form in a side panel next to the list instead of
 * navigating away. The panel is an iframe on the GridField's own `item/{id}` URL, so every field, permission, action
 * and validation of the stock GridFieldDetailForm keeps working. No endpoints. Requires GridFieldDetailForm.
 */
class GridFieldMasterDetail extends AbstractGridFieldComponent implements GridField_HTMLProvider
{
    public const FEATURE = 'master-detail';

    /** @param int $widthPercent Width of the detail panel as a share of the viewport (clamped to 30–80). */
    public function __construct(private readonly int $widthPercent = 50)
    {
    }

    public function getWidthPercent(): int
    {
        return max(30, min(80, $this->widthPercent));
    }

    #[\Override]
    public function getHTMLFragments($gridField): array
    {
        return [
            'before' => ClientMarker::html(self::FEATURE, [
                'width' => $this->getWidthPercent(),
                'strings' => [
                    'close' => _t(self::class . '.CLOSE', 'Close'),
                    'loading' => _t(self::class . '.LOADING', 'Loading…'),
                    'title' => _t(self::class . '.TITLE', 'Record details'),
                    'confirmLeave' => _t(self::class . '.CONFIRM_LEAVE', 'This record has unsaved changes. Discard them?'),
                ],
            ]),
        ];
    }
}
