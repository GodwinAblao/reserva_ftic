<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class MentorApplication
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $student = null;

    #[ORM\Column(length: 255)]
    private string $email = '';

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $firstName = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $middleName = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $lastName = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $contactNumber = null;

    #[ORM\Column(length: 255)]
    private string $specialization = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $reason = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $yearsOfExperience = null;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $currentProfession = null;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $highestEducation = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $supportingDescription = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $proofOfExpertise = null;

    #[ORM\Column(length: 50)]
    private string $status = 'Pending';

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $validUntil = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $adminNote = null;

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
    public function getStudent(): ?User { return $this->student; }
    public function setStudent(User $student): self { $this->student = $student; return $this; }
    public function getEmail(): string { return $this->email; }
    public function setEmail(string $email): self { $this->email = $email; $this->touch(); return $this; }

    public function getFirstName(): ?string { return $this->firstName; }
    public function setFirstName(?string $firstName): self { $this->firstName = $firstName; $this->touch(); return $this; }

    public function getMiddleName(): ?string { return $this->middleName; }
    public function setMiddleName(?string $middleName): self { $this->middleName = $middleName; $this->touch(); return $this; }

    public function getLastName(): ?string { return $this->lastName; }
    public function setLastName(?string $lastName): self { $this->lastName = $lastName; $this->touch(); return $this; }

    public function getContactNumber(): ?string { return $this->contactNumber; }
    public function setContactNumber(?string $contactNumber): self { $this->contactNumber = $contactNumber; $this->touch(); return $this; }

    public function getSpecialization(): string { return $this->specialization; }
    public function setSpecialization(string $specialization): self { $this->specialization = $specialization; $this->touch(); return $this; }
    public function getReason(): ?string { return $this->reason; }
    public function setReason(?string $reason): self { $this->reason = $reason; $this->touch(); return $this; }

    public function getYearsOfExperience(): ?int { return $this->yearsOfExperience; }
    public function setYearsOfExperience(?int $yearsOfExperience): self { $this->yearsOfExperience = $yearsOfExperience; $this->touch(); return $this; }

    public function getCurrentProfession(): ?string { return $this->currentProfession; }
    public function setCurrentProfession(?string $currentProfession): self { $this->currentProfession = $currentProfession; $this->touch(); return $this; }

    public function getHighestEducation(): ?string { return $this->highestEducation; }
    public function setHighestEducation(?string $highestEducation): self { $this->highestEducation = $highestEducation; $this->touch(); return $this; }

    public function getSupportingDescription(): ?string { return $this->supportingDescription; }
    public function setSupportingDescription(?string $supportingDescription): self { $this->supportingDescription = $supportingDescription; $this->touch(); return $this; }

    public function getProofOfExpertise(): ?array { return $this->proofOfExpertise; }
    public function setProofOfExpertise(?array $proofOfExpertise): self { $this->proofOfExpertise = $proofOfExpertise; $this->touch(); return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): self { $this->status = $status; $this->touch(); return $this; }

    public function getValidUntil(): ?\DateTimeInterface { return $this->validUntil; }
    public function setValidUntil(?\DateTimeInterface $validUntil): self { $this->validUntil = $validUntil; $this->touch(); return $this; }

    public function getAdminNote(): ?string { return $this->adminNote; }
    public function setAdminNote(?string $adminNote): self { $this->adminNote = $adminNote; $this->touch(); return $this; }
    public function getCreatedAt(): \DateTimeInterface { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeInterface { return $this->updatedAt; }

    private function touch(): void
    {
        $this->updatedAt = new \DateTime();
    }
}
