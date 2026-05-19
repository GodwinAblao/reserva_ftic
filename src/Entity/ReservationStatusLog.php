<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ReservationStatusLogRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ReservationStatusLogRepository::class)]
#[ORM\Table(name: 'reservation_status_log')]
#[ORM\Index(columns: ['changed_at'], name: 'idx_reservation_status_log_changed_at')]
class ReservationStatusLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Reservation::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Reservation $reservation = null;

    #[ORM\Column(length: 50)]
    private string $previousStatus = '';

    #[ORM\Column(length: 50)]
    private string $newStatus = '';

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $changedBy = null;

    #[ORM\Column(length: 30)]
    private string $actorRoleLabel = '';

    #[ORM\Column(length: 30)]
    private string $action = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $changedAt;

    public function __construct()
    {
        $this->changedAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getReservation(): ?Reservation
    {
        return $this->reservation;
    }

    public function setReservation(?Reservation $reservation): static
    {
        $this->reservation = $reservation;

        return $this;
    }

    public function getPreviousStatus(): string
    {
        return $this->previousStatus;
    }

    public function setPreviousStatus(string $previousStatus): static
    {
        $this->previousStatus = $previousStatus;

        return $this;
    }

    public function getNewStatus(): string
    {
        return $this->newStatus;
    }

    public function setNewStatus(string $newStatus): static
    {
        $this->newStatus = $newStatus;

        return $this;
    }

    public function getChangedBy(): ?User
    {
        return $this->changedBy;
    }

    public function setChangedBy(?User $changedBy): static
    {
        $this->changedBy = $changedBy;

        return $this;
    }

    public function getActorRoleLabel(): string
    {
        return $this->actorRoleLabel;
    }

    public function setActorRoleLabel(string $actorRoleLabel): static
    {
        $this->actorRoleLabel = $actorRoleLabel;

        return $this;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function setAction(string $action): static
    {
        $this->action = $action;

        return $this;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): static
    {
        $this->note = $note;

        return $this;
    }

    public function getChangedAt(): \DateTimeInterface
    {
        return $this->changedAt;
    }

    public function setChangedAt(\DateTimeInterface $changedAt): static
    {
        $this->changedAt = $changedAt;

        return $this;
    }
}
