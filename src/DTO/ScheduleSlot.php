<?php

declare(strict_types=1);

namespace App\DTO;

use App\Entity\Facility;

final class ScheduleSlot
{
    public function __construct(
        public readonly Facility $facility,
        public readonly \DateTime $date,
        public readonly \DateTime $start,
        public readonly \DateTime $end,
    ) {}
}
