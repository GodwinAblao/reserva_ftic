<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ClassScheduleNotificationLogRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ClassScheduleNotificationLogRepository::class)]
#[ORM\Table(name: 'class_schedule_notification_log')]
class ClassScheduleNotificationLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ClassSchedule::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?ClassSchedule $classSchedule = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $notifiedBy = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $facultyUser = null;

    #[ORM\ManyToOne(targetEntity: Facility::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Facility $previousFacility = null;

    #[ORM\ManyToOne(targetEntity: Facility::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Facility $newFacility = null;

    #[ORM\Column(length: 180)]
    private string $recipientEmail = '';

    #[ORM\Column(length: 30)]
    private string $actorRoleLabel = '';

    #[ORM\Column(length: 30)]
    private string $channels = '';

    #[ORM\Column(type: Types::TEXT)]
    private string $message = '';

    #[ORM\Column(options: ['default' => false])]
    private bool $emailSent = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $inAppSent = false;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getClassSchedule(): ?ClassSchedule
    {
        return $this->classSchedule;
    }

    public function setClassSchedule(?ClassSchedule $classSchedule): self
    {
        $this->classSchedule = $classSchedule;

        return $this;
    }

    public function getNotifiedBy(): ?User
    {
        return $this->notifiedBy;
    }

    public function setNotifiedBy(?User $notifiedBy): self
    {
        $this->notifiedBy = $notifiedBy;

        return $this;
    }

    public function getFacultyUser(): ?User
    {
        return $this->facultyUser;
    }

    public function setFacultyUser(?User $facultyUser): self
    {
        $this->facultyUser = $facultyUser;

        return $this;
    }

    public function getPreviousFacility(): ?Facility
    {
        return $this->previousFacility;
    }

    public function setPreviousFacility(?Facility $previousFacility): self
    {
        $this->previousFacility = $previousFacility;

        return $this;
    }

    public function getNewFacility(): ?Facility
    {
        return $this->newFacility;
    }

    public function setNewFacility(?Facility $newFacility): self
    {
        $this->newFacility = $newFacility;

        return $this;
    }

    public function getRecipientEmail(): string
    {
        return $this->recipientEmail;
    }

    public function setRecipientEmail(string $recipientEmail): self
    {
        $this->recipientEmail = $recipientEmail;

        return $this;
    }

    public function getActorRoleLabel(): string
    {
        return $this->actorRoleLabel;
    }

    public function setActorRoleLabel(string $actorRoleLabel): self
    {
        $this->actorRoleLabel = $actorRoleLabel;

        return $this;
    }

    public function getChannels(): string
    {
        return $this->channels;
    }

    public function setChannels(string $channels): self
    {
        $this->channels = $channels;

        return $this;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function setMessage(string $message): self
    {
        $this->message = $message;

        return $this;
    }

    public function isEmailSent(): bool
    {
        return $this->emailSent;
    }

    public function setEmailSent(bool $emailSent): self
    {
        $this->emailSent = $emailSent;

        return $this;
    }

    public function isInAppSent(): bool
    {
        return $this->inAppSent;
    }

    public function setInAppSent(bool $inAppSent): self
    {
        $this->inAppSent = $inAppSent;

        return $this;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }
}
