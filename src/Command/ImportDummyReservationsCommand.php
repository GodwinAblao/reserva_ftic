<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Facility;
use App\Entity\Reservation;
use App\Entity\User;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:import-dummy-reservations',
    description: 'Import dummy reservation data from CSV for analytics testing',
)]
class ImportDummyReservationsCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $csvPath = __DIR__ . '/../../data/dummy_reservations.csv';
        if (!file_exists($csvPath)) {
            $io->error("CSV file not found: $csvPath");
            return Command::FAILURE;
        }

        $handle = fopen($csvPath, 'r');
        if (!$handle) {
            $io->error("Cannot open CSV file");
            return Command::FAILURE;
        }

        // Skip header
        $header = fgetcsv($handle);
        if (!$header) {
            $io->error("CSV file is empty");
            return Command::FAILURE;
        }

        $imported = 0;
        $skipped = 0;
        $facilityRepo = $this->entityManager->getRepository(Facility::class);
        $reservationRepo = $this->entityManager->getRepository(Reservation::class);

        while (($row = fgetcsv($handle)) !== false) {
            $data = array_combine($header, $row);
            if (!$data) {
                continue;
            }

            // Check if already exists (by email + reservation_date combination)
            $existing = $reservationRepo->createQueryBuilder('r')
                ->where('r.email = :email')
                ->andWhere('r.reservationDate = :date')
                ->andWhere('r.isSimulated = true')
                ->setParameter('email', $data['email'])
                ->setParameter('date', new DateTime($data['reservation_date']))
                ->getQuery()
                ->getOneOrNullResult();

            if ($existing) {
                $skipped++;
                continue;
            }

            // Find facility by name
            $facility = $facilityRepo->findOneBy(['name' => $data['facility_name']]);
            if (!$facility) {
                $io->warning("Facility not found: {$data['facility_name']} - skipping row");
                continue;
            }

            // Create reservation
            $reservation = new Reservation();
            $reservation->setUser(null); // Dummy data has no real user
            $reservation->setFacility($facility);
            $reservation->setName($data['name']);
            $reservation->setEmail($data['email']);
            $reservation->setContact($data['contact']);
            $reservation->setReservationDate(new DateTime($data['reservation_date']));
            $reservation->setReservationStartTime(new DateTime($data['reservation_start_time']));
            $reservation->setReservationEndTime(new DateTime($data['reservation_end_time']));
            $reservation->setCapacity((int) $data['capacity']);
            $reservation->setPurpose($data['purpose']);
            $reservation->setStatus($data['status']);
            $reservation->setCreatedAt(new DateTime($data['created_at']));
            $reservation->setUpdatedAt(new DateTime($data['updated_at']));
            $reservation->setRejectionReason($data['rejection_reason'] ?: null);
            $reservation->setEventName($data['name'] ?? null); // Use name as event_name
            $reservation->setIsSimulated(true); // Mark as simulated data

            $this->entityManager->persist($reservation);
            $imported++;

            // Flush every 20 records to manage memory
            if ($imported % 20 === 0) {
                $this->entityManager->flush();
                $this->entityManager->clear();
                $io->writeln("Imported $imported records...");
            }
        }

        fclose($handle);

        // Final flush
        $this->entityManager->flush();
        $this->entityManager->clear();

        $io->success("Import complete: $imported imported, $skipped skipped (already existed)");

        return Command::SUCCESS;
    }
}
