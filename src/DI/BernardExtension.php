<?php

namespace Cellar\Bernard\DI;

use Bernard\Command\ConsumeCommand;
use Bernard\Consumer;
use Bernard\Driver\FlatFileDriver;
use Bernard\Normalizer\EnvelopeNormalizer;
use Bernard\Normalizer\PlainMessageNormalizer;
use Bernard\Producer;
use Bernard\QueueFactory\PersistentFactory;
use Bernard\Serializer;
use Cellar\Bernard\Command\DebugCommand;
use Cellar\Bernard\Router\ContainerAwareRouter;
use Nette\DI\CompilerExtension;
use Nette\DI\ContainerBuilder;
use Nette\DI\ServiceDefinition;
use Nette\Utils\Validators;
use Normalt\Normalizer\AggregateNormalizer;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

class BernardExtension extends CompilerExtension
{
	const TAG_RECEIVER = 'bernard.receiver';

	private $defaultConfiguration = [
		'driver' => null,
		'options' => []
	];

	public function loadConfiguration()
	{
		$builder = $this->getContainerBuilder();
		$config = $this->getConfig($this->defaultConfiguration);

		Validators::assert($config['driver'], 'string', 'bernard driver');

		switch ($config['driver']) {
			case 'file':
				$this->registerFlatFileDriver($builder, $config['options']);
				break;
		}

		/**
		 * QueueFactory
		 */
		$builder->addDefinition($this->prefix('queue_factory'))
			->setClass(PersistentFactory::class);

		/**
		 * Producer
		 */
		$builder->addDefinition($this->prefix('producer'))
			->setClass(Producer::class);

		/**
		 * DebugCommand
		 */
		$debugCommand = $builder->addDefinition($this->prefix('debugCommand'))
			->setClass(DebugCommand::class);

		/**
		 * ConsumeCommand
		 */
		$consumeCommand = $builder->addDefinition($this->prefix('consume_command'))
			->setClass(ConsumeCommand::class);

		if (class_exists('Kdyby\Console\DI\ConsoleExtension')) {
			$debugCommand->addTag('kdyby.console.command');
			$consumeCommand->addTag('kdyby.console.command');
		}
	}

	public function beforeCompile()
	{
		$builder = $this->getContainerBuilder();

		/**
		 * Consumer
		 */
		$builder->addDefinition($this->prefix('Consumer'))
			->setClass(Consumer::class);

		/**
		 * Router
		 */
		$builder->addDefinition($this->prefix('router'))
			->setClass(ContainerAwareRouter::class)
			->setArguments([
				'receivers' => $this->getReceiverServiceMap()
			]);

		/** @var NormalizerInterface[] $normalizers */
		$normalizers = [];

		/**
		 * EnvelopeNormalizer
		 */
		$normalizers[] = $builder->addDefinition($this->prefix('envelope_normalizer'))
			->setClass(EnvelopeNormalizer::class)
			->setAutowired(false);

		/**
		 * PlainMessageNormalizer
		 */
		$normalizers[] = $builder->addDefinition($this->prefix('plain_message_normalizer'))
			->setClass(PlainMessageNormalizer::class)
			->setAutowired(false);

		/**
		 * Fallback Symfony Serializer
		 */
		$symfonySerializer = $builder->getByType('Symfony\Component\Serializer\Serializer');

		if ($symfonySerializer !== null) {
			$normalizers[] = $builder->getDefinition($symfonySerializer);
		}

		/**
		 * AggregateNormalizer
		 */
		$aggregateNormalizer = $builder->addDefinition($this->prefix('aggregate_normalizer'))
			->setClass(AggregateNormalizer::class)
			->setAutowired(false)
			->setArguments([
				'normalizers' => $normalizers
			]);

		/**
		 * Serializer
		 */
		$builder->addDefinition($this->prefix('serializer'))
			->setClass(Serializer::class)
			->setArguments([
				$aggregateNormalizer
			]);
	}

	/**
	 * @param ContainerBuilder $builder
	 * @param array $config
	 */
	private function registerFlatFileDriver(ContainerBuilder $builder, array $config): void
	{
		Validators::assertField($config, 'directory', 'string', 'flat file directory');

		$builder->addDefinition($this->prefix('driver'))
			->setClass(FlatFileDriver::class)
			->setArguments([$config['directory']]);
	}

	/**
	 * @return array
	 */
	private function getReceiverServiceMap(): array
	{
		$receiverServiceMap = [];

		foreach ($this->findReceiverDefinitions() as $name => $definition) {
			foreach ($definition->getTags() as $tag) {
				$receiverServiceMap[$tag['message']] = $name;
			}
		}

		return $receiverServiceMap;
	}

	/**
	 * @return ServiceDefinition[]
	 */
	private function findReceiverDefinitions(): array
	{
		$definitions = [];
		$builder = $this->getContainerBuilder();

		foreach ($builder->getDefinitions() as $name => $definition) {
			foreach ($definition->getTags() as $tag) {
				if (isset($tag['name']) && $tag['name'] === self::TAG_RECEIVER) {
					$definitions[$name] = $definition;
				}
			}
		}

		return $definitions;
	}
}