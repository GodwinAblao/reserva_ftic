<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ReservationConflictRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ReservationConflictRepository::class)]
#[ORM\Table(name: 'reservation_conflict')]
class ReservationConflict
{
    public const TYPE_CLASS_SCHEDULE = 'class_schedule';
    public const TYPE_RESERVATION    = 'reservation';
    public const TYPE_BLOCK          = 'block';

    public const RESOLUTION_ROOM_CHANGED    = 'room_changed';
    public const RESOLUTION_TIME_CHANGED    = 'time_changed';
    public const RESOLUTION_MOVED_ONLINE    = 'moved_online';
    public const RESOLUTION_CANCELLED       = 'cancelled';
    public const RESOLUTION_FACILITY_CHANGED = 'facility_changed';
    public const RESOLUTION_DATE_CHANGED    = 'date_changed';
    public const RESOLUTION_RESCHEDULED     = 'rescheduled';
    public const RESOLUTION_RELOCATED       = 'relocated';
    public const RESOLUTION_MANUAL          = 'manual';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Reservation::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Reservation $reservation = null;

    #[ORM\Column(length: 50)]
    private string $conflictType = self::TYPE_RESERVATION;

    #[ORM\Column]
    private int $conflictItemId = 0;

    #[ORM\Column(length: 500)]
    private string $conflictItemLabel = '';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $conflictItemFacility = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $conflictDate = null;

    #[ORM\Column(type: Types::TIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $conflictStartTime = null;

    #[ORM\Column(type: Types::TIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $conflictEndTime = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $conflictProfessor = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $conflictProfessorEmail = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $conflictCourse = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $conflictSection = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $conflictStatus = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $conflictOwner = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $conflictOwnerEmail = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $resolution = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $resolutionNotes = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $resolvedBy = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $resolvedAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $createdAt;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
    }

    public function getId(): ?int { return $this->id; }

    public function getReservation(): ?Reservation { return $this->reservation; }
    public function setReservation(?Reservation $reservation): static { $this->reservation = $reservation; return $this; }

    public function getConflictType(): string { return $this->conflictType; }
    public function setConflictType(string $conflictType): static { $this->conflictType = $conflictType; return $this; }

    public function getConflictItemId(): int { return $this->conflictItemId; }
    public function setConflictItemId(int $conflictItemId): static { $this->conflictItemId = $conflictItemId; return $this; }

    public function getConflictItemLabel(): string { return $this->conflictItemLabel; }
    public function setConflictItemLabel(string $conflictItemLabel): static { $this->conflictItemLabel = $conflictItemLabel; return $this; }

    public function getConflictItemFacility(): ?string { return $this->conflictItemFacility; }
    public function setConflictItemFacility(?string $conflictItemFacility): static { $this->conflictItemFacility = $conflictItemFacility; return $this; }

    public function getConflictDate(): ?\DateTimeInterface { return $this->conflictDate; }
    public function setConflictDate(?\DateTimeInterface $conflictDate): static { $this->conflictDate = $conflictDate; return $this; }

    public function getConflictStartTime(): ?\DateTimeInterface { return $this->conflictStartTime; }
    public function setConflictStartTime(?\DateTimeInterface $conflictStartTime): static { $this->conflictStartTime = $conflictStartTime; return $this; }

    public function getConflictEndTime(): ?\DateTimeInterface { return $this->conflictEndTime; }
    public function setConflictEndTime(?\DateTimeInterface $conflictEndTime): static { $this->conflictEndTime = $conflictEndTime; return $this; }

    public function getConflictProfessor(): ?string { return $this->conflictProfessor; }
    public function setConflictProfessor(?string $conflictProfessor): static { $this->conflictProfessor = $conflictProfessor; return $this; }

    public function getConflictProfessorEmail(): ?string { return $this->conflictProfessorEmail; }
    public function setConflictProfessorEmail(?string $conflictProfessorEmail): static { $this->conflictProfessorEmail = $conflictProfessorEmail; return $this; }

    public function getConflictCourse(): ?string { return $this->conflictCourse; }
    public function setConflictCourse(?string $conflictCourse): static { $this->conflictCourse = $conflictCourse; return $this; }

    public function getConflictSection(): ?string { return $this->conflictSection; }
    public function setConflictSection(?string $conflictSection): static { $this->conflictSection = $conflictSection; return $this; }

    public function getConflictStatus(): ?string { return $this->conflictStatus; }
    public function setConflictStatus(?string $conflictStatus): static { $this->conflictStatus = $conflictStatus; return $this; }

    public function getConflictOwner(): ?string { return $this->conflictOwner; }
    public function setConflictOwner(?string $conflictOwner): static { $this->conflictOwner = $conflictOwner; return $this; }

    public function getConflictOwnerEmail(): ?string { return $this->conflictOwnerEmail; }
    public function setConflictOwnerEmail(?string $conflictOwnerEmail): static { $this->conflictOwnerEmail = $conflictOwnerEmail; return $this; }

    public function getResolution(): ?string { return $this->resolution; }
    public function setResolution(?string $resolution): static { $this->resolution = $resolution; return $this; }

    public function getResolutionNotes(): ?string { return $this->resolutionNotes; }
    public function setResolutionNotes(?string $resolutionNotes): static { $this->resolutionNotes = $resolutionNotes; return $this; }

    public function getResolvedBy(): ?User { return $this->resolvedBy; }
    public function setResolvedBy(?User $resolvedBy): static { $this->resolvedBy = $resolvedBy; return $this; }

    public function getResolvedAt(): ?\DateTimeInterface { return $this->resolvedAt; }
    public function setResolvedAt(?\DateTimeInterface $resolvedAt): static { $this->resolvedAt = $resolvedAt; return $this; }

    public function isResolved(): bool { return $this->resolution !== null; }

    public function getCreatedAt(): \DateTimeInterface { return $this->createdAt; }
    public function setCreatedAt(\DateTimeInterface $createdAt): static { $this->createdAt = $createdAt; return $this; }

    public function getUpdatedAt(): \DateTimeInterface { return $this->updatedAt; }
    public function setUpdatedAt(\DateTimeInterface $updatedAt): static { $this->updatedAt = $updatedAt; return $this; }
}
