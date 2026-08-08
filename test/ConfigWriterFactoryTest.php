<?php

namespace LaminasTest\ApiTools\Configuration;

use Laminas\ApiTools\Configuration\Factory\ConfigWriterFactory;
use Laminas\Config\Writer\PhpArray;
use Override;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

class ConfigWriterFactoryTest extends TestCase
{
    /** @var ConfigWriterFactory */
    private $factory;

    #[Override]
    protected function setUp(): void
    {
        $this->factory = new ConfigWriterFactory();
    }

    public function testReturnsInstanceOfPhpArrayWriter(): void
    {
        $container    = $this->createStub(ContainerInterface::class);
        $factory      = $this->factory;
        $configWriter = $factory($container);

        $this->assertInstanceOf(PhpArray::class, $configWriter);
    }

    public function testDefaultFlagsValues(): void
    {
        $container    = $this->createStub(ContainerInterface::class);
        $factory      = $this->factory;
        $configWriter = $factory($container);

        $this->assertObjectHasProperty('useBracketArraySyntax', $configWriter);
        $this->assertFalse($configWriter->getUseClassNameScalars());
    }

    public function testEnableShortArrayFlagIsSet(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->expects(self::atLeastOnce())->method('has')->with('config')->willReturn(true);
        $container->expects(self::atLeastOnce())->method('get')->with('config')->willReturn([
            'api-tools-configuration' => [
                'enable_short_array' => true,
            ],
        ]);

        $factory      = $this->factory;
        $configWriter = $factory($container);

        $this->assertObjectHasProperty('useBracketArraySyntax', $configWriter);
    }

    public function testClassNameScalarsFlagIsSet(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->expects(self::atLeastOnce())->method('has')->with('config')->willReturn(true);
        $container->expects(self::atLeastOnce())->method('get')->with('config')->willReturn([
            'api-tools-configuration' => [
                'class_name_scalars' => true,
            ],
        ]);

        $factory      = $this->factory;
        $configWriter = $factory($container);

        $this->assertTrue($configWriter->getUseClassNameScalars());
    }
}
