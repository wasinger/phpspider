<?php
namespace Wa72\Spider\Tests;

use PHPUnit\Framework\TestCase;
use Wa72\Spider\Core\UrlFilter;

class UrlFilterTest extends TestCase
{
    public function testFiltersSchemesAndAllowedHosts(): void
    {
        $filter = (new UrlFilter())->addAllowedHost('example.test');

        $this->assertTrue($filter->filter('https://example.test/page'));
        $this->assertFalse($filter->filter('ftp://example.test/file'));
        $this->assertFalse($filter->filter('https://other.test/page'));

        $filter->setAllowedSchemes(['ftp']);
        $this->assertTrue($filter->filter('ftp://example.test/file'));
        $this->assertFalse($filter->filter('https://example.test/page'));
    }

    public function testRejectsPathsMatchingConfiguredRegex(): void
    {
        $filter = (new UrlFilter())->rejectPathByRegex('~^/private(?:/|$)~');

        $this->assertFalse($filter->filter('https://example.test/private/page'));
        $this->assertTrue($filter->filter('https://example.test/public/page'));
    }

    public function testRejectsScalarAndArrayQueryParameters(): void
    {
        $filter = (new UrlFilter())->rejectByQueryparam([
            'page' => '2',
            'tag' => 'blocked'
        ]);

        $this->assertFalse($filter->filter('https://example.test/?page=2'));
        $this->assertTrue($filter->filter('https://example.test/?page=3'));
        $this->assertFalse($filter->filter('https://example.test/?tag%5B%5D=allowed&tag%5B%5D=blocked'));
        $this->assertTrue($filter->filter('https://example.test/?tag%5B%5D=allowed'));
    }

    public function testAppliesCustomFilterFunctionToUri(): void
    {
        $receivedUri = null;
        $filter = (new UrlFilter())->addFilterFunction(static function ($uri) use (&$receivedUri) {
            $receivedUri = $uri;
            return $uri->getPath() !== '/excluded';
        });

        $this->assertTrue($filter->filter('https://example.test/allowed'));
        $this->assertSame('/allowed', $receivedUri->getPath());
        $this->assertFalse($filter->filter('https://example.test/excluded'));
    }
}