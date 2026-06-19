<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MentorProfile;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MentorProfile>
 */
class MentorProfileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MentorProfile::class);
    }

    public function findByUser(User $user): ?MentorProfile
    {
        return $this->createQueryBuilder('mp')
            ->where('mp.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @param MentorProfile[] $profiles
     * @return MentorProfile[]
     */
    public function filterActiveProfiles(array $profiles): array
    {
        return array_values(array_filter($profiles, static function (MentorProfile $profile): bool {
            $user = $profile->getUser();
            return $user !== null && in_array('ROLE_MENTOR', $user->getRoles(), true);
        }));
    }

    /**
     * @return MentorProfile[]
     */
    public function findActiveOrderedByDisplayName(): array
    {
        return $this->filterActiveProfiles($this->findBy([], ['displayName' => 'ASC']));
    }

    /**
     * @return MentorProfile[]
     */
    public function findActiveLeaderboard(int $limit = 10): array
    {
        return array_slice($this->filterActiveProfiles($this->findBy([], ['engagementPoints' => 'DESC'])), 0, $limit);
    }
}

