<?php

declare(strict_types=1);

namespace YouWillLikeIT\GridFieldToolkit\Tests\Fixtures;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

class ToolkitRecord extends DataObject implements TestOnly
{
    private static string $table_name = 'YWLI_ToolkitRecord';

    private static array $db = [
        'Title' => 'Varchar(20)',
        'IsActive' => 'Boolean',
        'Qty' => 'Int',
        'Price' => 'Decimal(8,2)',
        'Status' => "Enum('a,b,c','a')",
        'Country' => 'Varchar(2)',
        'City' => 'Varchar(50)',
    ];

    private static array $summary_fields = ['Title', 'IsActive', 'Qty', 'Price', 'Status', 'Country', 'City'];

    /** Stand-in for a has_many relation (nested relation tests). */
    public function Siblings(): \SilverStripe\ORM\DataList
    {
        return ToolkitRecord::get()->exclude('ID', $this->ID);
    }
}
