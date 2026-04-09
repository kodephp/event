<?php

declare(strict_types=1);

namespace Kode\Event\Tests;

use Kode\Event\Dispatcher;
use Kode\Event\Event;
use Kode\Event\EventMiddleware;
use Kode\Event\EventReplay;
use Kode\Event\EventSchema;
use Kode\Event\EventSchemaRegistry;
use Kode\Event\ImmutableEvent;
use Kode\Event\LoggingMiddleware;
use Kode\Event\ValidationMiddleware;
use PHPUnit\Framework\TestCase;

class AdvancedEnhancementsTest extends TestCase
{
    public function testImmutableEvent(): void
    {
        $event = ImmutableEvent::create('user.created', ['name' => 'test']);

        $this->assertSame('user.created', $event->name);
        $this->assertSame(['name' => 'test'], $event->data);
        $this->assertFalse($event->propagationStopped);
    }

    public function testImmutableEventWith(): void
    {
        $event = ImmutableEvent::create('user.created', ['name' => 'test']);
        $newEvent = $event->with('age', 25);

        $this->assertSame('test', $event->get('name'));
        $this->assertNull($event->get('age'));
        $this->assertSame(25, $newEvent->get('age'));
        $this->assertSame('test', $newEvent->get('name'));
    }

    public function testImmutableEventFromEvent(): void
    {
        $original = new Event('test', ['data' => 'value']);
        $immutable = ImmutableEvent::fromEvent($original);

        $this->assertSame('test', $immutable->getName());
        $this->assertSame(['data' => 'value'], $immutable->getData());
    }

    public function testImmutableEventWithStopped(): void
    {
        $event = ImmutableEvent::create('test');
        $stopped = $event->withStopped();

        $this->assertFalse($event->isPropagationStopped());
        $this->assertTrue($stopped->isPropagationStopped());
    }

    public function testEventReplay(): void
    {
        $dispatcher = new Dispatcher();
        $replay = new EventReplay($dispatcher);
        $handled = false;

        $dispatcher->listen('test', function () use (&$handled) {
            $handled = true;
        });

        $replay->record(new Event('test'));
        $this->assertFalse($handled);

        $replay->replay();
        $this->assertTrue($handled);
    }

    public function testEventReplayMultiple(): void
    {
        $dispatcher = new Dispatcher();
        $replay = new EventReplay($dispatcher);
        $count = 0;

        $dispatcher->listen('event', function () use (&$count) {
            $count++;
        });

        $replay->record(new Event('event'));
        $replay->record(new Event('event'));
        $replay->record(new Event('event'));

        $this->assertSame(0, $count);
        $replay->replay();
        $this->assertSame(3, $count);
    }

    public function testEventReplayReverse(): void
    {
        $dispatcher = new Dispatcher();
        $replay = new EventReplay($dispatcher);
        $order = [];

        $dispatcher->listen('test', function () use (&$order) {
            $order[] = 'handled';
        });

        $replay->record(new Event('test'));
        $replay->record(new Event('test'));

        $replay->replayReverse();
        $this->assertCount(2, $order);
    }

    public function testEventReplayUntil(): void
    {
        $dispatcher = new Dispatcher();
        $replay = new EventReplay($dispatcher);
        $count = 0;

        $dispatcher->listen('a', function () use (&$count) {
            $count++;
        });
        $dispatcher->listen('b', function () use (&$count) {
            $count++;
        });
        $dispatcher->listen('c', function () use (&$count) {
            $count++;
        });

        $replay->record(new Event('a'));
        $replay->record(new Event('b'));
        $replay->record(new Event('c'));

        $replay->replayUntil('b');
        $this->assertSame(2, $count);
    }

    public function testEventReplayIf(): void
    {
        $dispatcher = new Dispatcher();
        $replay = new EventReplay($dispatcher);
        $count = 0;

        $dispatcher->listen('test', function () use (&$count) {
            $count++;
        });

        $replay->record(new Event('test', ['value' => 1]));
        $replay->record(new Event('test', ['value' => 2]));
        $replay->record(new Event('test', ['value' => 3]));

        $replay->replayIf(fn($e) => $e->get('value') > 1);
        $this->assertSame(2, $count);
    }

    public function testEventReplayExportImport(): void
    {
        $replay = new EventReplay(new Dispatcher());

        $replay->record(new Event('user.created', ['name' => 'test1']));
        $replay->record(new Event('user.updated', ['name' => 'test2']));

        $exported = $replay->export();

        $this->assertCount(2, $exported);
        $this->assertSame('user.created', $exported[0]['name']);

        $imported = EventReplay::import($exported);
        $this->assertCount(2, $imported);
        $this->assertInstanceOf(Event::class, $imported[0]);
    }

