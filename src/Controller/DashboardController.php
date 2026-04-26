<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\ReservationRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DashboardController extends AbstractController
{
    #[Route('/dashboard', name: 'app_dashboard')]
    public function index(ReservationRepository $reservationRepo): Response
    {
        $user = $this->getUser();
        $reservations = $user ? $reservationRepo->findByUser($user) : [];

        // Categorize reservations by status
        $categorized = [
            'Approved' => [],
            'Pending' => [],
            'Suggested' => [],
            'Rejected' => [],
            'Cancelled' => [],
        ];

        foreach ($reservations as $reservation) {
            $status = $reservation->getStatus();
            if (isset($categorized[$status])) {
                $categorized[$status][] = $reservation;
            }
        }

        return $this->render('dashboard/index.html.twig', [
            'reservations' => $categorized,
        ]);
    }
}
