<?php

namespace Cellar\Bernard\Router;

use Bernard\Router\SimpleRouter;
use Nette\DI\Container;

class ContainerAwareRouter extends SimpleRouter
{
	/** @var Container */
	private $container;

	public function __construct(Container $container, array $receivers = [])
	{
		$this->container = $container;
		parent::__construct($receivers);
	}

	/**
	 * @inheritdoc
	 */
	protected function get($name)
	{
		$serviceId = parent::get($name);
		return $this->container->getService($serviceId);
	}

	/**
	 * @inheritdoc
	 */
	protected function accepts($receiver)
	{
		return $this->container->hasService($receiver);
	}
}