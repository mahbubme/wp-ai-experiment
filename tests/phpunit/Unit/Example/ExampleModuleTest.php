<?php

declare(strict_types=1);

namespace Mahbub\WpAiExperiment\Tests\Unit\Example;

use Mahbub\WpAiExperiment\Tests\AbstractTestCase;

class ExampleModuleTest extends AbstractTestCase
{
    public function testExampleShouldTrueBeSameAsTrue(): void
    {
        /** @phpstan-ignore method.alreadyNarrowedType */
        $this->assertSame(true, true);
    }
}
