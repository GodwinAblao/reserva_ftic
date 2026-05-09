<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\MentorCustomRequestRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MentorCustomRequestRepository::class)]
class MentorCustomRequest
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $student = null;

    #[ORM\ManyToOne(targetEntity: MentorProfile::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?MentorProfile $mentorProfile = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $message = null;

    #[ORM\Column(length: 50)]
    private string $status = 'pending';

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $createdAt;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $updatedAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $mentorResponse = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $fullName = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $departmentCourse = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $preferredExpertise = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $preferredSchedule = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $assignedMentorName = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $assignedMentorExpertise = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $availableDates = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $availableTime = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $meetingMethod = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $adminInstructions = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $respondedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStudent(): ?User
    {
        return $this->student;
    }

    public function setStudent(?User $student): self
    {
        $this->student = $student;

        return $this;
    }

    public function getMentorProfile(): ?MentorProfile
    {
        return $this->mentorProfile;
    }

    public function setMentorProfile(?MentorProfile $mentorProfile): self
    {
        $this->mentorProfile = $mentorProfile;

        return $this;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function setMessage(string $message): self
    {
        $this->message = $message;
        $this->touch();

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;
        $this->touch();

        return $this;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function getMentorResponse(): ?string
    {
        return $this->mentorResponse;
    }

    public function setMentorResponse(?string $mentorResponse): self
    {
        $this->mentorResponse = $mentorResponse;
        $this->touch();

        return $this;
    }

    public function getFullName(): ?string { return $this->fullName; }
    public function setFullName(?string $fullName): self { $this->fullName = $fullName; $this->touch(); return $this; }

    public function getDepartmentCourse(): ?string { return $this->departmentCourse; }
    public function setDepartmentCourse(?string $departmentCourse): self { $this->departmentCourse = $departmentCourse; $this->touch(); return $this; }

    public function getPreferredExpertise(): ?string { return $this->preferredExpertise; }
    public function setPreferredExpertise(?string $preferredExpertise): self { $this->preferredExpertise = $preferredExpertise; $this->touch(); return $this; }

    public function getPreferredSchedule(): ?string { return $this->preferredSchedule; }
    public function setPreferredSchedule(?string $preferredSchedule): self { $this->preferredSchedule = $preferredSchedule; $this->touch(); return $this; }

    public function getAssignedMentorName(): ?string { return $this->assignedMentorName; }
    public function setAssignedMentorName(?string $assignedMentorName): self { $this->assignedMentorName = $assignedMentorName; $this->touch(); return $this; }

    public function getAssignedMentorExpertise(): ?string { return $this->assignedMentorExpertise; }
    public function setAssignedMentorExpertise(?string $assignedMentorExpertise): self { $this->assignedMentorExpertise = $assignedMentorExpertise; $this->touch(); return $this; }

    public function getAvailableDates(): ?string { return $this->availableDates; }
    public function setAvailableDates(?string $availableDates): self { $this->availableDates = $availableDates; $this->touch(); return $this; }

    public function getAvailableTime(): ?string { return $this->availableTime; }
    public function setAvailableTime(?string $availableTime): self { $this->availableTime = $availableTime; $this->touch(); return $this; }

    public function getMeetingMethod(): ?string { return $this->meetingMethod; }
    public function setMeetingMethod(?string $meetingMethod): self { $this->meetingMethod = $meetingMethod; $this->touch(); return $this; }

    public function getAdminInstructions(): ?string { return $this->adminInstructions; }
    public function setAdminInstructions(?string $adminInstructions): self { $this->adminInstructions = $adminInstructions; $this->touch(); return $this; }

    public function getRespondedAt(): ?\DateTimeInterface { return $this->respondedAt; }
    public function markResponded(): self { $this->respondedAt = new \DateTime(); $this->touch(); return $this; }

    public function isAssistanceRequest(): bool
    {
        return $this->mentorProfile === null;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTime();
    }
}

