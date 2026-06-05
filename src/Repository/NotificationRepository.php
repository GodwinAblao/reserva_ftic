<?php

namespace App\Repository;

use App\Entity\Notification;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
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
            'SELECT COUNT(id) FROM notifications WHERE user_id = ? AND is_read = false AND status != ?',
            [$user->getId(), 'Suggested']
        );
    }

    /**
     * Lightweight poll data — single query returns unreadCount + newestId.
     * Used for background polling to decide whether a full fetch is needed.
     */
    public function getPollData(User $user): array
    {
        $row = $this->getEntityManager()->getConnection()->fetchAssociative(
            'SELECT COUNT(CASE WHEN is_read = false THEN 1 END) AS unread_count,
                    MAX(id) AS newest_id
             FROM notifications
             WHERE user_id = ? AND status != ?',
            [$user->getId(), 'Suggested']
        );

        return [
            'unreadCount' => (int) ($row['unread_count'] ?? 0),
            'newestId'    => (int) ($row['newest_id']    ?? 0),
        ];
    }

    public function findLatestRows(User $user, int $limit = 20): array
    {
        return $this->getEntityManager()->getConnection()->executeQuery(
            'SELECT n.id, n.type, n.title, n.message, n.status, n.is_read, n.reference_id, n.created_at
             FROM notifications n
             LEFT JOIN reservation r ON n.reference_id = r.id AND n.type = ?
             WHERE n.user_id = ?
               AND (n.type != ? OR r.status IS NULL OR r.status != ?)
             ORDER BY n.created_at DESC
             LIMIT ?',
            ['reservation', $user->getId(), 'reservation', 'Suggested', $limit],
            [ParameterType::STRING, ParameterType::INTEGER, ParameterType::STRING, ParameterType::STRING, ParameterType::INTEGER]
        )->fetchAllAssociative();
    }

    /**
     * Find latest notifications for a user (native SQL — avoids ORM hydration)
     */
    public function findLatest(User $user, int $limit = 20): array
    {
        return $this->createQueryBuilder('n')
            ->leftJoin('App\Entity\Reservation', 'r', 'WITH', 'n.referenceId = r.id AND n.type = :reservationType')
            ->where('n.user = :user')
            ->andWhere('n.type != :reservationType OR r.status IS NULL OR r.status != :suggestedStatus')
            ->setParameter('user', $user)
            ->setParameter('reservationType', 'reservation')
            ->setParameter('suggestedStatus', 'Suggested')
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
            ->leftJoin('App\Entity\Reservation', 'r', 'WITH', 'n.referenceId = r.id AND n.type = :reservationType')
            ->where('n.user = :user')
            ->andWhere('n.isRead = :isRead')
            ->andWhere('n.type != :reservationType OR r.status IS NULL OR r.status != :suggestedStatus')
            ->setParameter('user', $user)
            ->setParameter('isRead', false)
            ->setParameter('reservationType', 'reservation')
            ->setParameter('suggestedStatus', 'Suggested')
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
            'UPDATE notifications SET is_read = true WHERE user_id = ? AND is_read = false',
            [$user->getId()]
        );
        // Clear entity manager to prevent cached entities from showing stale data
        $this->getEntityManager()->clear();
    }
}
