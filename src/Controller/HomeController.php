<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ResearchContent;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use App\Repository\FacilityRepository;
use Doctrine\ORM\EntityManagerInterface;

class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home')]
    public function index(FacilityRepository $facilityRepository, EntityManagerInterface $em): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_dashboard');
        }

        $facilities = $facilityRepository->findBy([
            'availableForReservation' => true,
        ], ['name' => 'ASC']);

        $researchItems = $em->createQueryBuilder()
            ->select('r')
            ->from(ResearchContent::class, 'r')
            ->where('r.visibility = :public')
            ->andWhere('r.type IN (:types)')
            ->setParameter('public', 'Public')
            ->setParameter('types', ['Article', 'Research'])
            ->orderBy('r.createdAt', 'DESC')
            ->setMaxResults(5)
            ->getQuery()
            ->getResult();

        return $this->render('home/landing.html.twig', [
            'facilities' => $facilities,
            'researchItems' => $researchItems,
        ]);
    }
}
