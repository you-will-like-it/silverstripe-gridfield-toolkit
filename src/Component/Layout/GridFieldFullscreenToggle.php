<?php

declare(strict_types=1);

namespace YouWillLikeIT\GridFieldToolkit\Component\Layout;

use SilverStripe\Forms\GridField\AbstractGridFieldComponent;
use SilverStripe\Forms\GridField\GridField_HTMLProvider;
use SilverStripe\View\HTML;
use YouWillLikeIT\GridFieldToolkit\Support\ClientMarker;

/**
 * Opt-in fullscreen mode. Pure client-side layout (CSS overlay + Esc to exit); no endpoints.
 * The button goes into the `buttons-before-right` fragment (rendered by GridFieldButtonRow); if the config has no
 * such row the client adds a floating button instead.
 */
class GridFieldFullscreenToggle extends AbstractGridFieldComponent implements GridField_HTMLProvider
{
    public const FEATURE = 'fullscreen';

    #[\Override]
    public function getHTMLFragments($gridField): array
    {
        $enter = _t(self::class . '.ENTER', 'Fullscreen');

        return [
            'buttons-before-right' => HTML::createTag('button', [
                'type' => 'button',
                'class' => 'btn btn-secondary ywli-fullscreen-toggle',
                'data-ywli-fullscreen-toggle' => true,
                'aria-pressed' => 'false',
            ], $enter),
            'before' => ClientMarker::html(self::FEATURE, [
                'strings' => [
                    'enter' => $enter,
                    'exit' => _t(self::class . '.EXIT', 'Exit fullscreen'),
                ],
            ]),
        ];
    }
}
