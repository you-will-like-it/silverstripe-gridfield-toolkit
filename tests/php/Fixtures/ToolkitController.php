<?php

declare(strict_types=1);

namespace YouWillLikeIT\GridFieldToolkit\Tests\Fixtures;

use SilverStripe\Control\Controller;
use SilverStripe\Dev\TestOnly;

/** Gives the test GridField a stable Link() without touching routing. */
class ToolkitController extends Controller implements TestOnly
{
    private static string $url_segment = 'ywli-test';
}
