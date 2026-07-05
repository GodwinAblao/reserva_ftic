<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Message;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Message>
 */
class MessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Message::class);
    }

    /**
     * @return Message[]
     */
    public function findInbox(User $user): array
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.recipient = :user')
            ->andWhere('m.isDeletedByRecipient = false')
            ->setParameter('user', $user)
            ->orderBy('m.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Message[]
     */
    public function findSent(User $user): array
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.sender = :user')
            ->andWhere('m.isDeletedBySender = false')
            ->setParameter('user', $user)
            ->orderBy('m.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function countUnread(User $user): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->andWhere('m.recipient = :user')
            ->andWhere('m.isReadByRecipient = false')
            ->andWhere('m.isDeletedByRecipient = false')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return Message[]
     */
    public function findThread(int $threadId, User $user): array
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.threadId = :threadId')
            ->andWhere('m.sender = :user OR m.recipient = :user')
            ->setParameter('threadId', $threadId)
            ->setParameter('user', $user)
            ->orderBy('m.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
