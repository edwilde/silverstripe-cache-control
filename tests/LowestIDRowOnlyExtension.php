<?php

namespace Edwilde\CacheControl\Tests;

use SilverStripe\Core\Extension;
use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DataQuery;
use SilverStripe\ORM\Queries\SQLSelect;
use SilverStripe\SiteConfig\SiteConfig;

/**
 * Stands in for the subsites module's SiteConfig filter: a query that does not filter on ID returns only the row with the lowest ID.
 */
class LowestIDRowOnlyExtension extends Extension implements TestOnly
{
    /**
     * Restrict a query that does not filter on ID to the row with the lowest ID.
     */
    public function augmentSQL(SQLSelect $query, ?DataQuery $dataQuery = null): void
    {
        if ($query->filtersOnID()) {
            return;
        }

        $schema = DataObject::getSchema();
        $table = $schema->baseDataTable(SiteConfig::class);
        $lowestID = (int)SQLSelect::create('MIN("ID")', '"' . $table . '"')->execute()->value();

        $query->addWhere([$schema->sqlColumnForField(SiteConfig::class, 'ID') . ' = ?' => $lowestID]);
    }
}
