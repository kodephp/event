<?php

declare(strict_types=1);

namespace Kode\Event\Tests;

use Kode\Event\Dispatcher;
use Kode\Event\Event;
use Kode\Event\EventHooks;
use Kode\Event\EventPipeline;
use Kode\Event\Php85Features;
use PHPUnit\Framework\TestCase;

class Php85AndPipelineTest extends TestCase
{
    public function testEventPipeline(): void
    {
        $event = new Event('user.created', ['name' => 'test']);
        $pipeline = EventPipeline::create($event);

        $result = $pipeline
            ->pipe(fn($e) => $e->set('piped', true))
            ->pipe(fn($e) => $e->set('step', 1))
            ->execute();

        $this->assertSame('test', $result->get('name'));
        $this->assertTrue($result->get('piped'));
        $this->assertSame(1, $result->get('step'));
    }

    public function testEventPipelineWithFilter(): void
    {
        $event = new Event('test', ['value' => 1]);
        $pipeline = EventPipeline::create($event);

        $result = $pipeline
            ->filter(fn($e) => $e->get('value') > 0)
            ->pipe(fn($e) => $e->set('filtered', true))
            ->execute();

        $this->assertNotNull($result);
        $this->assertTrue($result->get('filtered'));
    }

    public function testEventPipelineWithStop(): void
    {
        $event = new Event('test', ['value' => 1]);
        $pipeline = EventPipeline::create($event);

        $result = $pipeline
            ->pipe(fn($e) => $e->set('step1', true))
            ->stop()
            ->pipe(fn($e) => $e->set('step2', true))
            ->execute();

        $this->assertNull($result);
        $this->assertTrue($pipeline->isStopped());
    }

    public function testEventPipelineDispatch(): void
    {
        $dispatcher = new Dispatcher();
        $executed = false;

        $dispatcher->listen('test', function () use (&$executed) {
            $executed = true;
        });

        $event = new Event('test', ['data' => 'value']);
        $pipeline = EventPipeline::create($event);
        $pipeline->pipe(fn($e) => $e->set('piped', true));

        $resultEvent = $pipeline->dispatch($dispatcher);

        $this->assertTrue($executed);
        $this->assertTrue($resultEvent->get('piped'));
    }

    public function testEventPipelineThen(): void
    {
        $event = new Event('test', ['value' => 42]);
        $pipeline = EventPipeline::create($event);

        $result = $pipeline
            ->pipe(fn($e) => $e->set('transformed', true))
            ->then(fn($e) => $e->get('value') * 2);

        $this->assertSame(84, $result);
    }

    public function testEventPipelineTap(): void
    {
        $tapped = false;
        $event = new Event('test', ['data' => 'value']);
        $pipeline = EventPipeline::create($event);

        $pipeline->tap(function ($e) use (&$tapped) {
            $tapped = true;
        })->execute();

        $this->assertTrue($tapped);
    }

    public function testEventHooks(): void
    {
        $hooks = new EventHooks();
        $beforeCalled = false;
        $afterCalled = false;

        $hooks->before(function (Event $event) use (&$beforeCalled) {
            $beforeCalled = true;
            return $event;
        });

        $hooks->after(function (Event $event) use (&$afterCalled) {
            $afterCalled = true;
        });

        $event = new Event('test');
        $hooks->triggerBefore($event);
        $hooks->triggerAfter($event);

        $this->assertTrue($beforeCalled);
        $this->assertTrue($afterCalled);
    }

    public function testEventHooksWithPriority(): void
    {
        $hooks = new EventHooks();
        $order = [];

        $hooks->before(function () use (&$order) {
            $order[] = 'first';
        }, priority: 10);

        $hooks->before(function () use (&$order) {
            $order[] = 'second';
        }, priority: 5);

        $event = new Event('test');
        $hooks->triggerBefore($event);

        $this->assertSame(['first', 'second'], $order);
    }

    public function testEventHooksError(): void
    {
        $hooks = new EventHooks();
        $errorCalled = false;
        $errorMessage = '';

        $hooks->error(function (Event $event, \Throwable $e) use (&$errorCalled, &$errorMessage) {
            $errorCalled = true;
            $errorMessage = $e->getMessage();
        });

        $event = new Event('test');
        $exception = new \RuntimeException('Test error');
        $hooks->triggerError($event, $exception);

        $this->assertTrue($errorCalled);
        $this->assertSame('Test error', $errorMessage);
    }

    public function testEventHooksRemove(): void
    {
        $hooks = new EventHooks();
        $called = false;

        $callback = function () use (&$called) {
            $called = true;
        };

        $hooks->before($callback);
        $hooks->removeBefore($callback);
        $hooks->triggerBefore(new Event('test'));

        $this->assertFalse($called);
    }

    public function testPhp85FeaturesHasMethods(): void
    {
        $this->assertIsBool(Php85Features::hasPipeOperator());
        $this->assertIsBool(Php85Features::hasCloneWith());
        $this->assertIsBool(Php85Features::hasAsymmetricVisibility());
        $this->assertIsBool(Php85Features::hasTrueType());
        $this->assertIsBool(Php85Features::hasJsonError());
    }

    public function testPhp85FeaturesPipe(): void
    {
        $value = 5;
        $result = Php85Features::pipe($value, fn($v) => $v * 2);
        $this->assertSame(10, $result);
    }

    public function testPhp85FeaturesPipeMany(): void
    {
        $value = 2;
        $result = Php85Features::pipeMany($value, [
            fn($v) => $v + 1,
            fn($v) => $v * 2,
            fn($v) => $v - 1,
        ]);
        $this->assertSame(5, $result);
    }

    public function testPhp85FeaturesPipeEvent(): void
    {
        $event = new Event('test', ['value' => 1]);
        $result = Php85Features::pipeEvent($event, function ($e) {
            $e->set('piped', true);
            return $e;
        });

        $this->assertSame('test', $result->getName());
        $this->assertTrue($result->get('piped'));
    }

    public function testPhp85FeaturesVersion(): void
    {
        $this->assertIsString(Php85Features::getPhpVersion());
        $this->assertIsInt(Php85Features::getMajorVersion());
        $this->assertIsInt(Php85Features::getMinorVersion());
        $this->assertIsInt(Php85Features::getReleaseVersion());

        $this->assertSame(8, Php85Features::getMajorVersion());
    }

    public function testPhp85FeaturesSupportsFeature(): void
    {
        $this->assertTrue(Php85Features::supportsFeature('enum'));
        $this->assertTrue(Php85Features::supportsFeature('union_types'));
        $this->assertTrue(Php85Features::supportsFeature('readonly'));
        $this->assertTrue(Php85Features::supportsFeature('never_type'));
        $this->assertFalse(Php85Features::supportsFeature('unknown_feature'));
    }

    public function testPhp85FeaturesGetAllFeatures(): void
    {
        $features = Php85Features::getAllFeatures();

        $this->assertIsArray($features);
        $this->assertArrayHasKey('php_version', $features);
        $this->assertArrayHasKey('major', $features);
        $this->assertArrayHasKey('minor', $features);
        $this->assertArrayHasKey('enum', $features);
        $this->assertArrayHasKey('pipe_operator', $features);
        $this->assertArrayHasKey('clone_with', $features);
    }
}