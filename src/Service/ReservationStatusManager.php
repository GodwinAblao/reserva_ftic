<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Reservation;
use App\Repository\ReservationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class ReservationStatusManager
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ReservationRepository $reservationRepo,
        private readonly ReservationAuditLogger $auditLogger,
    ) {
    }

    public function approve(Reservation $reservation, bool $isAjax = false): JsonResponse|array
    {
        if ($reservation->getReservationDate()->format('w') === '0') {
            return $this->failure('Cannot approve: facilities are closed on Sundays.', $isAjax);
        }

        if ($this->reservationRepo->isTimeRangeBooked(
            $reservation->getFacility(),
            $reservation->getReservationDate(),
            $reservation->getReservationStartTime(),
            $reservation->getReservationEndTime(),
            $reservation->getId(),
            ['Approved', 'Pending']
        )) {
            return $this->failure('Cannot approve: this time slot is already booked.', $isAjax);
        }

        $previous = $reservation->getStatus();
        $reservation->setStatus('Approved');
        $reservation->setUpdatedAt(new \DateTime());
        $this->auditLogger->logStatusChange($reservation, $previous, 'Approved', 'approve');
        $this->em->flush();

        return $this->success('Reservation approved successfully.', $isAjax);
    }

    public function reject(Reservation $reservation, string $reason, bool $isAjax = false): JsonResponse|array
    {
        $previous = $reservation->getStatus();
        $reservation->setStatus('Rejected');
        $reservation->setRejectionReason($reason);
        $reservation->setUpdatedAt(new \DateTime());
        $this->auditLogger->logStatusChange($reservation, $previous, 'Rejected', 'reject', $reason);
        $this->em->flush();

        return $this->success('Reservation rejected successfully.', $isAjax);
    }

    /**
     * @return array{ok: bool, message?: string}
     */
    public function applyManageableStatus(
        Reservation $reservation,
        string $newStatus,
        string $action = 'update',
        ?string $note = null,
    ): array {
        if (!ReservationAuditLogger::isManageableStatus($newStatus)) {
            return ['ok' => false, 'message' => 'Invalid status. Allowed: Pending, Approved, Rejected, Cancelled.'];
        }

        $previous = $reservation->getStatus();
        if ($previous === $newStatus) {
            return ['ok' => true];
        }

        if ($newStatus === 'Approved') {
            if ($reservation->getReservationDate()->format('w') === '0') {
                return ['ok' => false, 'message' => 'Cannot approve: facilities are closed on Sundays.'];
            }
            if ($this->reservationRepo->isTimeRangeBooked(
                $reservation->getFacility(),
                $reservation->getReservationDate(),
                $reservation->getReservationStartTime(),
                $reservation->getReservationEndTime(),
                $reservation->getId(),
                ['Approved', 'Pending']
            )) {
                return ['ok' => false, 'message' => 'Cannot approve: this time slot is already booked.'];
            }
        }

        $reservation->setStatus($newStatus);
        if ($newStatus === 'Rejected' && $note) {
            $reservation->setRejectionReason($note);
        }
        $reservation->setUpdatedAt(new \DateTime());
        $this->auditLogger->logStatusChange($reservation, $previous, $newStatus, $action, $note);
        $this->em->flush();

        return ['ok' => true];
    }

    private function success(string $message, bool $isAjax): JsonResponse|array
    {
        if ($isAjax) {
            return new JsonResponse(['success' => true, 'message' => $message]);
        }

        return ['success' => true, 'message' => $message];
    }

    private function failure(string $message, bool $isAjax): JsonResponse|array
    {
        if ($isAjax) {
            return new JsonResponse(['success' => false, 'message' => $message], Response::HTTP_BAD_REQUEST);
        }

        return ['success' => false, 'message' => $message];
    }
}
