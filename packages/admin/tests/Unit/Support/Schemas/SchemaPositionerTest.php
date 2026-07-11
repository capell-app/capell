<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Unit\Support\Schemas;

use Capell\Admin\Support\Schemas\SchemaPositioner;
use Filament\Forms\Components\TextInput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/** @extends TestCase<MockObject> */
final class SchemaPositionerTest extends TestCase
{
    public function test_append_adds_component_at_end(): void
    {
        $first = TextInput::make('first')->key('first');
        $second = TextInput::make('second')->key('second');
        $toAppend = TextInput::make('third')->key('third');

        $result = SchemaPositioner::append([$first, $second], $toAppend);

        $this->assertCount(3, $result);
        $this->assertSame($toAppend, $result[2]);
    }

    public function test_prepend_adds_component_at_start(): void
    {
        $first = TextInput::make('first')->key('first');
        $second = TextInput::make('second')->key('second');
        $toPrepend = TextInput::make('zero')->key('zero');

        $result = SchemaPositioner::prepend([$first, $second], $toPrepend);

        $this->assertCount(3, $result);
        $this->assertSame($toPrepend, $result[0]);
        $this->assertSame($first, $result[1]);
    }

    public function test_insert_after_places_after_matching_key(): void
    {
        $first = TextInput::make('first')->key('first');
        $second = TextInput::make('second')->key('second');
        $third = TextInput::make('third')->key('third');
        $toInsert = TextInput::make('inserted')->key('inserted');

        $result = SchemaPositioner::insertAfter([$first, $second, $third], $toInsert, 'second');

        $this->assertCount(4, $result);
        $this->assertSame($second, $result[1]);
        $this->assertSame($toInsert, $result[2]);
        $this->assertSame($third, $result[3]);
    }

    public function test_insert_before_places_before_matching_key(): void
    {
        $first = TextInput::make('first')->key('first');
        $second = TextInput::make('second')->key('second');
        $third = TextInput::make('third')->key('third');
        $toInsert = TextInput::make('inserted')->key('inserted');

        $result = SchemaPositioner::insertBefore([$first, $second, $third], $toInsert, 'second');

        $this->assertCount(4, $result);
        $this->assertSame($first, $result[0]);
        $this->assertSame($toInsert, $result[1]);
        $this->assertSame($second, $result[2]);
    }

    public function test_insert_after_falls_back_to_append_when_key_not_found(): void
    {
        $first = TextInput::make('first')->key('first');
        $second = TextInput::make('second')->key('second');
        $toInsert = TextInput::make('inserted')->key('inserted');

        $result = SchemaPositioner::insertAfter([$first, $second], $toInsert, 'nonexistent');

        $this->assertCount(3, $result);
        $this->assertSame($toInsert, $result[2]);
    }

    public function test_insert_before_falls_back_to_append_when_key_not_found(): void
    {
        $first = TextInput::make('first')->key('first');
        $second = TextInput::make('second')->key('second');
        $toInsert = TextInput::make('inserted')->key('inserted');

        $result = SchemaPositioner::insertBefore([$first, $second], $toInsert, 'nonexistent');

        $this->assertCount(3, $result);
        $this->assertSame($toInsert, $result[2]);
    }

    public function test_operations_work_with_empty_array(): void
    {
        $toAdd = TextInput::make('only')->key('only');

        $resultAppend = SchemaPositioner::append([], $toAdd);
        $resultPrepend = SchemaPositioner::prepend([], $toAdd);
        $resultInsertAfter = SchemaPositioner::insertAfter([], $toAdd, 'key');
        $resultInsertBefore = SchemaPositioner::insertBefore([], $toAdd, 'key');

        $this->assertCount(1, $resultAppend);
        $this->assertCount(1, $resultPrepend);
        $this->assertCount(1, $resultInsertAfter);
        $this->assertCount(1, $resultInsertBefore);
    }

    public function test_insert_after_ignores_component_with_null_key(): void
    {
        $withKey = TextInput::make('with')->key('with');
        $withoutKey = TextInput::make('without'); // no key() call
        $toInsert = TextInput::make('inserted')->key('inserted');

        $result = SchemaPositioner::insertAfter([$withKey, $withoutKey], $toInsert, 'without');

        // null key does not match, falls back to append
        $this->assertCount(3, $result);
        $this->assertSame($toInsert, $result[2]);
    }
}
