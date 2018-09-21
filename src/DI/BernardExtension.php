<?php

namespace Cellar\Bernard\DI;

use Bernard\Command\ConsumeCommand;
use Bernard\Command\Doctrine\CreateCommand;
use Bernard\Command\Doctrine\DropCommand;
use Bernard\Command\Doctrine\UpdateCommand;
use Bernard\Consumer;
use Bernard\Driver\DoctrineDriver;
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
            case 'doctrine':
                $this->registerDoctrineDriver($builder, $config['options']);
                break;
        }

        /**
         * QueueFactory
         */
        $builder->addDefinition($this->prefix('queue_factory'))
            ->setType(PersistentFactory::class);

        /**
         * Producer
         */
        $builder->addDefinition($this->prefix('producer'))
            ->setType(Producer::class);

        /**
         * DebugCommand
         */
        $debugCommand = $builder->addDefinition($this->prefix('debugCommand'))
            ->setType(DebugCommand::class);

        /**
         * ConsumeCommand
         */
        $consumeCommand = $builder->addDefinition($this->prefix('consume_command'))
            ->setType(ConsumeCommand::class);

        if (class_exists('Kdyby\Console\DI\ConsoleExtension')) {
            $debugCommand->addTag('kdyby.console.command');
            $consumeCommand->addTag('kdyby.console.command');
        }

        /**
         * Consumer
         */
        $builder->addDefinition($this->prefix('Consumer'))
            ->setType(Consumer::class);

        /**
         * Router
         */
        $builder->addDefinition($this->prefix('router'))
            ->setType(ContainerAwareRouter::class);
    }

    public function beforeCompile()
    {
        $builder = $this->getContainerBuilder();

        /**
         * Router
         */
        $builder
            ->getDefinition($this->prefix('router'))
            ->setArguments([
                'receivers' => $this->getReceiverServiceMap()
            ]);

        /** @var NormalizerInterface[] $normalizers */
        $normalizers = [];

        /**
         * EnvelopeNormalizer
         */
        $normalizers[] = $builder->addDefinition($this->prefix('envelope_normalizer'))
            ->setType(EnvelopeNormalizer::class)
            ->setAutowired(false);

        /**
         * PlainMessageNormalizer
         */
        $normalizers[] = $builder->addDefinition($this->prefix('plain_message_normalizer'))
            ->setType(PlainMessageNormalizer::class)
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
            ->setType(AggregateNormalizer::class)
            ->setAutowired(false)
            ->setArguments([
                'normalizers' => $normalizers
            ]);

        /**
         * Serializer
         */
        $builder->addDefinition($this->prefix('serializer'))
            ->setType(Serializer::class)
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
            ->setType(FlatFileDriver::class)
            ->setArguments([$config['directory']]);
    }


    private function registerDoctrineDriver(ContainerBuilder $builder, array $config): void
    {
        Validators::assertField($config, 'directory', 'string', 'flat file directory');

        $builder->addDefinition($this->prefix('driver'))
            ->setType(DoctrineDriver::class);

        /**
         * CreateCommand
         */
        $createCommand = $builder->addDefinition($this->prefix('createCommand'))
            ->setType(CreateCommand::class);

        /**
         * DropCommand
         */
        $dropCommand = $builder->addDefinition($this->prefix('dropCommand'))
            ->setType(DropCommand::class);

        /**
         * UpdateCommand
         */
        $updateCommand = $builder->addDefinition($this->prefix('updateCommand'))
            ->setType(UpdateCommand::class);

        if (class_exists('Kdyby\Console\DI\ConsoleExtension')) {
            $createCommand->addTag('kdyby.console.command');
            $updateCommand->addTag('kdyby.console.command');
            $dropCommand->addTag('kdyby.console.command');
        }
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