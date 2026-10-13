<?php

declare(strict_types=1);

namespace Poletti\Framework\Tests\Container;

use PHPUnit\Framework\TestCase;
use Poletti\Framework\Container\Container;
use Poletti\Framework\Tests\Support\FixtureAbstract;
use Poletti\Framework\Tests\Support\FixtureConsumer;
use Poletti\Framework\Tests\Support\FixtureService;
use Poletti\Framework\Tests\Support\FixtureServiceInterface;
use Poletti\Framework\Tests\Support\FixtureWithScalarConstructor;
use Poletti\Framework\Tests\Support\FixtureWithoutConstructor;
use RuntimeException;

final class ContainerTest extends TestCase
{
    private Container $container;

    protected function setUp(): void
    {
        $this->container = new Container();
    }

    public function testInstanceReturnsTheRegisteredObject(): void
    {
        $instance = new FixtureService();
        $this->container->instance(FixtureServiceInterface::class, $instance);

        self::assertSame(
            $instance,
            $this->container->get(FixtureServiceInterface::class)
        );
    }

    public function testRegisteredInstanceTakesPrecedenceOverBinding(): void
    {
        $instance = new FixtureService();
        $this->container->bind(FixtureServiceInterface::class, FixtureService::class);
        $this->container->instance(FixtureServiceInterface::class, $instance);

        self::assertSame($instance, $this->container->get(FixtureServiceInterface::class));
    }

    public function testBindBuildsAClassOnEachResolution(): void
    {
        $this->container->bind(FixtureServiceInterface::class, FixtureService::class);

        $first = $this->container->get(FixtureServiceInterface::class);
        $second = $this->container->get(FixtureServiceInterface::class);

        self::assertInstanceOf(FixtureService::class, $first);
        self::assertInstanceOf(FixtureService::class, $second);
        self::assertNotSame($first, $second);
    }

    public function testSingletonReusesTheSameInstance(): void
    {
        $this->container->singleton(FixtureServiceInterface::class, FixtureService::class);

        self::assertSame(
            $this->container->get(FixtureServiceInterface::class),
            $this->container->get(FixtureServiceInterface::class)
        );
    }

    public function testContainerResolvesConstructorDependenciesRecursively(): void
    {
        $this->container->bind(FixtureServiceInterface::class, FixtureService::class);

        $consumer = $this->container->get(FixtureConsumer::class);

        self::assertInstanceOf(FixtureConsumer::class, $consumer);
        self::assertInstanceOf(FixtureService::class, $consumer->service);
        self::assertSame('fixture-service', $consumer->service->value());
    }

    public function testBuildSupportsClassesWithoutAConstructor(): void
    {
        self::assertInstanceOf(
            FixtureWithoutConstructor::class,
            $this->container->build(FixtureWithoutConstructor::class)
        );
    }

    public function testGetThrowsForAnUnknownClass(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Class Missing\\SomeClass does not exist.');

        $this->container->get('Missing\\SomeClass');
    }

    public function testBuildThrowsForAnAbstractClass(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is not instantiable');

        $this->container->build(FixtureAbstract::class);
    }

    public function testBuildThrowsWhenAConstructorHasAScalarDependency(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot resolve depedency value');

        $this->container->build(FixtureWithScalarConstructor::class);
    }
}
