<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Facility;
use App\Entity\Reservation;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/analytics')]
#[IsGranted('ROLE_SUPER_ADMIN')]
class AnalyticsController extends AbstractController
{
    #[Route('', name: 'analytics_dashboard', methods: ['GET'])]
    public function dashboard(EntityManagerInterface $em): Response
    {
        $usage = $em->createQueryBuilder()
            ->select('f.name, COUNT(r.id) AS total, COALESCE(SUM(r.capacity), 0) AS attendees')
            ->from(Facility::class, 'f')
            ->leftJoin(Reservation::class, 'r', 'WITH', 'r.facility = f AND r.status = :approved')
            ->setParameter('approved', 'Approved')
            ->groupBy('f.id')
            ->orderBy('total', 'DESC')
            ->getQuery()
            ->getArrayResult();

        $monthly = $em->createQueryBuilder()
            ->select('r.reservationDate')
            ->from(Reservation::class, 'r')
            ->where('r.status IN (:statuses)')
            ->setParameter('statuses', ['Approved', 'Pending', 'Suggested'])
            ->orderBy('r.reservationDate', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $series = $this->monthlySeries($monthly);
        $forecast = $this->movingAverageForecast($series, 3);

        return $this->render('analytics/dashboard.html.twig', [
            'usage' => $usage,
            'series' => $series,
            'forecast' => $forecast,
            'highDemand' => array_slice($usage, 0, 3),
            'underused' => array_slice(array_reverse($usage), 0, 3),
            'mae' => $this->mae($series),
            'rmse' => $this->rmse($series),
        ]);
    }

    private function monthlySeries(array $rows): array
    {
        $series = [];
        foreach ($rows as $row) {
            $month = $row['reservationDate']->format('Y-m');
            $series[$month] = ($series[$month] ?? 0) + 1;
        }

        ksort($series);

        return $series;
    }

    private function movingAverageForecast(array $series, int $months): array
    {
        $values = array_values($series);
        $lastMonth = $series === [] ? new \DateTime('first day of this month') : new \DateTime(array_key_last($series) . '-01');
        $window = array_slice($values, -3);
        $average = $window === [] ? 0 : (int) round(array_sum($window) / count($window));

        $forecast = [];
        for ($i = 1; $i <= $months; $i++) {
            $label = (clone $lastMonth)->modify('+' . $i . ' month')->format('Y-m');
            $forecast[$label] = $average;
        }

        return $forecast;
    }

    private function mae(array $series): float
    {
        $values = array_values($series);
        if (count($values) < 2) {
            return 0.0;
        }

        $errors = [];
        for ($i = 1; $i < count($values); $i++) {
            $errors[] = abs($values[$i] - $values[$i - 1]);
        }

        return round(array_sum($errors) / count($errors), 2);
    }

    private function rmse(array $series): float
    {
        $values = array_values($series);
        if (count($values) < 2) {
            return 0.0;
        }

        $errors = [];
        for ($i = 1; $i < count($values); $i++) {
            $errors[] = ($values[$i] - $values[$i - 1]) ** 2;
        }

        return round(sqrt(array_sum($errors) / count($errors)), 2);
    }
}
