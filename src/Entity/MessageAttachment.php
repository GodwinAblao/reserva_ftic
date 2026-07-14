<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'inbox_message_attachment')]
class MessageAttachment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Message::class, inversedBy: 'attachments')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Message $message = null;

    #[ORM\Column(length: 255)]
    private string $originalName = '';

    #[ORM\Column(length: 255)]
    private string $storedName = '';

    #[ORM\Column(length: 255)]
    private string $storagePath = '';

    #[ORM\Column(length: 120)]
    private string $mimeType = 'application/octet-stream';

    #[ORM\Column]
    private int $fileSize = 0;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
    }

    public function getId(): ?int { return $this->id; }

    public function getMessage(): ?Message { return $this->message; }
    public function setMessage(?Message $message): static { $this->message = $message; return $this; }

    public function getOriginalName(): string { return $this->originalName; }
    public function setOriginalName(string $originalName): static { $this->originalName = $originalName; return $this; }

    public function getStoredName(): string { return $this->storedName; }
    public function setStoredName(string $storedName): static { $this->storedName = $storedName; return $this; }

    public function getStoragePath(): string { return $this->storagePath; }
    public function setStoragePath(string $storagePath): static { $this->storagePath = $storagePath; return $this; }

    public function getMimeType(): string { return $this->mimeType; }
    public function setMimeType(string $mimeType): static { $this->mimeType = $mimeType; return $this; }

    public function getFileSize(): int { return $this->fileSize; }
    public function setFileSize(int $fileSize): static { $this->fileSize = $fileSize; return $this; }

    public function getCreatedAt(): \DateTimeInterface { return $this->createdAt; }

    public function getFormattedFileSize(): string
    {
        if ($this->fileSize >= 1048576) {
            return number_format($this->fileSize / 1048576, 1) . ' MB';
        }

        if ($this->fileSize >= 1024) {
            return number_format($this->fileSize / 1024, 1) . ' KB';
        }

        return $this->fileSize . ' B';
    }
}
