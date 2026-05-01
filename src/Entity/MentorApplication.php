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
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $student = null;

    #[ORM\Column(length: 180)]
    private string $email = '';

    #[ORM\Column(type: Types::TEXT)]
    private string $reason = '';

    #[ORM\Column(length: 255)]
    private string $specialization = '';

    #[ORM\Column(length: 10)]
    private string $otpCode = '';

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $otpExpiresAt;

    #[ORM\Column]
    private bool $isOtpVerified = false;

    #[ORM\Column(length: 50)]
    private string $status = 'Awaiting OTP';

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
        $this->otpExpiresAt = new \DateTime('+10 minutes');
    }

    public function getId(): ?int { return $this->id; }
    public function getStudent(): ?User { return $this->student; }
    public function setStudent(User $student): self { $this->student = $student; return $this; }
    public function getEmail(): string { return $this->email; }
    public function setEmail(string $email): self { $this->email = $email; $this->touch(); return $this; }
    public function getReason(): string { return $this->reason; }
    public function setReason(string $reason): self { $this->reason = $reason; $this->touch(); return $this; }
    public function getSpecialization(): string { return $this->specialization; }
    public function setSpecialization(string $specialization): self { $this->specialization = $specialization; $this->touch(); return $this; }
    public function getOtpCode(): string { return $this->otpCode; }
    public function setOtpCode(string $otpCode): self { $this->otpCode = $otpCode; $this->touch(); return $this; }
    public function getOtpExpiresAt(): \DateTimeInterface { return $this->otpExpiresAt; }
    public function setOtpExpiresAt(\DateTimeInterface $otpExpiresAt): self { $this->otpExpiresAt = $otpExpiresAt; $this->touch(); return $this; }
    public function isOtpVerified(): bool { return $this->isOtpVerified; }
    public function setIsOtpVerified(bool $isOtpVerified): self { $this->isOtpVerified = $isOtpVerified; $this->touch(); return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): self { $this->status = $status; $this->touch(); return $this; }
    public function getAdminNote(): ?string { return $this->adminNote; }
    public function setAdminNote(?string $adminNote): self { $this->adminNote = $adminNote; $this->touch(); return $this; }
    public function getCreatedAt(): \DateTimeInterface { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeInterface { return $this->updatedAt; }

    private function touch(): void
    {
        $this->updatedAt = new \DateTime();
    }
}
