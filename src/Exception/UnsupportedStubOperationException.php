<?php

declare(strict_types=1);

namespace ModxKit\Testbench\Exception;

final class UnsupportedStubOperationException extends TestbenchException
{
    public static function forMethod(string $method): self
    {
        return new self(sprintf(
            'The core stub does not support %s(). The stub level covers the API partially on purpose: '
            . 'to work with real data use the integration ModxKit\\Testbench\\TestCase, and for prepared '
            . 'objects use TestbenchModx::seed().',
            $method
        ));
    }

    public static function forConstructor(): self
    {
        return new self(
            'The core stub cannot be created with a constructor: TestbenchModx::create() builds it '
            . 'without calling the modX/xPDO constructor, because the real constructor needs an '
            . 'installed CMS and a database connection. Level 1 receives a ready stub in '
            . '$this->modx (ModxKit\\Testbench\\Unit\\UnitTestCase), while a test that needs the real '
            . 'core needs the integration ModxKit\\Testbench\\TestCase.'
        );
    }

    public static function forSeededObject(string $class, string $returned): self
    {
        return new self(sprintf(
            'The seeded object %s returned %s from toArray() instead of an array, so there is nothing '
            . 'to check it against the criteria with. That is how a REAL model object behaves when it '
            . 'is created without a loaded xPDO model: level 1 needs a plain double (an object with '
            . 'fields or with its own toArray()), and a test of the model itself is the integration '
            . 'ModxKit\\Testbench\\TestCase.',
            $class,
            $returned
        ));
    }

    public static function forCriteria(string $type): self
    {
        return new self(sprintf(
            'The core stub does not understand a criterion of type %s: it has nothing to build SQL '
            . 'from — it has neither a database connection nor a class map. At level 1 a criterion is '
            . 'an array of fields (`[\'name\' => \'nightly\']`) or a scalar (the `id` primary key); a '
            . 'query object (xPDOQuery, xPDOCriteria) is supported only by the integration '
            . 'ModxKit\\Testbench\\TestCase.',
            $type
        ));
    }
}
