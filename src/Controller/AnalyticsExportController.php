<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Reservation;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[Route('/admin/analytics')]
#[IsGranted('ROLE_ADMIN')]
class AnalyticsExportController extends AbstractController
{
    private const API_BASE = 'http://127.0.0.1:8002';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {}

    #[Route('/export/this-week', name: 'admin_role_analytics_export_this_week', methods: ['GET'])]
    public function exportThisWeek(EntityManagerInterface $em): Response
    {
        $content = $this->fetchAnalyticsCsv('/api/analytics/export/this-week');
        if ($content !== null) {
            return $this->csvAttachment($content, 'reservations_this_week.csv');
        }

        return $this->csvAttachment($this->buildThisWeekCsvFallback($em), 'reservations_this_week.csv');
    }

    #[Route('/export/weekly-forecast', name: 'admin_role_analytics_export_weekly', methods: ['GET'])]
    public function exportWeeklyForecast(Request $request): Response
    {
        $facilityId = $request->query->get('facility_id');
        $path = '/api/analytics/export/weekly-forecast';
        if ($facilityId !== null && $facilityId !== '') {
            $path .= '?facility_id=' . rawurlencode((string) $facilityId);
        }

        $content = $this->fetchAnalyticsCsv($path);
        if ($content !== null) {
            return $this->csvAttachment($content, 'arima_weekly_forecast.csv');
        }

        $hint = "report_type,error\nmessage,\"Start analytics server: cd analytics && .venv\\Scripts\\python main.py\"\n";

        return $this->csvAttachment($hint, 'arima_weekly_forecast.csv');
    }

    private function fetchAnalyticsCsv(string $path): ?string
    {
        try {
            $response = $this->httpClient->request('GET', self::API_BASE . $path, [
                'timeout' => 45,
            ]);

            if ($response->getStatusCode() === 200) {
                return $response->getContent();
            }
        } catch (\Throwable) {
            // Fall through to Symfony-generated CSV where possible.
        }

        return null;
    }

    private function buildThisWeekCsvFallback(EntityManagerInterface $em): string
    {
        $today = new \DateTimeImmutable('today');
        $daysFromMonday = (int) $today->format('N') - 1;
        $weekStart = $today->modify('-' . $daysFromMonday . ' days');
        $weekEnd = $weekStart->modify('+6 days');

        $lines = [
            'report_type,this_week_snapshot',
            'data_source,symfony_fallback',
            'week_start,' . $weekStart->format('Y-m-d'),
            'week_end,' . $weekEnd->format('Y-m-d'),
            'facility,approved_count,pending_count',
        ];

        $reservations = $em->getRepository(Reservation::class)->createQueryBuilder('r')
            ->leftJoin('r.facility', 'f')
            ->addSelect('f')
            ->where('r.reservationDate >= :start AND r.reservationDate <= :end')
            ->setParameter('start', $weekStart->setTime(0, 0))
            ->setParameter('end', $weekEnd->setTime(23, 59, 59))
            ->getQuery()
            ->getResult();

        $byFacility = [];
        foreach ($reservations as $r) {
            $name = $r->getFacility()?->getName() ?? 'Unknown';
            $byFacility[$name] ??= ['approved' => 0, 'pending' => 0];
            if ($r->getStatus() === 'Approved') {
                $byFacility[$name]['approved']++;
            } elseif (in_array($r->getStatus(), ['Pending', 'Suggested'], true)) {
                $byFacility[$name]['pending']++;
            }
        }

        if ($byFacility === []) {
            $lines[] = 'ALL,0,0';
        } else {
            foreach ($byFacility as $name => $counts) {
                $lines[] = sprintf('%s,%d,%d', $this->escapeCsv($name), $counts['approved'], $counts['pending']);
            }
        }

        return implode("\n", $lines) . "\n";
    }

    private function escapeCsv(string $value): string
    {
        if (str_contains($value, ',') || str_contains($value, '"')) {
            return '"' . str_replace('"', '""', $value) . '"';
        }

        return $value;
    }

    private function csvAttachment(string $content, string $filename): Response
    {
        $response = new Response($content);
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $disposition = $response->headers->makeDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $filename
        );
        $response->headers->set('Content-Disposition', $disposition);

        return $response;
    }
}
