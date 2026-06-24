<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\ReservationAutoExpireService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:reservations:expire-overdue',
    description: 'Automatically cancels Pending reservations whose date has passed and notifies the user by email.',
)]
class ExpireOverdueReservationsCommand extends Command
{
    public function __construct(
        private readonly ReservationAutoExpireService $autoExpireService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Expire Overdue Pending Reservations');

        $result = $this->autoExpireService->expireOverdue();

        if ($result['cancelled'] === 0 && $result['errors'] === 0) {
            $io->success('No overdue pending reservations found.');
            return Command::SUCCESS;
        }

        $io->success(sprintf('Done. Cancelled: %d | Errors: %d', $result['cancelled'], $result['errors']));

        return Command::SUCCESS;
    }
}
