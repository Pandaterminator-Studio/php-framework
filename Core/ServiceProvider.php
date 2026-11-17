<?php

namespace Core;

abstract class ServiceProvider
{
    public function register(Container $container, Config $config): void
    {
    }

    public function boot(Application $application): void
    {
    }
}
