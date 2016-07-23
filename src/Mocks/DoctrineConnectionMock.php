<?php

namespace Testbench\Mocks;

use Doctrine\Common;
use Doctrine\DBAL;
use Doctrine\DBAL\Platforms\MySqlPlatform;
use Doctrine\DBAL\Platforms\PostgreSqlPlatform;

/**
 * @method onConnect(DoctrineConnectionMock $self)
 */
class DoctrineConnectionMock extends \Kdyby\Doctrine\Connection implements \Testbench\Providers\IDatabaseProvider
{

	private $__testbench_databaseName;

	public $onConnect = [];

	public function connect()
	{
		if (parent::connect()) {
			$this->onConnect($this);
		}
	}

	public function __construct(
		array $params,
		DBAL\Driver $driver,
		DBAL\Configuration $config = NULL,
		Common\EventManager $eventManager = NULL
	) {
		$container = \Testbench\ContainerFactory::create(FALSE);
		$this->onConnect[] = function (DoctrineConnectionMock $connection) use ($container) {
			if ($this->__testbench_databaseName !== NULL) { //already initialized (needed for pgsql)
				return;
			}
			try {
				$dataFile = 'nette.safe://' . \Testbench\Bootstrap::$tempDir . '/../databases.testbench';
				if (file_exists($dataFile)) {
					$data = file_get_contents($dataFile);
				} else {
					$data = '';
				}

				$dbName = 'testbench_' . getenv(\Tester\Environment::THREAD);
				$this->__testbench_databaseName = $dbName;

				if (!preg_match('~' . $dbName . '~', $data)) {
					$handle = fopen($dataFile, 'a+');
					fwrite($handle, $dbName . "\n");
					fclose($handle);

					$this->__testbench_database_setup($connection, $container);
				} else { //database already exists
					$this->__testbench_switch_database($connection, $container);
				}

				$connection->beginTransaction();
			} catch (\Exception $e) {
				\Tester\Assert::fail($e->getMessage());
			}
		};
		parent::__construct($params, $driver, $config, $eventManager);
	}

	/**
	 * @internal
	 *
	 * @param \Kdyby\Doctrine\Connection $connection
	 */
	public function __testbench_switch_database($connection, \Nette\DI\Container $container)
	{
		if ($connection->getDatabasePlatform() instanceof MySqlPlatform) {
			try {
				$connection->exec("USE {$this->__testbench_databaseName}");
			} catch (\Doctrine\DBAL\Exception\DriverException $exc) {
				if ($exc->getErrorCode() === 1049) { //ER_BAD_DB_ERROR
					$this->__testbench_database_setup($connection, $container);
				} else {
					throw $exc;
				}
			}
		} else {
			try {
				$this->__testbench_database_connect($connection, $container, $this->__testbench_databaseName);
			} catch (\Doctrine\DBAL\Exception\DriverException $exc) {
				if ($exc->getErrorCode() === 7) {
					$this->__testbench_database_setup($connection, $container);
				} else {
					throw $exc;
				}
			}
		}
	}

	/**
	 * @internal
	 *
	 * @param DoctrineConnectionMock $connection
	 */
	public function __testbench_database_setup($connection, \Nette\DI\Container $container)
	{
		try {
			$this->__testbench_database_create($connection, $container);
		} catch (\Doctrine\DBAL\Exception\DriverException $exc) {
			if ($exc->getErrorCode() === 1007 || $exc->getErrorCode() === 7) { //ER_DB_CREATE_EXISTS (7 - pgsql)
				$this->__testbench_database_drop($connection, $container);
				$this->__testbench_database_create($connection, $container);
			} else {
				throw $exc;
			}
		}

		$config = $container->parameters['testbench'];

		if (isset($config['sqls'])) {
			foreach ($container->parameters['testbench']['sqls'] as $file) {
				\Kdyby\Doctrine\Dbal\BatchImport\Helpers::loadFromFile($connection, $file);
			}
		}

		if (isset($config['migrations']) && $config['migrations'] === TRUE) {
			if (class_exists(\Zenify\DoctrineMigrations\Configuration\Configuration::class)) {
				/** @var \Zenify\DoctrineMigrations\Configuration\Configuration $migrationsConfig */
				$migrationsConfig = $container->getByType(\Zenify\DoctrineMigrations\Configuration\Configuration::class);
				$migrationsConfig->__construct($container, $connection);
				$migrationsConfig->registerMigrationsFromDirectory($migrationsConfig->getMigrationsDirectory());
				$migration = new \Doctrine\DBAL\Migrations\Migration($migrationsConfig);
				$migration->migrate($migrationsConfig->getLatestVersion());
			}
		}
	}

	/**
	 * @internal
	 *
	 * @param $connection \Kdyby\Doctrine\Connection
	 */
	public function __testbench_database_create($connection, \Nette\DI\Container $container)
	{
		if (!$connection->getDatabasePlatform() instanceof MySqlPlatform) {
			$this->__testbench_database_connect($connection, $container);
		}
		$connection->exec("CREATE DATABASE {$this->__testbench_databaseName}");
		$this->__testbench_switch_database($connection, $container);
	}

	/**
	 * @internal
	 *
	 * @param $connection \Kdyby\Doctrine\Connection
	 */
	public function __testbench_database_drop($connection, \Nette\DI\Container $container)
	{
		if (!$connection->getDatabasePlatform() instanceof MySqlPlatform) {
			$this->__testbench_database_connect($connection, $container);
		}
		$connection->exec("DROP DATABASE IF EXISTS {$this->__testbench_databaseName}");
	}

	/**
	 * @internal
	 *
	 * @param $connection \Kdyby\Doctrine\Connection
	 */
	public function __testbench_database_connect($connection, \Nette\DI\Container $container, $databaseName = NULL)
	{
		//connect to an existing database other than $this->_databaseName
		if ($databaseName === NULL) {
			$config = $container->parameters['testbench'];
			if (isset($config['dbname'])) {
				$databaseName = $config['dbname'];
			} elseif ($connection->getDatabasePlatform() instanceof PostgreSqlPlatform) {
				$databaseName = 'postgres';
			} else {
				throw new \LogicException('You should setup existing database name using testbench:dbname option.');
			}
		}

		$connection->close();
		$connection->__construct(
			['dbname' => $databaseName] + $connection->getParams(),
			$connection->getDriver(),
			$connection->getConfiguration(),
			$connection->getEventManager()
		);
		$connection->connect();
	}

}
