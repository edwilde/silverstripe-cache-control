<?php

namespace Edwilde\CacheControls\Tests\Unit;

use Edwilde\CacheControls\Traits\CacheControlTrait;
use PHPUnit\Framework\TestCase;

class CacheControlTraitTest extends TestCase
{
    private function createMockObjectWithTrait($properties = [])
    {
        return new class($properties) {
            use CacheControlTrait;
            
            public $EnableCacheControl;
            public $CacheType;
            public $EnableMaxAge;
            public $MaxAge;
            public $EnableMustRevalidate;
            public $EnableNoStore;
            
            public function __construct($properties)
            {
                foreach ($properties as $key => $value) {
                    $this->$key = $value;
                }
            }
            
            public function getCacheHeader()
            {
                return $this->buildCacheControlHeader();
            }
        };
    }

    public function testReturnsNullWhenDisabled()
    {
        $obj = $this->createMockObjectWithTrait([
            'EnableCacheControl' => false,
        ]);
        
        $this->assertNull($obj->getCacheHeader());
    }

    public function testBuildsPublicMaxAge()
    {
        $obj = $this->createMockObjectWithTrait([
            'EnableCacheControl' => true,
            'CacheType' => 'public',
            'EnableMaxAge' => true,
            'MaxAge' => 3600,
        ]);
        
        $this->assertEquals('public, max-age=3600', $obj->getCacheHeader());
    }

    public function testBuildsPrivate()
    {
        $obj = $this->createMockObjectWithTrait([
            'EnableCacheControl' => true,
            'CacheType' => 'private',
            'EnableMaxAge' => false,
        ]);
        
        $this->assertEquals('private', $obj->getCacheHeader());
    }

    public function testBuildsWithMustRevalidate()
    {
        $obj = $this->createMockObjectWithTrait([
            'EnableCacheControl' => true,
            'CacheType' => 'public',
            'EnableMustRevalidate' => true,
        ]);
        
        $header = $obj->getCacheHeader();
        $this->assertStringContainsString('must-revalidate', $header);
        $this->assertStringContainsString('public', $header);
    }

    public function testNoStoreOverridesMaxAge()
    {
        $obj = $this->createMockObjectWithTrait([
            'EnableCacheControl' => true,
            'EnableNoStore' => true,
            'EnableMaxAge' => true,
            'MaxAge' => 3600,
        ]);
        
        $header = $obj->getCacheHeader();
        $this->assertStringContainsString('no-store', $header);
        $this->assertStringNotContainsString('max-age', $header);
    }

    public function testMaxAgeDefaultsTo120()
    {
        $obj = $this->createMockObjectWithTrait([
            'EnableCacheControl' => true,
            'CacheType' => 'public',
            'EnableMaxAge' => true,
            'MaxAge' => 0,
        ]);
        
        $this->assertEquals('public, max-age=120', $obj->getCacheHeader());
    }

    public function testComplexHeader()
    {
        $obj = $this->createMockObjectWithTrait([
            'EnableCacheControl' => true,
            'CacheType' => 'public',
            'EnableMaxAge' => true,
            'MaxAge' => 7200,
            'EnableMustRevalidate' => true,
        ]);
        
        $this->assertEquals('public, max-age=7200, must-revalidate', $obj->getCacheHeader());
    }

    public function testMaxAgeIgnoredWhenNotEnabled()
    {
        $obj = $this->createMockObjectWithTrait([
            'EnableCacheControl' => true,
            'CacheType' => 'public',
            'EnableMaxAge' => false,
            'MaxAge' => 3600,
        ]);
        
        $header = $obj->getCacheHeader();
        $this->assertEquals('public', $header);
        $this->assertStringNotContainsString('max-age', $header);
    }
}
