<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function getConfigDir(): string
    {
        return str_replace('\\', '/', $this->getProjectDir() . '/config');
    }

    private function configureContainer(ContainerConfigurator $container, LoaderInterface $loader, ContainerBuilder $builder): void
    {
        $configDir = $this->getConfigDir();

        $container->import($configDir . '/packages/*.{php,yaml}');
        if (is_dir($configDir . '/packages/' . $this->environment)) {
            $container->import($configDir . '/packages/' . $this->environment . '/*.{php,yaml}');
        }

        if (is_file($configDir . '/services.yaml')) {
            $container->import($configDir . '/services.yaml');
            if (is_file($configDir . '/services_' . $this->environment . '.yaml')) {
                $container->import($configDir . '/services_' . $this->environment . '.yaml');
            }
        } else {
            $container->import($configDir . '/services.php');
            if (is_file($configDir . '/services_' . $this->environment . '.php')) {
                $container->import($configDir . '/services_' . $this->environment . '.php');
            }
        }
    }

    private function configureRoutes(RoutingConfigurator $routes): void
    {
        $configDir = $this->getConfigDir();

        if (is_dir($configDir . '/routes/' . $this->environment)) {
            $routes->import($configDir . '/routes/' . $this->environment . '/*.{php,yaml}');
        }
        $routes->import($configDir . '/routes/*.{php,yaml}');
        $routes->import($configDir . '/routes.yaml');
        $routes->import(__FILE__, 'attribute');
    }
}
