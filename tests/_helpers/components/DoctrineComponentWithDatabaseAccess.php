<?php

use Tester\Assert;

class DoctrineComponentWithDatabaseAccess extends \Nette\Application\UI\Control
{

	public function __construct(\Kdyby\Doctrine\EntityManager $entityManager)
	{
		parent::__construct();

		$connection = $entityManager->getConnection();
		Assert::type('Testbench\Mocks\ConnectionMock', $connection); //not a service (listeners will not work)!
		Assert::false($connection->isConnected());
		Assert::count(1, $connection->onConnect);
		if ($connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\MySqlPlatform) {
			Assert::match('testbench_initial', $connection->getDatabase());
			Assert::truthy(preg_match('~testbench_[1-8]~', $connection->query('SELECT DATABASE();')->fetchColumn()));
		} else {
			Assert::truthy(preg_match('~testbench_[1-8]~', $connection->getDatabase()));
		}
	}

	public function render()
	{
		$this->template->render(__DIR__ . '/Component.latte');
	}

}
