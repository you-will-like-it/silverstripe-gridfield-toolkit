<?php

namespace YouWillLikeIT\GridFieldToolkit\Service;

use SilverStripe\Forms\GridField\GridField;

interface ZIPExporterInterface
{
    public function export(GridField $gridField, string $title = 'PDF Export', string $created = ''): string;
}