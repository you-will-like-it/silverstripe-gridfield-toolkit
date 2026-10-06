<?php

declare(strict_types=1);

namespace YouWillLikeIT\GridFieldToolkit\Support;

use SilverStripe\View\HTML;

/** The hidden element each feature renders so the client can mount it (recreated on every GridField reload). */
final class ClientMarker
{
    /** @param array<string, mixed> $config */
    public static function html(string $feature, array $config = []): string
    {
        return HTML::createTag('span', [
            'class' => 'ywli-marker',
            'hidden' => true,
            'data-ywli-feature' => $feature,
            'data-ywli-config' => json_encode($config, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        ]);
    }
}
