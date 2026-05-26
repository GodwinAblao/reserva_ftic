<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class MentorProfile
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    #[ORM\Column(length: 255)]
    private string $displayName = '';

    #[ORM\Column(length: 255)]
    private string $specialization = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $bio = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $education = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $availabilityDays = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $availabilityStart = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $availabilityEnd = null;

    #[ORM\Column]
    private int $engagementPoints = 0;

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

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(User $user): self
    {
        $this->user = $user;

        return $this;
    }

    public function getDisplayName(): string
    {
        return $this->displayName;
    }

    public function setDisplayName(string $displayName): self
    {
        $this->displayName = $displayName;

        return $this;
    }

    public function getSpecialization(): string
    {
        return $this->specialization;
    }

    public function setSpecialization(string $specialization): self
    {
        $this->specialization = $specialization;

        return $this;
    }

    public function getBio(): ?string
    {
        return $this->bio;
    }

    public function setBio(?string $bio): self
    {
        $this->bio = $bio;

        return $this;
    }

    public function getEducation(): ?string { return $this->education; }
    public function setEducation(?string $education): self { $this->education = $education; return $this; }

    public function getAvailabilityDays(): ?array {
        return $this->availabilityDays;
    }
    public function setAvailabilityDays(?array $availabilityDays): self { $this->availabilityDays = $availabilityDays; return $this; }

    // Backward compatibility getter for availabilityDay (returns comma-separated string)
    public function getAvailabilityDay(): ?string {
        $days = $this->getAvailabilityDays();
        if (empty($days)) {
            return null;
        }
        return implode(', ', $days);
    }
    public function setAvailabilityDay(?string $availabilityDay): self {
        if ($availabilityDay) {
            $this->availabilityDays = array_map('trim', explode(',', $availabilityDay));
        } else {
            $this->availabilityDays = null;
        }
        return $this;
    }

    public function getAvailabilityStart(): ?string { return $this->availabilityStart; }
    public function setAvailabilityStart(?string $availabilityStart): self { $this->availabilityStart = $availabilityStart; return $this; }

    public function getAvailabilityEnd(): ?string { return $this->availabilityEnd; }
    public function setAvailabilityEnd(?string $availabilityEnd): self { $this->availabilityEnd = $availabilityEnd; return $this; }

    public function getEngagementPoints(): int
    {
        return $this->engagementPoints;
    }

    public function setEngagementPoints(int $engagementPoints): self
    {
        $this->engagementPoints = $engagementPoints;

        return $this;
    }

    public function addEngagementPoints(int $points): self
    {
        $this->engagementPoints += $points;

        return $this;
    }

    /**
     * Check if a specific day of week is available
     * @param string $dayName Full day name (e.g., 'Monday', 'Tuesday')
     */
    public function isDayAvailable(string $dayName): bool
    {
        $days = $this->getAvailabilityDays();
        if (empty($days)) {
            return true; // If no days configured, allow all
        }
        return in_array($dayName, $days, true);
    }

    /**
     * Get availability as JSON string for JavaScript
     */
    public function getAvailabilityDaysJson(): string
    {
        return json_encode($this->getAvailabilityDays());
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }
}
