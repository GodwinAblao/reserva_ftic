<?php
require dirname(__DIR__, 3) . '/vendor/autoload.php';

use App\Entity\Facility;
use Doctrine\ORM\EntityManagerInterface;

$kernel = new \App\Kernel('dev', true);
$kernel->boot();
$container = $kernel->getContainer();
$em = $container->get(EntityManagerInterface::class);

$facilities = [
    ['3D Printing Lab', 20, '3D Printing Laboratory'],
    ['Lounge 1', 30, 'Lounge Area Section 1'],
    ['Lounge 2', 30, 'Lounge Area Section 2'],
    ['Lounge 3', 30, 'Lounge Area Section 3'],
    ['Lounge 4', 30, 'Lounge Area Section 4'],
];

$repo = $em->getRepository(Facility::class);
$added = 0;

foreach ($facilities as [$name, $capacity, $desc]) {
    $existing = $repo->findOneBy(['name' => $name]);
    if (!$existing) {
        $facility = new Facility();
        $facility->setName($name);
        $facility->setCapacity($capacity);
        $facility->setDescription($desc);
        $em->persist($facility);
        $added++;
        echo "Adding: $name\n";
    } else {
        echo "Already exists: $name\n";
    }
}

$em->flush();
echo "\nAdded $added new facilities!\n";
