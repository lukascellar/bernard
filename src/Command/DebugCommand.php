<?php

namespace Cellar\Bernard\Command;

use Bernard\Router;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DebugCommand extends Command
{
	/** @var Router */
	private $router;

	public function __construct(Router $router)
	{
		$this->router = $router;
		parent::__construct('bernard:debug');
	}

	public function configure()
	{
		$this
			->setName('bernard:debug')
			->setDescription('Displays a table of receivers that are registered with "bernard.receiver" tag.');
	}

	protected function execute(InputInterface $input, OutputInterface $output)
	{
		$r = new \ReflectionProperty($this->router, 'receivers');
		$r->setAccessible(true);
		$rows = [];

		foreach ($r->getValue($this->router) as $key => $val) {
			$rows[] = [$key, $val];
		}

		$headers = ['Message', 'Service'];

		if (class_exists('Symfony\Component\Console\Helper\Table')) {
			$table = new Table($output);
			$table
				->setHeaders($headers)
				->addRows($rows)
				->render()
			;
		} else {
			/** @var \Symfony\Component\Console\Helper\Table $helper */
			$helper = $this->getHelper('table');
			$helper
				->setHeaders($headers)
				->addRows($rows)
				->render($output)
			;
		}
	}
}