<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Test\Unit\Model\Json;

use DmLab\TypeSafeCore\Model\Json\CanonicalJson;
use PHPUnit\Framework\TestCase;

class CanonicalJsonTest extends TestCase
{
    /**
     * @var CanonicalJson
     */
    private CanonicalJson $json;

    protected function setUp(): void
    {
        $this->json = new CanonicalJson();
    }

    public function testSortsAssocArraysRecursively(): void
    {
        $this->assertSame(
            '{"a":{"x":1,"y":2},"b":[3,1,2]}',
            $this->json->encode(['b' => [3, 1, 2], 'a' => ['y' => 2, 'x' => 1]])
        );
        $this->assertSame(
            $this->json->encode(['a' => ['x' => 1, 'y' => 2], 'b' => 1]),
            $this->json->encode(['b' => 1, 'a' => ['y' => 2, 'x' => 1]])
        );
    }

    public function testKeepsListOrder(): void
    {
        $this->assertNotSame($this->json->encode([1, 2]), $this->json->encode([2, 1]));
    }

    public function testStdClassIsATaggedOrderSignificantPairList(): void
    {
        $this->assertSame(
            '{"\\u0000":[["b",{"x":1,"y":2}],["0",null]]}',
            $this->json->encode((object)['b' => ['y' => 2, 'x' => 1], '0' => null])
        );
        $this->assertNotSame(
            $this->json->encode((object)['a' => null, 'b' => null]),
            $this->json->encode((object)['b' => null, 'a' => null])
        );
    }

    public function testMixedKeysSortAsStrings(): void
    {
        $this->assertSame('{"10":"c","9":"b","a":"a"}', $this->json->encode(['a' => 'a', 9 => 'b', 10 => 'c']));
    }

    /**
     * Different values must never share an encoding: they would share a cache key but send different bodies.
     */
    public function testDistinctShapesNeverCollide(): void
    {
        $this->assertNotSame(
            $this->json->encode(['x' => (object)['a' => 1]]),
            $this->json->encode(['x' => [['a', 1]]])
        );
        $this->assertNotSame($this->json->encode((object)[]), $this->json->encode([]));
        $this->assertNotSame(
            $this->json->encode((object)['a' => 1]),
            $this->json->encode(["\0" => [['a', 1]]])
        );
        $this->assertNotSame($this->json->encode([1 => 'a', 0 => 'b']), $this->json->encode(['b', 'a']));
    }

    public function testKeepsNulPrefixedKeys(): void
    {
        $this->assertSame('{"\\u0000\\u0000x":"red"}', $this->json->encode(["\0x" => 'red']));
        $this->assertNotSame($this->json->encode(["\0x" => 'red']), $this->json->encode(["\0x" => 'blue']));
    }

    public function testAssocArrayWithListKeysStaysAnObject(): void
    {
        $this->assertSame('{"0":"b","1":"a"}', $this->json->encode([1 => 'a', 0 => 'b']));
    }

    public function testFlags(): void
    {
        $this->assertSame('["é","a/b",1.0]', $this->json->encode(['é', 'a/b', 1.0]));
    }

    public function testScalarsAndNull(): void
    {
        $this->assertSame('"text"', $this->json->encode('text'));
        $this->assertSame('null', $this->json->encode(null));
        $this->assertSame('true', $this->json->encode(true));
    }

    public function testThrowsOnUnencodableValue(): void
    {
        $this->expectException(\JsonException::class);
        $this->json->encode(['v' => NAN]);
    }

    public function testThrowsOnSelfReferencingArray(): void
    {
        $value = ['a' => 1];
        $value['self'] = &$value;

        $this->expectException(\JsonException::class);
        $this->json->encode($value);
    }

    public function testThrowsOnSelfReferencingObject(): void
    {
        $value = new \stdClass();
        $value->self = $value;

        $this->expectException(\JsonException::class);
        $this->json->encode($value);
    }
}
