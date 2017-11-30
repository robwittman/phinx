<?php

namespace Test\Apartment\Migration\Manager;

use Apartment\Db\Adapter\AdapterFactory;
use Apartment\Db\Adapter\PdoAdapter;
use Apartment\Migration\Manager\Environment;
use Apartment\Migration\MigrationInterface;

class PDOMock extends \PDO
{
    public $attributes = [];

    public function __construct()
    {
    }

    public function getAttribute($attribute)
    {
        return isset($this->attributes[$attribute]) ? $this->attributes[$attribute] : 'pdomock';
    }

    public function setAttribute($attribute, $value)
    {
        $this->attributes[$attribute] = $value;
    }
}

class EnvironmentTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var \Apartment\Migration\Manager\Environment
     */
    private $environment;

    public function setUp()
    {
        $this->environment = new Environment('test', []);
    }

    public function testConstructorWorksAsExpected()
    {
        $env = new Environment('testenv', ['foo' => 'bar']);
        $this->assertEquals('testenv', $env->getName());
        $this->assertArrayHasKey('foo', $env->getOptions());
    }

    public function testSettingTheName()
    {
        $this->environment->setName('prod123');
        $this->assertEquals('prod123', $this->environment->getName());
    }

    public function testSettingOptions()
    {
        $this->environment->setOptions(['foo' => 'bar']);
        $this->assertArrayHasKey('foo', $this->environment->getOptions());
    }

    /**
     * @expectedException \RuntimeException
     * @expectedExceptionMessage Adapter "fakeadapter" has not been registered
     */
    public function testInvalidAdapter()
    {
        $this->environment->setOptions(['adapter' => 'fakeadapter']);
        $this->environment->getAdapter();
    }

    /**
     * @expectedException \RuntimeException
     */
    public function testNoAdapter()
    {
        $this->environment->getAdapter();
    }

    public function testGetAdapterWithExistingPdoInstance()
    {
        $adapter = $this->getMockForAbstractClass('\Apartment\Db\Adapter\PdoAdapter', [['foo' => 'bar']]);
        AdapterFactory::instance()->registerAdapter('pdomock', $adapter);
        $this->environment->setOptions(['connection' => new PDOMock()]);
        $options = $this->environment->getAdapter()->getOptions();
        $this->assertEquals('pdomock', $options['adapter']);
    }

    public function testSetPdoAttributeToErrmodeException()
    {
        $pdoMock = new PDOMock();
        $adapter = $this->getMockForAbstractClass('\Apartment\Db\Adapter\PdoAdapter', [['foo' => 'bar']]);
        AdapterFactory::instance()->registerAdapter('pdomock', $adapter);
        $this->environment->setOptions(['connection' => $pdoMock]);
        $options = $this->environment->getAdapter()->getOptions();
        $this->assertEquals(\PDO::ERRMODE_EXCEPTION, $options['connection']->getAttribute(\PDO::ATTR_ERRMODE));
    }

    /**
     * @expectedException \RuntimeException
     * @expectedExceptionMessage The specified connection is not a PDO instance
     */
    public function testGetAdapterWithBadExistingPdoInstance()
    {
        $this->environment->setOptions(['connection' => new \stdClass()]);
        $this->environment->getAdapter();
    }

    public function testTablePrefixAdapter()
    {
        $this->environment->setOptions(['table_prefix' => 'tbl_', 'adapter' => 'mysql']);
        $this->assertInstanceOf('Apartment\Db\Adapter\TablePrefixAdapter', $this->environment->getAdapter());

        $tablePrefixAdapter = $this->environment->getAdapter();
        $this->assertInstanceOf('Apartment\Db\Adapter\MysqlAdapter', $tablePrefixAdapter->getAdapter()->getAdapter());
    }

    public function testSchemaName()
    {
        $this->assertEquals('Apartmentlog', $this->environment->getSchemaTableName());

        $this->environment->setSchemaTableName('changelog');
        $this->assertEquals('changelog', $this->environment->getSchemaTableName());
    }

    public function testCurrentVersion()
    {
        $stub = $this->getMockBuilder('\Apartment\Db\Adapter\PdoAdapter')
            ->setConstructorArgs([[]])
            ->getMock();
        $stub->expects($this->any())
             ->method('getVersions')
             ->will($this->returnValue(['20110301080000']));

        $this->environment->setAdapter($stub);

        $this->assertEquals('20110301080000', $this->environment->getCurrentVersion());
    }

    public function testExecutingAMigrationUp()
    {
        // stub adapter
        $adapterStub = $this->getMockBuilder('\Apartment\Db\Adapter\PdoAdapter')
            ->setConstructorArgs([[]])
            ->getMock();
        $adapterStub->expects($this->once())
                    ->method('migrated')
                    ->will($this->returnArgument(0));

        $this->environment->setAdapter($adapterStub);

        // up
        $upMigration = $this->getMockBuilder('\Apartment\Migration\AbstractMigration')
            ->setConstructorArgs(['20110301080000'])
            ->setMethods(['up'])
            ->getMock();
        $upMigration->expects($this->once())
                    ->method('up');

        $this->environment->executeMigration($upMigration, MigrationInterface::UP);
    }

    public function testExecutingAMigrationDown()
    {
        // stub adapter
        $adapterStub = $this->getMockBuilder('\Apartment\Db\Adapter\PdoAdapter')
            ->setConstructorArgs([[]])
            ->getMock();
        $adapterStub->expects($this->once())
                    ->method('migrated')
                    ->will($this->returnArgument(0));

        $this->environment->setAdapter($adapterStub);

        // down
        $downMigration = $this->getMockBuilder('\Apartment\Migration\AbstractMigration')
            ->setConstructorArgs(['20110301080000'])
            ->setMethods(['down'])
            ->getMock();
        $downMigration->expects($this->once())
                      ->method('down');

        $this->environment->executeMigration($downMigration, MigrationInterface::DOWN);
    }

    public function testExecutingAMigrationWithTransactions()
    {
        // stub adapter
        $adapterStub = $this->getMockBuilder('\Apartment\Db\Adapter\PdoAdapter')
            ->setConstructorArgs([[]])
            ->getMock();
        $adapterStub->expects($this->once())
                    ->method('beginTransaction');

        $adapterStub->expects($this->once())
                    ->method('commitTransaction');

        $adapterStub->expects($this->exactly(2))
                    ->method('hasTransactions')
                    ->will($this->returnValue(true));

        $this->environment->setAdapter($adapterStub);

        // migrate
        $migration = $this->getMockBuilder('\Apartment\Migration\AbstractMigration')
            ->setConstructorArgs(['20110301080000'])
            ->setMethods(['up'])
            ->getMock();
        $migration->expects($this->once())
                  ->method('up');

        $this->environment->executeMigration($migration, MigrationInterface::UP);
    }

    public function testExecutingAChangeMigrationUp()
    {
        // stub adapter
        $adapterStub = $this->getMockBuilder('\Apartment\Db\Adapter\PdoAdapter')
            ->setConstructorArgs([[]])
            ->getMock();
        $adapterStub->expects($this->once())
                    ->method('migrated')
                    ->will($this->returnArgument(0));

        $this->environment->setAdapter($adapterStub);

        // migration
        $migration = $this->getMockBuilder('\Apartment\Migration\AbstractMigration')
            ->setConstructorArgs(['20130301080000'])
            ->setMethods(['change'])
            ->getMock();
        $migration->expects($this->once())
                  ->method('change');

        $this->environment->executeMigration($migration, MigrationInterface::UP);
    }

    public function testExecutingAChangeMigrationDown()
    {
        // stub adapter
        $adapterStub = $this->getMockBuilder('\Apartment\Db\Adapter\PdoAdapter')
            ->setConstructorArgs([[]])
            ->getMock();
        $adapterStub->expects($this->once())
                    ->method('migrated')
                    ->will($this->returnArgument(0));

        $this->environment->setAdapter($adapterStub);

        // migration
        $migration = $this->getMockBuilder('\Apartment\Migration\AbstractMigration')
            ->setConstructorArgs(['20130301080000'])
            ->setMethods(['change'])
            ->getMock();
        $migration->expects($this->once())
                  ->method('change');

        $this->environment->executeMigration($migration, MigrationInterface::DOWN);
    }

    public function testGettingInputObject()
    {
        $mock = $this->getMockBuilder('\Symfony\Component\Console\Input\InputInterface')
            ->getMock();
        $this->environment->setInput($mock);
        $inputObject = $this->environment->getInput();
        $this->assertInstanceOf('\Symfony\Component\Console\Input\InputInterface', $inputObject);
    }
}