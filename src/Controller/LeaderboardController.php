<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\MentorProfile;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/leaderboard')]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
class LeaderboardController extends AbstractController
{
    #[Route('', name: 'app_leaderboard', methods: ['GET'])]
    public function index(Request $request, EntityManagerInterface $em): Response
    {
        $search = $request->query->get('search', '');
        $specialization = $request->query->get('specialization', '');

        $qb = $em->getRepository(MentorProfile::class)->createQueryBuilder('m')
            ->leftJoin('m.user', 'u')
            ->orderBy('m.engagementPoints', 'DESC')
            ->addOrderBy('m.displayName', 'ASC');

        if ($search) {
            $qb->andWhere(
                'CONCAT(u.firstName, \' \', u.lastName) LIKE :search OR u.email LIKE :search OR m.displayName LIKE :search OR m.specialization LIKE :search'
            )->setParameter('search', '%' . $search . '%');
        }

        if ($specialization) {
            $qb->andWhere('m.specialization = :specialization')
                ->setParameter('specialization', $specialization);
        }

        $mentors = $qb->getQuery()->getResult();

        $specializations = $em->getRepository(MentorProfile::class)->createQueryBuilder('m')
            ->select('DISTINCT m.specialization')
            ->orderBy('m.specialization', 'ASC')
            ->getQuery()
            ->getResult();

        $flatSpecializations = array_map(fn($s) => $s['specialization'], $specializations);

        $totalPoints = array_sum(array_map(fn($m) => $m->getEngagementPoints(), $mentors));

        return $this->render('leaderboard/index.html.twig', [
            'mentors' => $mentors,
            'topThree' => array_slice($mentors, 0, 3),
            'rest' => array_slice($mentors, 3),
            'specializations' => $flatSpecializations,
            'search' => $search,
            'specializationFilter' => $specialization,
            'totalMentors' => count($mentors),
            'totalPoints' => $totalPoints,
        ]);
    }
}
