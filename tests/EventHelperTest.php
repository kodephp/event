<?php

declare(strict_types=1);

namespace Kode\Event\Tests;

use Kode\Event\EventHelper;
use PHPUnit\Framework\TestCase;

class EventHelperTest extends TestCase
{
    public function testIsValidName(): void
    {
        $this->assertTrue(EventHelper::isValidName('user.created'));
        $this->assertTrue(EventHelper::isValidName('order.paid'));
        $this->assertTrue(EventHelper::isValidName('app_start'));
        $this->assertFalse(EventHelper::isValidName('123.invalid'));
        $this->assertFalse(EventHelper::isValidName(''));
    }

    public function testNormalizeName(): void
    {
        $this->assertSame('user.created', EventHelper::normalizeName(' User.Created '));
    }

    public function testParseName(): void
    {
        $parsed = EventHelper::parseName('user.profile.updated');

        $this->assertSame('user', $parsed['prefix']);
        $this->assertSame('profile', $parsed['name']);
        $this->assertSame('updated', $parsed['suffix']);
    }

    public function testMatchesPattern(): void
    {
        $this->assertTrue(EventHelper::matchesPattern('user.created', 'user.*'));
        $this->assertTrue(EventHelper::matchesPattern('user.created', '*.created'));
        $this->assertTrue(EventHelper::matchesPattern('user.created', '*.*'));
        $this->assertFalse(EventHelper::matchesPattern('user.created', 'order.*'));
    }

    public function testGetPhpFeatures(): void
    {
        $features = EventHelper::getPhpFeatures();

        $this->assertIsArray($features);
        $this->assertArrayHasKey('enum', $features);
        $this->assertArrayHasKey('union_types', $features);
        $this->assertArrayHasKey('readonly', $features);
    }
}
