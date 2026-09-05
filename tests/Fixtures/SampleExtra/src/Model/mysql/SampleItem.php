<?php

declare(strict_types=1);

namespace SampleExtra\Model\mysql;

class SampleItem extends \SampleExtra\Model\SampleItem
{
    /** @var array<string, mixed> */
    public static $metaMap = [
        'package' => 'SampleExtra\Model',
        'version' => '3.0',
        'table' => 'sample_items',
        'extends' => 'xPDO\Om\xPDOSimpleObject',
        'tableMeta' => [
            'engine' => 'InnoDB',
        ],
        'fields' => [
            'name' => null,
            'quantity' => 0,
        ],
        'fieldMeta' => [
            'name' => [
                'dbtype' => 'varchar',
                'precision' => '100',
                'phptype' => 'string',
                'null' => false,
                'default' => '',
            ],
            'quantity' => [
                'dbtype' => 'int',
                'precision' => '10',
                'attributes' => 'unsigned',
                'phptype' => 'integer',
                'null' => false,
                'default' => 0,
            ],
        ],
    ];
}
