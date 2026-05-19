<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\ClassScheduleRepository;
use App\Repository\FacilityScheduleBlockRepository;
use App\Repository\ReservationRepository;

class ScheduleRevisionService
{
    public function __construct(
        private readonly ClassScheduleRepository $classScheduleRepo,
        private readonly FacilityScheduleBlockRepository $blockRepo,
        private readonly ReservationRepository $reservationRepo,
    ) {
    }

    public function getRevision(): string
    {
        return sha1(implode('|', [
            'classes:' . $this->classScheduleRepo->getGlobalRevisionToken(),
            'blocks:' . $this->blockRepo->getGlobalRevisionToken(),
            'reservations:' . $this->reservationRepo->getAvailabilityRevisionToken(),
        ]));
    }
}
