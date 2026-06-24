<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Reservation;
use App\Repository\ReservationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class ReservationAutoExpireService
{
    private const APP_TIMEZONE = 'Asia/Manila';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ReservationRepository $reservationRepo,
        private readonly ReservationAuditLogger $auditLogger,
        private readonly ReservationMailer $reservationMailer,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Cancel any Pending reservations whose start date+time has passed.
     *
     * @return array{cancelled: int, errors: int}
     */
    public function expireOverdue(): array
    {
        $tz       = new \DateTimeZone(self::APP_TIMEZONE);
        $now      = new \DateTimeImmutable('now', $tz);
        $tomorrow = new \DateTimeImmutable('tomorrow', $tz);

        $candidates = $this->reservationRepo
            ->createQueryBuilder('r')
            ->where('r.status = :status')
            ->andWhere('r.reservationDate < :tomorrow')
            ->setParameter('status', 'Pending')
            ->setParameter('tomorrow', $tomorrow)
            ->getQuery()
            ->getResult();

        $cancelled = 0;
        $errors    = 0;

        foreach ($candidates as $reservation) {
            if (!$this->isOverdue($reservation, $now, $tz)) {
                continue;
            }

            try {
                $previous = $reservation->getStatus();
                $reservation->setStatus('Cancelled');
                $reservation->setCancellationReason('Automatically cancelled: reservation date has passed without an admin response.');
                $reservation->setUpdatedAt(new \DateTime());

                $this->auditLogger->logStatusChange(
                    $reservation,
                    $previous,
                    'Cancelled',
                    'auto-expire',
                    'Reservation date passed without admin response.',
                );

                $this->em->flush();
                $this->reservationMailer->notifyExpired($reservation);

                $cancelled++;
                $this->logger->info('Overdue reservation auto-cancelled', [
                    'reservation_id' => $reservation->getId(),
                    'date'           => $reservation->getReservationDate()?->format('Y-m-d'),
                    'start_time'     => $reservation->getReservationStartTime()?->format('H:i'),
                    'email'          => $reservation->getEmail(),
                ]);
            } catch (\Throwable $e) {
                $errors++;
                $this->logger->error('Failed to auto-cancel overdue reservation', [
                    'reservation_id' => $reservation->getId(),
                    'error'          => $e->getMessage(),
                ]);
            }
        }

        return ['cancelled' => $cancelled, 'errors' => $errors];
    }

    /**
     * Expire only the reservations belonging to a specific user. Useful for the
     * "My Reservations" page where the user expects real-time status updates.
     *
     * @return array{cancelled: int, errors: int}
     */
    public function expireOverdueForUser(\App\Entity\User $user): array
    {
        $tz       = new \DateTimeZone(self::APP_TIMEZONE);
        $now      = new \DateTimeImmutable('now', $tz);
        $tomorrow = new \DateTimeImmutable('tomorrow', $tz);

        $candidates = $this->reservationRepo
            ->createQueryBuilder('r')
            ->where('r.status = :status')
            ->andWhere('r.user = :user')
            ->andWhere('r.reservationDate < :tomorrow')
            ->setParameter('status', 'Pending')
            ->setParameter('user', $user)
            ->setParameter('tomorrow', $tomorrow)
            ->getQuery()
            ->getResult();

        $cancelled = 0;
        $errors    = 0;

        foreach ($candidates as $reservation) {
            if (!$this->isOverdue($reservation, $now, $tz)) {
                continue;
            }

            try {
                $previous = $reservation->getStatus();
                $reservation->setStatus('Cancelled');
                $reservation->setCancellationReason('Automatically cancelled: reservation date has passed without an admin response.');
                $reservation->setUpdatedAt(new \DateTime());

                $this->auditLogger->logStatusChange(
                    $reservation,
                    $previous,
                    'Cancelled',
                    'auto-expire',
                    'Reservation date passed without admin response.',
                );

                $this->em->flush();
                $this->reservationMailer->notifyExpired($reservation);

                $cancelled++;
                $this->logger->info('Overdue reservation auto-cancelled for user', [
                    'reservation_id' => $reservation->getId(),
                    'user_id'        => $user->getId(),
                    'date'           => $reservation->getReservationDate()?->format('Y-m-d'),
                    'start_time'     => $reservation->getReservationStartTime()?->format('H:i'),
                ]);
            } catch (\Throwable $e) {
                $errors++;
                $this->logger->error('Failed to auto-cancel overdue reservation for user', [
                    'reservation_id' => $reservation->getId(),
                    'user_id'        => $user->getId(),
                    'error'          => $e->getMessage(),
                ]);
            }
        }

        return ['cancelled' => $cancelled, 'errors' => $errors];
    }

    private function isOverdue(Reservation $reservation, \DateTimeImmutable $now, \DateTimeZone $tz): bool
    {
        $date      = $reservation->getReservationDate();
        $startTime = $reservation->getReservationStartTime();
        if (!$date || !$startTime) {
            return false;
        }

        $startDatetime = \DateTimeImmutable::createFromFormat(
            'Y-m-d H:i:s',
            $date->format('Y-m-d') . ' ' . $startTime->format('H:i:s'),
            $tz,
        );

        return $startDatetime !== false && $startDatetime < $now;
    }
}
