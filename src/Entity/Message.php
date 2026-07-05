<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\MessageRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MessageRepository::class)]
#[ORM\Table(name: 'inbox_message')]
class Message
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $sender = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $recipient = null;

    #[ORM\Column(length: 255)]
    private string $subject = '';

    #[ORM\Column(type: Types::TEXT)]
    private string $body = '';

    #[ORM\Column]
    private bool $isReadByRecipient = false;

    #[ORM\Column]
    private bool $isDeletedBySender = false;

    #[ORM\Column]
    private bool $isDeletedByRecipient = false;

    #[ORM\ManyToOne(targetEntity: Message::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Message $parentMessage = null;

    #[ORM\Column(nullable: true)]
    private ?int $threadId = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
    }

    public function getId(): ?int { return $this->id; }

    public function getSender(): ?User { return $this->sender; }
    public function setSender(?User $sender): static { $this->sender = $sender; return $this; }

    public function getRecipient(): ?User { return $this->recipient; }
    public function setRecipient(?User $recipient): static { $this->recipient = $recipient; return $this; }

    public function getSubject(): string { return $this->subject; }
    public function setSubject(string $subject): static { $this->subject = $subject; return $this; }

    public function getBody(): string { return $this->body; }
    public function setBody(string $body): static { $this->body = $body; return $this; }

    public function isReadByRecipient(): bool { return $this->isReadByRecipient; }
    public function setIsReadByRecipient(bool $isReadByRecipient): static { $this->isReadByRecipient = $isReadByRecipient; return $this; }

    public function isDeletedBySender(): bool { return $this->isDeletedBySender; }
    public function setIsDeletedBySender(bool $isDeletedBySender): static { $this->isDeletedBySender = $isDeletedBySender; return $this; }

    public function isDeletedByRecipient(): bool { return $this->isDeletedByRecipient; }
    public function setIsDeletedByRecipient(bool $isDeletedByRecipient): static { $this->isDeletedByRecipient = $isDeletedByRecipient; return $this; }

    public function getParentMessage(): ?Message { return $this->parentMessage; }
    public function setParentMessage(?Message $parentMessage): static { $this->parentMessage = $parentMessage; return $this; }

    public function getThreadId(): ?int { return $this->threadId; }
    public function setThreadId(?int $threadId): static { $this->threadId = $threadId; return $this; }

    public function getCreatedAt(): \DateTimeInterface { return $this->createdAt; }
    public function setCreatedAt(\DateTimeInterface $createdAt): static { $this->createdAt = $createdAt; return $this; }

    public function getSenderDisplayName(): string
    {
        if (!$this->sender) return 'Unknown';
        $name = trim(($this->sender->getFirstName() ?? '') . ' ' . ($this->sender->getLastName() ?? ''));
        return $name ?: $this->sender->getEmail();
    }

    public function getRecipientDisplayName(): string
    {
        if (!$this->recipient) return 'Unknown';
        $name = trim(($this->recipient->getFirstName() ?? '') . ' ' . ($this->recipient->getLastName() ?? ''));
        return $name ?: $this->recipient->getEmail();
    }
}
