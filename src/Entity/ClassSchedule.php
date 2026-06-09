<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ClassScheduleRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ClassScheduleRepository::class)]
#[ORM\Table(name: 'class_schedule')]
class ClassSchedule
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Facility::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Facility $facility = null;

    #[ORM\ManyToOne(targetEntity: Facility::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Facility $previousFacility = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $facultyUser = null;

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    private ?\DateTimeInterface $scheduleDate = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $dayOfWeek = null;

    #[ORM\Column(type: Types::TIME_MUTABLE)]
    private ?\DateTimeInterface $startTime = null;

    #[ORM\Column(type: Types::TIME_MUTABLE)]
    private ?\DateTimeInterface $endTime = null;

    #[ORM\Column(length: 50)]
    private string $courseCode = '';

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $section = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $facultyName = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $facultyEmail = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $source = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $importBatchId = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $scheduleIdentifier = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $isRelocated = false;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $status = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $startDate = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $endDate = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $term = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $createdAt;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFacility(): ?Facility
    {
        return $this->facility;
    }

    public function setFacility(?Facility $facility): self
    {
        $this->facility = $facility;

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

    public function getFacultyUser(): ?User
    {
        return $this->facultyUser;
    }

    public function setFacultyUser(?User $facultyUser): self
    {
        $this->facultyUser = $facultyUser;

        return $this;
    }

    public function getScheduleDate(): ?\DateTimeInterface
    {
        return $this->scheduleDate;
    }

    public function setScheduleDate(\DateTimeInterface $scheduleDate): self
    {
        $this->scheduleDate = $scheduleDate;

        return $this;
    }

    public function getDayOfWeek(): ?string
    {
        return $this->dayOfWeek;
    }

    public function setDayOfWeek(?string $dayOfWeek): self
    {
        $this->dayOfWeek = $dayOfWeek;

        return $this;
    }

    public function getStartTime(): ?\DateTimeInterface
    {
        return $this->startTime;
    }

    public function setStartTime(\DateTimeInterface $startTime): self
    {
        $this->startTime = $startTime;

        return $this;
    }

    public function getEndTime(): ?\DateTimeInterface
    {
        return $this->endTime;
    }

    public function setEndTime(\DateTimeInterface $endTime): self
    {
        $this->endTime = $endTime;

        return $this;
    }

    public function getCourseCode(): string
    {
        return $this->courseCode;
    }

    public function setCourseCode(string $courseCode): self
    {
        $this->courseCode = $courseCode;

        return $this;
    }

    public function getSection(): ?string
    {
        return $this->section;
    }

    public function setSection(?string $section): self
    {
        $this->section = $section;

        return $this;
    }

    public function getFacultyName(): ?string
    {
        return $this->facultyName;
    }

    public function setFacultyName(?string $facultyName): self
    {
        $this->facultyName = $facultyName;

        return $this;
    }

    public function getFacultyEmail(): ?string
    {
        return $this->facultyEmail;
    }

    public function setFacultyEmail(?string $facultyEmail): self
    {
        $this->facultyEmail = $facultyEmail ? strtolower(trim($facultyEmail)) : null;

        return $this;
    }

    public function getSource(): ?string
    {
        return $this->source;
    }

    public function setSource(?string $source): self
    {
        $this->source = $source;

        return $this;
    }

    public function getImportBatchId(): ?string
    {
        return $this->importBatchId;
    }

    public function setImportBatchId(?string $importBatchId): self
    {
        $this->importBatchId = $importBatchId;

        return $this;
    }

    public function getScheduleIdentifier(): ?string
    {
        return $this->scheduleIdentifier;
    }

    public function setScheduleIdentifier(?string $scheduleIdentifier): self
    {
        $this->scheduleIdentifier = $scheduleIdentifier;

        return $this;
    }

    public function isRelocated(): bool
    {
        return $this->isRelocated;
    }

    public function setIsRelocated(bool $isRelocated): self
    {
        $this->isRelocated = $isRelocated;

        return $this;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(?string $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getStartDate(): ?\DateTimeInterface
    {
        return $this->startDate;
    }

    public function setStartDate(?\DateTimeInterface $startDate): self
    {
        $this->startDate = $startDate;

        return $this;
    }

    public function getEndDate(): ?\DateTimeInterface
    {
        return $this->endDate;
    }

    public function setEndDate(?\DateTimeInterface $endDate): self
    {
        $this->endDate = $endDate;

        return $this;
    }

    public function getTerm(): ?string
    {
        return $this->term;
    }

    public function setTerm(?string $term): self
    {
        $this->term = $term;

        return $this;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeInterface $updatedAt): self
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    public function getDisplayTitle(): string
    {
        $parts = array_filter([$this->courseCode, $this->section, $this->facultyName]);

        return $parts !== [] ? implode(' · ', $parts) : 'Class Schedule';
    }
}
