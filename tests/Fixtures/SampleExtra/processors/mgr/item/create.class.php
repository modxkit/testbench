<?php

/*
 * An extra's processor in the form real MODX 3 extras write it: a file under
 * `{processors_path}/{action}.class.php` that returns the name of its own class. It is exactly such
 * a processor that is NOT found by a string action when there is nowhere to pass
 * `$options['processors_path']` — the core looks for it relative to ITS OWN processors_path.
 *
 * The directory is excluded from cs-fixer and Rector together with the whole of `tests/Fixtures`:
 * this is a consumer's sample code, not code of the package.
 */

use MODX\Revolution\Processors\Processor;

class SampleExtraItemCreateProcessor extends Processor
{
    public function process()
    {
        $name = $this->getProperty('name', '');
        $name = is_string($name) ? trim($name) : '';

        if ($name === '') {
            return $this->failure('sampleextra.name_required');
        }

        return $this->success('', ['name' => $name]);
    }
}

return 'SampleExtraItemCreateProcessor';
