<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();
        if ($app['config']->get('database.default') !== 'mysql' || $app['config']->get('database.connections.mysql.database') !== 'portal_test' || ! in_array($app['config']->get('database.connections.mysql.host'), ['127.0.0.1', 'mysql-test'], true)) {
            throw new \RuntimeException('Tes hanya boleh berjalan pada MySQL portal_test yang terisolasi.');
        }

        return $app;
    }
}
