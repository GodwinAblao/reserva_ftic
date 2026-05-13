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
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[Route('/analytics')]
#[IsGranted('ROLE_ADMIN')]
class AnalyticsController extends AbstractController
{
    public function __construct(
        private HttpClientInterface $httpClient
    ) {}

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

        $historyRows = $monthly === [] ? $this->csvHistoryRows() : $monthly;
        $series = $this->monthlySeries($historyRows);
        $weeklySeries = $this->weeklySeries($historyRows);
        $forecast = $this->movingAverageForecast($series, 3);
        $weeklyForecast = $this->movingAverageForecast($weeklySeries, 8);
        $localAnalytics = $this->localAnalyticsFallback($em, $usage, $series, $forecast, $weeklySeries, $weeklyForecast);

        return $this->render('analytics/dashboard.html.twig', [
            'usage' => $usage,
            'series' => $series,
            'forecast' => $forecast,
            'highDemand' => array_slice($usage, 0, 3),
            'underused' => array_slice(array_reverse($usage), 0, 3),
            'mae' => $this->mae($series),
            'rmse' => $this->rmse($series),
            'fastApiAvailable' => $this->isFastApiAvailable(),
            'fastApiUrl' => 'http://127.0.0.1:8002',
            'localAnalytics' => $localAnalytics,
        ]);
    }

    private function isFastApiAvailable(): bool
    {
        try {
            // Test connection to FastAPI
            $response = $this->httpClient->request('GET', 'http://127.0.0.1:8002/', [
                'timeout' => 1
            ]);

            return $response->getStatusCode() === 200;
        } catch (\Exception $e) {
            // FastAPI not available
            return false;
        }
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

    private function weeklySeries(array $rows): array
    {
        $series = [];
        foreach ($rows as $row) {
            $week = $row['reservationDate']->format('o-\WW');
            $series[$week] = ($series[$week] ?? 0) + 1;
        }

        ksort($series);

        return $series;
    }

    private function movingAverageForecast(array $series, int $months): array
    {
        $values = array_values($series);
        $lastLabel = array_key_last($series);
        $isWeekly = is_string($lastLabel) && str_contains($lastLabel, '-W');
        $lastDate = $this->seriesLabelToDate($lastLabel, $isWeekly);
        $window = array_slice($values, -3);
        $average = $window === [] ? 0 : (int) round(array_sum($window) / count($window));

        $forecast = [];
        for ($i = 1; $i <= $months; $i++) {
            $label = (clone $lastDate)
                ->modify('+' . $i . ($isWeekly ? ' week' : ' month'))
                ->format($isWeekly ? 'o-\WW' : 'Y-m');
            $forecast[$label] = $average;
        }

        return $forecast;
    }

    private function seriesLabelToDate(?string $label, bool $isWeekly): \DateTime
    {
        if ($label === null) {
            return new \DateTime($isWeekly ? 'monday this week' : 'first day of this month');
        }

        if ($isWeekly && preg_match('/^(\d{4})-W(\d{2})$/', $label, $matches)) {
            return (new \DateTime())->setISODate((int) $matches[1], (int) $matches[2]);
        }

        return new \DateTime($label . '-01');
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

    private function localAnalyticsFallback(EntityManagerInterface $em, array $usage, array $series, array $forecast, array $weeklySeries, array $weeklyForecast): array
    {
        $rows = $em->createQueryBuilder()
            ->select('r.reservationDate, r.reservationStartTime, r.capacity, r.purpose, r.status, r.rejectionReason, r.createdAt, f.name AS facilityName, f.capacity AS facilityCapacity')
            ->from(Reservation::class, 'r')
            ->join('r.facility', 'f')
            ->orderBy('r.reservationDate', 'ASC')
            ->getQuery()
            ->getArrayResult();
        if ($rows === []) {
            $rows = $this->csvAnalyticsRows();
        }

        $hourly = [];
        $statusCounts = [];
        $purposeTotals = [];
        $purposeCompleted = [];
        $monthlyAttendees = [];
        $rejectionReasons = [];
        $setupGaps = [];
        $facilityLoadDistribution = [];

        foreach ($rows as $row) {
            $hour = $row['reservationStartTime'] instanceof \DateTimeInterface
                ? $row['reservationStartTime']->format('H')
                : '00';
            $hourly[$hour] = ($hourly[$hour] ?? 0) + 1;
            $facilityName = (string) $row['facilityName'];
            $facilityLoadDistribution[$facilityName] = ($facilityLoadDistribution[$facilityName] ?? 0) + 1;

            $status = (string) $row['status'];
            $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;

            $purpose = trim((string) ($row['purpose'] ?? 'General Reservation')) ?: 'General Reservation';
            $purposeTotals[$purpose] = ($purposeTotals[$purpose] ?? 0) + 1;
            if (in_array($status, ['Approved', 'Completed'], true)) {
                $purposeCompleted[$purpose] = ($purposeCompleted[$purpose] ?? 0) + 1;
            }

            if ($row['reservationDate'] instanceof \DateTimeInterface) {
                $month = $row['reservationDate']->format('Y-m');
                $monthlyAttendees[$month] = ($monthlyAttendees[$month] ?? 0) + (int) $row['capacity'];
            }

            if ($status === 'Rejected' && !empty($row['rejectionReason'])) {
                $reason = (string) $row['rejectionReason'];
                $rejectionReasons[$reason] = ($rejectionReasons[$reason] ?? 0) + 1;
            }

            $setupDate = $row['setupDate'] ?? $row['createdAt'] ?? null;
            if ($setupDate instanceof \DateTimeInterface && $row['reservationDate'] instanceof \DateTimeInterface) {
                $setupGaps[] = max(0, (int) $setupDate->diff($row['reservationDate'])->format('%r%a'));
            }
        }

        ksort($hourly);
        ksort($monthlyAttendees);

        $eventSuccess = [];
        foreach ($purposeTotals as $purpose => $total) {
            $eventSuccess[$purpose] = $total === 0 ? 0 : round((($purposeCompleted[$purpose] ?? 0) / $total) * 100, 1);
        }
        arsort($eventSuccess);

        $roomUtilization = [];
        foreach ($rows as $row) {
            $facilityName = (string) $row['facilityName'];
            $roomUtilization[$facilityName] ??= [
                'reservations' => 0,
                'total_capacity' => 0,
                'available_capacity' => 0,
                'utilization_rate' => 0,
            ];
            $roomUtilization[$facilityName]['reservations']++;
            $roomUtilization[$facilityName]['total_capacity'] += (int) $row['capacity'];
            $roomUtilization[$facilityName]['available_capacity'] += max(0, (int) ($row['facilityCapacity'] ?? 0));
        }

        foreach ($roomUtilization as $facilityName => $values) {
            $roomUtilization[$facilityName]['utilization_rate'] = $values['available_capacity'] === 0
                ? 0
                : round($values['total_capacity'] / $values['available_capacity'], 4);
        }
        $facilityUtilizationRate = array_map(
            fn (array $values): float => $values['utilization_rate'],
            $roomUtilization
        );

        $totalReservations = max(count($rows), 1);
        $approvedOrCompleted = ($statusCounts['Approved'] ?? 0) + ($statusCounts['Completed'] ?? 0);
        $averageSetupGap = $setupGaps === [] ? 0 : round(array_sum($setupGaps) / count($setupGaps), 1);
        $setupComplianceRate = $setupGaps === [] ? 0 : round((count(array_filter($setupGaps, fn (int $gap): bool => $gap <= 3)) / count($setupGaps)) * 100, 1);
        $noShowRate = round(((($statusCounts['Cancelled'] ?? 0) + ($statusCounts['Rejected'] ?? 0)) / $totalReservations) * 100, 1);

        return [
            'source' => 'Symfony database fallback',
            'planning' => [
                'forecast_series' => [
                    'weekly' => [
                        'historical' => $weeklySeries,
                        'forecast' => $weeklyForecast,
                    ],
                    'monthly' => [
                        'historical' => $series,
                        'forecast' => $forecast,
                    ],
                ],
                'peak_demand_hours' => $hourly,
                'recommended_room_capacity' => $this->capacityStatsByFacility($rows),
                'participation_trends' => $monthlyAttendees,
                'event_type_distribution' => $purposeTotals,
                'forecast_accuracy' => [
                    'weekly_mae' => $this->mae($weeklySeries),
                    'weekly_rmse' => $this->rmse($weeklySeries),
                    'monthly_mae' => $this->mae($series),
                    'monthly_rmse' => $this->rmse($series),
                ],
            ],
            'organizing' => [
                'room_utilization' => $roomUtilization,
                'overlapping_reservations' => 0,
                'facility_load_distribution' => $facilityLoadDistribution,
                'peak_usage_times' => $hourly,
                'optimization_suggestions' => [
                    'Monitor peak facilities before approving dense schedules.',
                    'Use underused facilities as alternatives when demand spikes.',
                    'Keep buffer time between back-to-back reservations.',
                ],
            ],
            'staffing' => [
                'participation_trends' => $this->staffingTrends($monthlyAttendees),
                'participant_demand_trend' => $monthlyAttendees,
                'high_demand_periods' => array_slice($monthlyAttendees, -6, 6, true),
                'staffing_recommendations' => [
                    'Plan staff coverage from monthly participant volume.',
                    'Add support during high-participation months.',
                    'Review staffing needs before major FTIC events.',
                ],
            ],
            'leading' => [
                'overall_completion_rate' => round(($approvedOrCompleted / $totalReservations) * 100, 1),
                'rso_completion_rate' => 0,
                'participation_accuracy' => 100,
                'event_success_by_type' => array_slice($eventSuccess, 0, 8, true),
            ],
            'controlling' => [
                'average_setup_gap' => $averageSetupGap,
                'no_show_rate' => $noShowRate,
                'target_achievement' => $this->percentages($statusCounts, $totalReservations),
                'setup_compliance_rate' => $setupComplianceRate,
                'rejection_analysis' => $rejectionReasons,
                'facility_utilization_rate' => $facilityUtilizationRate,
            ],
        ];
    }

    private function capacityStatsByFacility(array $rows): array
    {
        $stats = [];
        foreach ($rows as $row) {
            $facility = (string) $row['facilityName'];
            $capacity = (int) $row['capacity'];
            $stats[$facility]['sum'] = ($stats[$facility]['sum'] ?? 0) + $capacity;
            $stats[$facility]['max'] = max($stats[$facility]['max'] ?? 0, $capacity);
            $stats[$facility]['count'] = ($stats[$facility]['count'] ?? 0) + 1;
        }

        foreach ($stats as $facility => $values) {
            $stats[$facility]['mean'] = $values['count'] === 0 ? 0 : round($values['sum'] / $values['count'], 1);
            unset($stats[$facility]['sum']);
        }

        return $stats;
    }

    private function staffingTrends(array $monthlyAttendees): array
    {
        $trends = [];
        foreach ($monthlyAttendees as $month => $attendees) {
            $trends[$month] = [
                'sum' => $attendees,
                'mean' => $attendees,
                'max' => $attendees,
                'required_staff' => (int) ceil($attendees / 20),
            ];
        }

        return $trends;
    }

    private function percentages(array $counts, int $total): array
    {
        $percentages = [];
        foreach ($counts as $status => $count) {
            $percentages[$status] = round(($count / max($total, 1)) * 100, 1);
        }

        return $percentages;
    }

    private function csvHistoryRows(): array
    {
        return array_map(
            fn (array $row): array => ['reservationDate' => $row['reservationDate']],
            $this->csvAnalyticsRows()
        );
    }

    private function csvAnalyticsRows(): array
    {
        $path = $this->getParameter('kernel.project_dir') . '/data/dummy_reservations.csv';
        if (!is_string($path) || !is_readable($path)) {
            return [];
        }

        $handle = fopen($path, 'r');
        if ($handle === false) {
            return [];
        }

        $headers = fgetcsv($handle);
        if ($headers === false) {
            fclose($handle);
            return [];
        }

        $rows = [];
        $facilityCapacities = [
            'CS Project Room' => 48,
            'Discussion Room 3' => 6,
            'Discussion Room 4' => 8,
            'Presentation Room 1' => 40,
            'Presentation Room 2' => 60,
            'COE Project Room' => 48,
            'Lounge Area' => 150,
        ];
        while (($data = fgetcsv($handle)) !== false) {
            $record = array_combine($headers, $data);
            if ($record === false || empty($record['reservation_date'])) {
                continue;
            }
            $facilityName = $record['facility_name'] ?? 'Facility';

            $rows[] = [
                'reservationDate' => new \DateTime($record['reservation_date']),
                'reservationStartTime' => new \DateTime($record['reservation_start_time'] ?? '00:00:00'),
                'capacity' => (int) ($record['capacity'] ?? 0),
                'purpose' => $record['purpose'] ?? 'General Reservation',
                'status' => $record['status'] ?? 'Pending',
                'rejectionReason' => $record['rejection_reason'] ?: null,
                'setupDate' => !empty($record['setup_date']) ? new \DateTime($record['setup_date']) : null,
                'createdAt' => !empty($record['created_at']) ? new \DateTime($record['created_at']) : null,
                'facilityName' => $facilityName,
                'facilityCapacity' => $facilityCapacities[$facilityName] ?? (int) ($record['capacity'] ?? 0),
            ];
        }

        fclose($handle);

        return $rows;
    }
}
