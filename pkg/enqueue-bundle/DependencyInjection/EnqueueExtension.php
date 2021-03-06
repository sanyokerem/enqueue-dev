<?php

namespace Enqueue\Bundle\DependencyInjection;

use Enqueue\AsyncCommand\DependencyInjection\AsyncCommandExtension;
use Enqueue\AsyncEventDispatcher\DependencyInjection\AsyncEventDispatcherExtension;
use Enqueue\Client\CommandSubscriberInterface;
use Enqueue\Client\DriverInterface;
use Enqueue\Client\Producer;
use Enqueue\Client\TopicSubscriberInterface;
use Enqueue\Client\TraceableProducer;
use Enqueue\Consumption\QueueConsumer;
use Enqueue\JobQueue\Job;
use Enqueue\Symfony\DependencyInjection\TransportFactory;
use Interop\Queue\PsrConnectionFactory;
use Interop\Queue\PsrContext;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\DependencyInjection\Alias;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;

final class EnqueueExtension extends Extension implements PrependExtensionInterface
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->processConfiguration($this->getConfiguration($configs, $container), $configs);

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
        $loader->load('services.yml');

        $this->setupAutowiringForProcessors($container);

        $transportFactory = (new TransportFactory('default'));
        $transportFactory->createConnectionFactory($container, $config['transport']);
        $transportFactory->createContext($container, $config['transport']);

        if (isset($config['client'])) {
            $loader->load('client.yml');
            $loader->load('extensions/flush_spool_producer_extension.yml');
            $loader->load('extensions/exclusive_command_extension.yml');

            $transportFactory->createDriver($container, $config['transport']);

            $configDef = $container->getDefinition('enqueue.client.config');
            $configDef->setArguments([
                $config['client']['prefix'],
                $config['client']['app_name'],
                $config['client']['router_topic'],
                $config['client']['router_queue'],
                $config['client']['default_processor_queue'],
                $config['client']['router_processor'],
                // @todo should be driver options.
                $config['transport'],
            ]);

            $container->setParameter('enqueue.client.router_queue_name', $config['client']['router_queue']);
            $container->setParameter('enqueue.client.default_queue_name', $config['client']['default_processor_queue']);

            if ($config['client']['traceable_producer']) {
                $container->register(TraceableProducer::class, TraceableProducer::class)
                    ->setDecoratedService(Producer::class)
                    ->setPublic(true)
                    ->addArgument(new Reference(sprintf('%s.inner', TraceableProducer::class)))
                ;
            }

            if ($config['client']['redelivered_delay_time']) {
                $loader->load('extensions/delay_redelivered_message_extension.yml');

                $container->getDefinition('enqueue.client.delay_redelivered_message_extension')
                    ->replaceArgument(1, $config['client']['redelivered_delay_time'])
                ;
            }
        }

        // configure queue consumer
        $container->getDefinition(QueueConsumer::class)
            ->replaceArgument(2, $config['consumption']['idle_timeout'])
            ->replaceArgument(3, $config['consumption']['receive_timeout'])
        ;

        if ($container->hasDefinition('enqueue.client.queue_consumer')) {
            $container->getDefinition('enqueue.client.queue_consumer')
                ->replaceArgument(2, $config['consumption']['idle_timeout'])
                ->replaceArgument(3, $config['consumption']['receive_timeout'])
            ;
        }

        if ($config['job']) {
            if (!class_exists(Job::class)) {
                throw new \LogicException('Seems "enqueue/job-queue" is not installed. Please fix this issue.');
            }

            $loader->load('job.yml');
        }

        if ($config['async_events']['enabled']) {
            if (false == class_exists(AsyncEventDispatcherExtension::class)) {
                throw new \LogicException('The "enqueue/async-event-dispatcher" package has to be installed.');
            }

            $extension = new AsyncEventDispatcherExtension();
            $extension->load([[
                'context_service' => 'enqueue.transport.default.context',
            ]], $container);
        }

        if ($config['async_commands']['enabled']) {
            if (false == class_exists(AsyncCommandExtension::class)) {
                throw new \LogicException('The "enqueue/async-command" package has to be installed.');
            }

            $extension = new AsyncCommandExtension();
            $extension->load([[]], $container);
        }

        if ($config['extensions']['doctrine_ping_connection_extension']) {
            $loader->load('extensions/doctrine_ping_connection_extension.yml');
        }

        if ($config['extensions']['doctrine_clear_identity_map_extension']) {
            $loader->load('extensions/doctrine_clear_identity_map_extension.yml');
        }

        if ($config['extensions']['signal_extension']) {
            $loader->load('extensions/signal_extension.yml');
        }

        if ($config['extensions']['reply_extension']) {
            $loader->load('extensions/reply_extension.yml');
        }
    }

    public function getConfiguration(array $config, ContainerBuilder $container): Configuration
    {
        $rc = new \ReflectionClass(Configuration::class);

        $container->addResource(new FileResource($rc->getFileName()));

        return new Configuration($container->getParameter('kernel.debug'));
    }

    public function prepend(ContainerBuilder $container): void
    {
        $this->registerJobQueueDoctrineEntityMapping($container);
    }

    private function registerJobQueueDoctrineEntityMapping(ContainerBuilder $container)
    {
        if (!class_exists(Job::class)) {
            return;
        }

        $bundles = $container->getParameter('kernel.bundles');

        if (!isset($bundles['DoctrineBundle'])) {
            return;
        }

        foreach ($container->getExtensionConfig('doctrine') as $config) {
            // do not register mappings if dbal not configured.
            if (!empty($config['dbal'])) {
                $rc = new \ReflectionClass(Job::class);
                $jobQueueRootDir = dirname($rc->getFileName());
                $container->prependExtensionConfig('doctrine', [
                    'orm' => [
                        'mappings' => [
                            'enqueue_job_queue' => [
                                'is_bundle' => false,
                                'type' => 'xml',
                                'dir' => $jobQueueRootDir.'/Doctrine/mapping',
                                'prefix' => 'Enqueue\JobQueue\Doctrine\Entity',
                            ],
                        ],
                    ],
                ]);
                break;
            }
        }
    }

    private function setupAutowiringForProcessors(ContainerBuilder $container)
    {
        $container->registerForAutoconfiguration(TopicSubscriberInterface::class)
            ->setPublic(true)
            ->addTag('enqueue.client.processor');

        $container->registerForAutoconfiguration(CommandSubscriberInterface::class)
            ->setPublic(true)
            ->addTag('enqueue.client.processor');
    }

    private function createConnectionFactory(ContainerBuilder $container, array $config): string
    {
        $factoryId = sprintf('enqueue.transport.%s.connection_factory', $this->getName());

        $container->register($factoryId, PsrConnectionFactory::class)
            ->setFactory([new Reference('enqueue.connection_factory_factory'), 'create'])
            ->addArgument($config['dsn'])
        ;

        $container->setAlias('enqueue.transport.connection_factory', new Alias($factoryId, true));

        return $factoryId;
    }

    private function createContext(ContainerBuilder $container, array $config): string
    {
        $contextId = sprintf('enqueue.transport.%s.context', $this->getName());
        $factoryId = sprintf('enqueue.transport.%s.connection_factory', $this->getName());

        $container->register($contextId, PsrContext::class)
            ->setFactory([new Reference($factoryId), 'createContext'])
        ;

        $container->setAlias('enqueue.transport.context', new Alias($contextId, true));

        return $contextId;
    }

    private function createDriver(ContainerBuilder $container, array $config): string
    {
        $factoryId = sprintf('enqueue.transport.%s.connection_factory', $this->getName());
        $driverId = sprintf('enqueue.client.%s.driver', $this->getName());

        $container->register($driverId, DriverInterface::class)
            ->setFactory([new Reference('enqueue.client.driver_factory'), 'create'])
            ->addArgument(new Reference($factoryId))
            ->addArgument($config['dsn'])
            ->addArgument($config)
        ;

        $container->setAlias('enqueue.client.driver', new Alias($driverId, true));

        return $driverId;
    }

    private function getName(): string
    {
        return 'default';
    }
}
