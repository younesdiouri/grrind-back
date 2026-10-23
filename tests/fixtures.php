<?php

declare(strict_types=1);

use App\Kernel;
use App\Tests\Support\EquipmentCatalogFixture;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;

require __DIR__.'/bootstrap.php';

if ('test' !== ($_SERVER['APP_ENV'] ?? null)) {
    throw new LogicException('Les fixtures ne se chargent que dans APP_ENV=test.');
}
$kernel = new Kernel('test', true);
$kernel->boot();
try {
    $doctrine = $kernel->getContainer()->get('doctrine');
    assert($doctrine instanceof ManagerRegistry);
    $db = $doctrine->getConnection();
    assert($db instanceof Connection);
    EquipmentCatalogFixture::install($db);
} finally {
    $kernel->shutdown();
}
