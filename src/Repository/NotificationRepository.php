<?php

namespace App\Repository;

use App\Entity\Notification;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Notification>
 */
class NotificationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Notification::class);
    }

    /**
     * Get unread notification count for a user
     */
    public function getUnreadCount(User $user): int
    {
        return (int) $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT COUNT(id) FROM notifications WHERE user_id = ? AND is_read = 0',
            [$user->getId()]
        );
    }

    /**
     * Lightweight poll data — single query returns unreadCount + newestId.
     * Used for background polling to decide whether a full fetch is needed.
     */
    public function getPollData(User $user): array
    {
        $row = $this->getEntityManager()->getConnection()->fetchAssociative(
            'SELECT COUNT(CASE WHEN is_read = 0 THEN 1 END) AS unread_count,
                    MAX(id) AS newest_id
             FROM notifications
             WHERE user_id = ?',
            [$user->getId()]
        );

        return [
            'unreadCount' => (int) ($row['unread_count'] ?? 0),
            'newestId'    => (int) ($row['newest_id']    ?? 0),
        ];
    }

    /**
     * Find latest notifications for a user (native SQL — avoids ORM hydration)
     */
    public function findLatest(User $user, int $limit = 20): array
    {
        return $this->createQueryBuilder('n')
            ->where('n.user = :user')
            ->setParameter('user', $user)
            ->orderBy('n.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find unread notifications for a user
     */
    public function findUnread(User $user): array
    {
        return $this->createQueryBuilder('n')
            ->where('n.user = :user')
            ->andWhere('n.isRead = :isRead')
            ->setParameter('user', $user)
            ->setParameter('isRead', false)
            ->orderBy('n.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Mark all unread as read via single UPDATE — avoids N flush calls
     */
    public function markAllReadForUser(User $user): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0',
            [$user->getId()]
        );
    }
}