    public function testEventMiddleware(): void
    {
        $middleware = new EventMiddleware();
        $dispatcher = new Dispatcher();
        $executed = false;

        $middleware->add(function ($event, $next) use (&$executed) {
            $executed = true;
            return $next($event);
        });

        $dispatcher->listen('test', function () {});

        $result = $middleware->process(new Event('test'), fn($e) => $dispatcher->dispatch($e));

        $this->assertTrue($executed);
    }

    public function testEventMiddlewareMultiple(): void
    {
        $middleware = new EventMiddleware();
        $order = [];

        $middleware->add(function ($event, $next) use (&$order) {
            $order[] = 'first';
            return $next($event);
        }, priority: 10);

        $middleware->add(function ($event, $next) use (&$order) {
            $order[] = 'second';
            return $next($event);
        }, priority: 5);

        $dispatcher = new Dispatcher();
        $dispatcher->listen('test', function () {});

        $middleware->process(new Event('test'), fn($e) => $dispatcher->dispatch($e));

        $this->assertSame(['first', 'second'], $order);
    }

    public function testEventMiddlewareRemove(): void
    {
        $middleware = new EventMiddleware();
        $executed = false;

        $callback = function ($event, $next) use (&$executed) {
            $executed = true;
            return $next($event);
        };

        $middleware->add($callback);
        $middleware->remove($callback);

        $dispatcher = new Dispatcher();
        $middleware->process(new Event('test'), fn($e) => $dispatcher->dispatch($e));

        $this->assertFalse($executed);
    }

    public function testLoggingMiddleware(): void
    {
        $middleware = new LoggingMiddleware();
        $dispatcher = new Dispatcher();
        $handled = false;

        $dispatcher->listen('test', function () use (&$handled) {
            $handled = true;
        });

        $middleware->handle(new Event('test'), fn($e) => $dispatcher->dispatch($e));

        $this->assertTrue($handled);
    }

    public function testValidationMiddleware(): void
    {
        $middleware = new ValidationMiddleware();
        $dispatcher = new Dispatcher();
        $handled = false;

        $middleware->addRule('user.created', fn($e) => $e->has('user_id'));

        $dispatcher->listen('user.created', function () use (&$handled) {
            $handled = true;
        });

        $validEvent = new Event('user.created', ['user_id' => 1]);
        $middleware->handle($validEvent, fn($e) => $dispatcher->dispatch($e));

        $this->assertTrue($handled);
    }

    public function testValidationMiddlewareFails(): void
    {
        $middleware = new ValidationMiddleware();
        $dispatcher = new Dispatcher();

        $middleware->addRule('user.created', fn($e) => $e->has('user_id'));

        $dispatcher->listen('user.created', function () {});

        $invalidEvent = new Event('user.created', ['name' => 'test']);

        $this->expectException(\RuntimeException::class);
        $middleware->handle($invalidEvent, fn($e) => $dispatcher->dispatch($e));
    }

    public function testEventSchema(): void
    {
        $schema = EventSchema::create('user.created')
            ->required('user_id', 'int')
            ->required('name', 'string')
            ->optional('email', 'string');

        $validEvent = new Event('user.created', [
            'user_id' => 1,
            'name' => 'test',
            'email' => 'test@example.com',
        ]);

        $this->assertTrue($schema->validateEvent($validEvent));
    }

    public function testEventSchemaMissingRequired(): void
    {
        $schema = EventSchema::create('user.created')
            ->required('user_id', 'int');

        $invalidEvent = new Event('user.created', ['name' => 'test']);

        $this->assertFalse($schema->validateEvent($invalidEvent));
    }

    public function testEventSchemaWrongType(): void
    {
        $schema = EventSchema::create('user.created')
            ->required('user_id', 'int');

        $invalidEvent = new Event('user.created', ['user_id' => 'not-an-int']);

        $this->assertFalse($schema->validateEvent($invalidEvent));
    }

    public function testEventSchemaRegistry(): void
    {
        $registry = new EventSchemaRegistry();

        $schema = EventSchema::create('user.created')
            ->required('user_id', 'int');

        $registry->register($schema);

        $this->assertTrue($registry->has('user.created'));
        $this->assertFalse($registry->has('unknown.event'));

        $validEvent = new Event('user.created', ['user_id' => 1]);
        $invalidEvent = new Event('user.created', ['name' => 'test']);

        $this->assertTrue($registry->validate($validEvent));
        $this->assertFalse($registry->validate($invalidEvent));
    }

    public function testEventSchemaCustomValidator(): void
    {
        $schema = EventSchema::create('order.paid')
            ->required('order_id', 'int')
            ->required('amount', 'numeric')
            ->validate(fn($e) => $e->get('amount') > 0);

        $validEvent = new Event('order.paid', ['order_id' => 1, 'amount' => 99.99]);
        $invalidEvent = new Event('order.paid', ['order_id' => 1, 'amount' => -10]);

        $this->assertTrue($schema->validateEvent($validEvent));
        $this->assertFalse($schema->validateEvent($invalidEvent));
    }
}