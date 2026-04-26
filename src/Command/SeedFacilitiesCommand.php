<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Facility;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:seed-facilities',
    description: 'Seed the database with default facilities',
)]
class SeedFacilitiesCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $facilities = [
            [
                'name' => 'CS Project Room',
                'capacity' => 48,
                'description' => 'Computer Science Project Room equipped with modern facilities and workstations for collaborative project work.',
            ],
            [
                'name' => 'Discussion Room 3',
                'capacity' => 6,
                'description' => 'Intimate discussion room perfect for small group meetings and focused discussions.',
            ],
            [
                'name' => 'Discussion Room 4',
                'capacity' => 8,
                'description' => 'Versatile discussion room suitable for group projects and team collaborations.',
            ],
            [
                'name' => 'Presentation Room 1',
                'capacity' => 40,
                'description' => 'Professional presentation room with advanced audio-visual equipment for seminars and presentations.',
            ],
            [
                'name' => 'Presentation Room 2',
                'capacity' => 60,
                'description' => 'Large presentation room designed for major conferences, lectures, and large-scale presentations.',
            ],
            [
                'name' => 'COE Project Room',
                'capacity' => 48,
                'description' => 'College of Engineering dedicated project room equipped for engineering-related collaborative work.',
            ],
            [
                'name' => 'Lounge Area',
                'capacity' => 150,
                'description' => 'Spacious lounge area perfect for networking, informal gatherings, and social events.',
            ],
        ];

        $facilityRepository = $this->entityManager->getRepository(Facility::class);

        foreach ($facilities as $data) {
            $existing = $facilityRepository->findOneBy(['name' => $data['name']]);

            if ($existing === null) {
                $facility = new Facility();
                $facility->setName($data['name']);
                $facility->setCapacity($data['capacity']);
                $facility->setDescription($data['description']);

                $this->entityManager->persist($facility);
                $io->success('Created facility: ' . $data['name']);
            } else {
                $io->info('Facility already exists: ' . $data['name']);
            }
        }

        $this->entityManager->flush();
        $io->success('All facilities have been seeded successfully!');

        return Command::SUCCESS;
    }
}
