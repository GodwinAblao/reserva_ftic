<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\MentoringAuditLogRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MentoringAuditLogRepository::class)]
#[ORM\Table(name: 'mentoring_audit_log')]
#[ORM\Index(columns: ['logged_at'], name: 'idx_mentoring_audit_log_logged_at')]
class MentoringAuditLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** 'application' | 'custom_request' */
    #[ORM\Column(length: 30)]
    private string $subjectType = '';

    #[ORM\Column(nullable: true)]
    private ?int $subjectId = null;

    /** Human-readable label, e.g. "John Doe" */
    #[ORM\Column(length: 120)]
    private string $subjectLabel = '';

    /** e.g. 'approve', 'reject', 'assign', 'create_mentor', 'update_status' */
    #[ORM\Column(length: 40)]
    private string $action = '';

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $previousStatus = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $newStatus = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $performedBy = null;

    #[ORM\Column(length: 60, nullable: true)]
    private ?string $performedByName = null;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $performedByRole = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $loggedAt;

    public function __construct()
    {
        $this->loggedAt = new \DateTime();
    }

    public function getId(): ?int { return $this->id; }

    public function getSubjectType(): string { return $this->subjectType; }
    public function setSubjectType(string $subjectType): static { $this->subjectType = $subjectType; return $this; }

    public function getSubjectId(): ?int { return $this->subjectId; }
    public function setSubjectId(?int $subjectId): static { $this->subjectId = $subjectId; return $this; }

    public function getSubjectLabel(): string { return $this->subjectLabel; }
    public function setSubjectLabel(string $subjectLabel): static { $this->subjectLabel = $subjectLabel; return $this; }

    public function getAction(): string { return $this->action; }
    public function setAction(string $action): static { $this->action = $action; return $this; }

    public function getPreviousStatus(): ?string { return $this->previousStatus; }
    public function setPreviousStatus(?string $previousStatus): static { $this->previousStatus = $previousStatus; return $this; }

    public function getNewStatus(): ?string { return $this->newStatus; }
    public function setNewStatus(?string $newStatus): static { $this->newStatus = $newStatus; return $this; }

    public function getPerformedBy(): ?User { return $this->performedBy; }
    public function setPerformedBy(?User $performedBy): static { $this->performedBy = $performedBy; return $this; }

    public function getPerformedByName(): ?string { return $this->performedByName; }
    public function setPerformedByName(?string $performedByName): static { $this->performedByName = $performedByName; return $this; }

    public function getPerformedByRole(): ?string { return $this->performedByRole; }
    public function setPerformedByRole(?string $performedByRole): static { $this->performedByRole = $performedByRole; return $this; }

    public function getNote(): ?string { return $this->note; }
    public function setNote(?string $note): static { $this->note = $note; return $this; }

    public function getLoggedAt(): \DateTimeInterface { return $this->loggedAt; }
    public function setLoggedAt(\DateTimeInterface $loggedAt): static { $this->loggedAt = $loggedAt; return $this; }
}
