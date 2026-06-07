<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Facility;
use App\Entity\Reservation;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[Route('/analytics')]
#[IsGranted('ROLE_ADMIN')]
class AnalyticsController extends AbstractController
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private EntityManagerInterface $entityManager
    ) {}

    #[Route('', name: 'analytics_dashboard', methods: ['GET'])]
    public function dashboard(EntityManagerInterface $em): Response
    {
        $usage = $em->createQueryBuilder()
            ->select('f.id, f.name, COUNT(r.id) AS total, COALESCE(SUM(r.capacity), 0) AS attendees')
            ->from(Facility::class, 'f')
            ->leftJoin(Reservation::class, 'r', 'WITH', 'r.facility = f AND r.status = :approved')
            ->where('f.availableForReservation = true')
            ->setParameter('approved', 'Approved')
            ->groupBy('f.id')
            ->orderBy('total', 'DESC')
            ->getQuery()
            ->getArrayResult();

        $monthly = $em->createQueryBuilder()
            ->select('r.reservationDate')
            ->from(Reservation::class, 'r')
            ->where('r.status IN (:statuses)')
            ->setParameter('statuses', ['Approved', 'Pending'])
            ->orderBy('r.reservationDate', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $historyRows = $monthly === [] ? $this->csvHistoryRows() : $monthly;
        $series = $this->monthlySeries($historyRows);
        $weeklySeries = $this->weeklySeries($historyRows);
        $forecast = $this->movingAverageForecast($series, 3);
        $weeklyForecast = $this->movingAverageForecast($weeklySeries, 8);
        $localAnalytics = $this->localAnalyticsFallback($em, compact('usage', 'series', 'forecast', 'weeklySeries', 'weeklyForecast'));

        return $this->render('analytics/dashboard.html.twig', [
            'usage' => $usage,
            'series' => $series,
            'forecast' => $forecast,
            'highDemand' => array_slice($usage, 0, 3),
            'underused' => array_slice(array_reverse($usage), 0, 3),
            'mae' => $this->mae($series),
            'rmse' => $this->rmse($series),
            'fastApiAvailable' => $this->isFastApiAvailable(),
            'fastApiUrl' => $this->getFastApiUrl(),
            'localAnalytics' => $localAnalytics,
        ]);
    }

    #[Route('/proxy/{endpoint}', name: 'analytics_proxy', methods: ['GET'])]
    public function proxy(string $endpoint, Request $request, CacheInterface $cache): Response
    {
        $queryParams = $request->query->all();
        $facilityId = $queryParams['facility_id'] ?? null;
        $dataSource = $queryParams['data_source'] ?? 'combined';

        $cacheKey = 'analytics.proxy.' . md5($endpoint . '|' . (string) $facilityId . '|' . $dataSource);
        $data = $cache->get($cacheKey, function (ItemInterface $item) use ($endpoint, $facilityId, $dataSource): array {
            $item->expiresAfter(60);
            return $this->getAnalyticsData($endpoint, $facilityId, $dataSource);
        });

        $response = $this->json($data);
        $response->headers->set('Cache-Control', 'private, max-age=60');
        return $response;
    }

    private function getAnalyticsData(string $endpoint, ?string $facilityId, string $dataSource): array
    {
        $labels = [
            'live' => 'Live Database Only',
            'demo' => 'Demo Dataset Only',
            'combined' => 'Combined (Demo + Live)',
        ];

        // Get reservations based on data source filter (only from enabled facilities)
        $qb = $this->entityManager->createQueryBuilder()
            ->select('r, f')
            ->from(Reservation::class, 'r')
            ->innerJoin('r.facility', 'f')
            ->where('f.availableForReservation = true');

        if ($dataSource === 'live') {
            $qb->andWhere('r.isSimulated = false OR r.isSimulated IS NULL');
        } elseif ($dataSource === 'demo') {
            $qb->andWhere('r.isSimulated = true');
        }
        // For 'combined', no filter needed - get all

        if ($facilityId) {
            $qb->andWhere('f.id = :facilityId')
               ->setParameter('facilityId', (int) $facilityId);
        }

        $reservations = $qb->getQuery()->getResult();
        $totalCount = count($reservations);

        $base = [
            'source' => $dataSource,
            'dataSourceLabel' => $labels[$dataSource] ?? 'Combined (Demo + Live)',
            'reservationCount' => $totalCount,
            'totalRows' => $totalCount,
            'generatedAt' => date('c'),
        ];

        $metrics = $this->aggregateReservationMetrics($reservations);
        $facilityStats        = $metrics['facilityStats'];
        $facilityReservations = $metrics['facilityReservations'];
        $facilityDailyBookings= $metrics['facilityDailyBookings'];
        $statusCounts         = $metrics['statusCounts'];
        $monthlyTrends        = $metrics['monthlyTrends'];
        $weeklyTrends         = $metrics['weeklyTrends'];
        $hourlyPeak           = $metrics['hourlyPeak'];
        $hourlyHeatmap        = $metrics['hourlyHeatmap'];
        $purposeCounts        = $metrics['purposeCounts'];
        $setupGaps            = $metrics['setupGaps'];
        $rsoCount             = $metrics['rsoCount'];
        $rsoCompleted         = $metrics['rsoCompleted'];
        $purposeSuccess       = $metrics['purposeSuccess'];

        $totalReservations    = count($reservations);
        $approvedCompleted    = $statusCounts['Approved'] + $statusCounts['Completed'];
        $overallCompletionRate = $totalReservations > 0 ? round(($approvedCompleted / $totalReservations) * 100, 1) : 0;
        $rsoCompletionRate    = $rsoCount > 0 ? round(($rsoCompleted / $rsoCount) * 100, 1) : 0;
        $averageSetupGap      = $this->averageSetupGap($setupGaps);
        $setupComplianceRate  = $this->setupComplianceRate($setupGaps);
        $noShowRate           = $totalReservations > 0 ? round((($statusCounts['Cancelled'] + $statusCounts['Rejected']) / $totalReservations) * 100, 1) : 0;

        $eventSuccessByType   = $this->calcEventSuccess($purposeSuccess);
        $topEvents            = array_slice($purposeCounts, 0, 5, true);
        $perFacilityWeekly    = $this->buildPerFacilityWeekly($facilityReservations);
        $roomUtilization      = $this->buildRoomUtilization($facilityStats, $facilityDailyBookings);
        $heatmapData          = $this->buildHeatmap($hourlyHeatmap);

        $facilities = [];
        foreach ($facilityStats as $stats) {
            $facilities[] = ['id' => $stats['id'], 'name' => $stats['name'], 'count' => $stats['count'], 'capacity' => $stats['capacity']];
        }
        usort($facilities, fn($a, $b) => $b['count'] <=> $a['count']);

        $weeklyForecast  = $this->generateForecast($weeklyTrends, 'W');
        $monthlyForecast = $this->generateForecast($monthlyTrends, 'M');
        $weeklyRmse      = $this->naiveForecastRmse($weeklyTrends);

        return match($endpoint) {
            'meta' => array_merge($base, [
                'facilities' => array_slice($facilities, 0, 20),
            ]),
            'planning' => array_merge($base, [
                'forecast_series' => [
                    'weekly' => [
                        'historical' => $weeklyTrends,
                        'forecast' => $weeklyForecast['forecast'] ?? [],
                        'lower' => $weeklyForecast['lower'] ?? [],
                        'upper' => $weeklyForecast['upper'] ?? [],
                    ],
                    'monthly' => [
                        'historical' => $monthlyTrends,
                        'forecast' => $monthlyForecast['forecast'] ?? [],
                        'lower' => $monthlyForecast['lower'] ?? [],
                        'upper' => $monthlyForecast['upper'] ?? [],
                    ],
                ],
                'forecast_accuracy' => [
                    'weekly_rmse' => $weeklyRmse,
                ],
                'peak_demand_hours' => $hourlyPeak,
                'event_type_distribution' => $purposeCounts,
                'per_facility_weekly' => $perFacilityWeekly,
            ]),
            'organizing' => array_merge($base, [
                'facility_load_distribution' => array_column(array_slice($facilities, 0, 10), 'count', 'name'),
                'peak_usage_times' => $hourlyPeak,
                'room_utilization' => $roomUtilization,
                'peak_usage_heatmap' => $heatmapData,
            ]),
            'leading' => array_merge($base, [
                'overall_completion_rate' => $overallCompletionRate,
                'rso_completion_rate' => $rsoCompletionRate,
                'event_success_by_type' => $eventSuccessByType,
                'top_events' => $topEvents,
                'participant_demand_trend' => $monthlyTrends,
            ]),
            'controlling' => array_merge($base, [
                'target_achievement' => [
                    'Approved' => $statusCounts['Approved'] ?? 0,
                    'Pending' => $statusCounts['Pending'] ?? 0,
                    'Rejected' => $statusCounts['Rejected'] ?? 0,
                    'Cancelled' => $statusCounts['Cancelled'] ?? 0,
                ],
                'facility_utilization_rate' => $roomUtilization,
                'setup_compliance_rate' => $setupComplianceRate,
                'no_show_rate' => $noShowRate,
                'average_setup_gap' => $averageSetupGap,
                'rejection_analysis' => ['Rejected' => $statusCounts['Rejected'] ?? 0, 'Cancelled' => $statusCounts['Cancelled'] ?? 0],
            ]),
            default => array_merge($base, ['error' => 'Unknown endpoint']),
        };
    }

    private function generateForecast(array $trends, string $period = 'M'): array
    {
        if (count($trends) < 2) {
            return ['forecast' => [], 'lower' => [], 'upper' => []];
        }

        ksort($trends);
        $values = array_values($trends);
        $count = count($values);

        // Simple moving average forecast
        $avg = array_sum($values) / $count;
        $trend = ($values[$count - 1] - $values[0]) / max(1, $count - 1);
        $stdDev = $this->calculateStdDev($values);

        $lastKey = array_key_last($trends);
        $forecast = [];
        $lower = [];
        $upper = [];

        // Parse the last date properly
        if ($period === 'M') {
            $lastDate = \DateTime::createFromFormat('Y-m', $lastKey) ?: new \DateTime();
        } else {
            // Weekly format: 2024-W05
            $lastDate = \DateTime::createFromFormat('o-\WW', $lastKey) ?: new \DateTime();
        }

        for ($i = 1; $i <= ($period === 'W' ? 8 : 6); $i++) {
            $nextDate = clone $lastDate;
            if ($period === 'M') {
                $nextDate->modify("+$i month");
                $nextKey = $nextDate->format('Y-m');
            } else {
                $nextDate->modify("+$i week");
                $nextKey = $nextDate->format('o-\WW');
            }
            $predicted = max(0, round($avg + $trend * $i));
            $forecast[$nextKey] = $predicted;
            $lower[$nextKey] = max(0, round($predicted - 1.28 * $stdDev)); // 80% confidence
            $upper[$nextKey] = round($predicted + 1.28 * $stdDev);
        }

        return ['forecast' => $forecast, 'lower' => $lower, 'upper' => $upper];
    }

    private function calculateStdDev(array $values): float
    {
        $count = count($values);
        if ($count < 2) return 0;
        $mean = array_sum($values) / $count;
        $variance = array_sum(array_map(fn($x) => pow($x - $mean, 2), $values)) / $count;
        return sqrt($variance);
    }

    private function isFastApiAvailable(): bool
    {
        try {
            $response = $this->httpClient->request('GET', rtrim($this->getFastApiUrl(), '/') . '/', [
                'timeout' => 1,
            ]);

            return $response->getStatusCode() === 200;
        } catch (\Exception $e) {
            return false;
        }
    }

    private function getFastApiUrl(): string
    {
        $url = getenv('FASTAPI_URL');
        if ($url === false) {
            $url = $_ENV['FASTAPI_URL'] ?? $_SERVER['FASTAPI_URL'] ?? null;
        }

        return $url ?: 'https://reserva-ftic-analytics-production.up.railway.app';
    }

    private function getFastApiUrlSource(): string
    {
        if (getenv('FASTAPI_URL') !== false) {
            return 'getenv';
        }

        if (isset($_ENV['FASTAPI_URL'])) {
            return '_ENV';
        }

        if (isset($_SERVER['FASTAPI_URL'])) {
            return '_SERVER';
        }

        return 'default';
    }

    private function monthlySeries(array $rows): array
    {
        return $this->buildSeries($rows, 'Y-m');
    }

    private function weeklySeries(array $rows): array
    {
        return $this->buildSeries($rows, 'o-\WW');
    }

    private function buildSeries(array $rows, string $format): array
    {
        $series = [];
        foreach ($rows as $row) {
            $key = $row['reservationDate']->format($format);
            $series[$key] = ($series[$key] ?? 0) + 1;
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
        return $this->forecastError('mae', $series);
    }

    private function rmse(array $series): float
    {
        return $this->forecastError('rmse', $series);
    }

    private function forecastError(string $type, array $series): float
    {
        $values = array_values($series);
        if (count($values) < 2) {
            return 0.0;
        }
        $errors = [];
        for ($i = 1; $i < count($values); $i++) {
            $errors[] = $type === 'mae'
                ? abs($values[$i] - $values[$i - 1])
                : ($values[$i] - $values[$i - 1]) ** 2;
        }
        $aggregate = array_sum($errors) / count($errors);
        return round($type === 'rmse' ? sqrt($aggregate) : $aggregate, 2);
    }

    private function localAnalyticsFallback(EntityManagerInterface $em, array $ctx): array
    {
        ['usage' => $usage, 'series' => $series, 'forecast' => $forecast, 'weeklySeries' => $weeklySeries, 'weeklyForecast' => $weeklyForecast] = $ctx;
        $rows = $em->createQueryBuilder()
            ->select('r.reservationDate, r.reservationStartTime, r.capacity, r.purpose, r.status, r.rejectionReason, r.createdAt, f.name AS facilityName, f.capacity AS facilityCapacity')
            ->from(Reservation::class, 'r')
            ->join('r.facility', 'f')
            ->where('f.availableForReservation = true')
            ->orderBy('r.reservationDate', 'ASC')
            ->getQuery()
            ->getArrayResult();
        if ($rows === []) {
            $rows = $this->csvAnalyticsRows();
        }


        $agg = $this->aggregateFallbackRows($rows);
        $hourly                   = $agg['hourly'];
        $facilityLoadDistribution = $agg['facilityLoadDistribution'];
        $statusCounts             = $agg['statusCounts'];
        $purposeTotals            = $agg['purposeTotals'];
        $purposeCompleted         = $agg['purposeCompleted'];
        $monthlyAttendees         = $agg['monthlyAttendees'];
        $rejectionReasons         = $agg['rejectionReasons'];
        $setupGaps                = $agg['setupGaps'];


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


    private function aggregateReservationMetrics(array $reservations): array
    {
        $facilityStats = [];
        $facilityReservations = [];
        $facilityDailyBookings = [];
        $statusCounts = ['Approved' => 0, 'Pending' => 0, 'Rejected' => 0, 'Cancelled' => 0, 'Completed' => 0];
        $monthlyTrends = [];
        $weeklyTrends = [];
        $hourlyPeak = [];
        $hourlyHeatmap = [];
        $purposeCounts = [];
        $purposeSuccess = [];
        $setupGaps = [];
        $rsoCount = 0;
        $rsoCompleted = 0;
        $dayNames = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

        foreach ($reservations as $res) {
            $status = $res->getStatus();
            $effectiveStatus = $status === 'Completed' ? 'Approved' : $status;
            $statusCounts[$effectiveStatus] = ($statusCounts[$effectiveStatus] ?? 0) + 1;

            $facility = $res->getFacility();
            $facId    = $facility?->getId() ?? 0;
            $facName  = $facility->getName() ?? 'Unknown';
            $facilityStats[$facId] ??= ['name' => $facName, 'count' => 0, 'capacity' => 0, 'id' => $facId];
            $facilityStats[$facId]['count']++;
            $facilityStats[$facId]['capacity'] += $res->getCapacity() ?? 0;

            $resDate = $res->getReservationDate();
            if ($month = $resDate?->format('Y-m')) {
                $monthlyTrends[$month] = ($monthlyTrends[$month] ?? 0) + 1;
            }
            if ($week = $resDate?->format('Y-W')) {
                $weeklyTrends[$week] = ($weeklyTrends[$week] ?? 0) + 1;
            }

            $hour = (int) $res->getReservationStartTime()?->format('G');
            $timeSlot = $hour >= 5 && $hour < 12 ? 'Morning (5AM-12PM)'
                : ($hour >= 12 && $hour < 17 ? 'Afternoon (12PM-5PM)'
                : ($hour >= 17 && $hour < 21 ? 'Evening (5PM-9PM)' : 'Night (9PM-5AM)'));
            $hourlyPeak[$timeSlot] = ($hourlyPeak[$timeSlot] ?? 0) + 1;

            $purpose = $res->getPurpose() ?? 'General';
            $purposeCounts[$purpose] = ($purposeCounts[$purpose] ?? 0) + 1;
            $purposeSuccess[$purpose] ??= ['total' => 0, 'approved' => 0];
            $purposeSuccess[$purpose]['total']++;
            if ($effectiveStatus === 'Approved') {
                $purposeSuccess[$purpose]['approved']++;
            }

            if (str_contains(strtolower($purpose), 'rso')) {
                $rsoCount++;
                if ($effectiveStatus === 'Approved') $rsoCompleted++;
            }

            $createdAt = $res->getCreatedAt();
            if ($createdAt && $resDate) {
                $setupGaps[] = $createdAt->diff($resDate)->days;
            }

            $dateKey = $resDate?->format('Y-m-d');
            if ($facId && $dateKey) {
                $facilityDailyBookings[$facId . '_' . $dateKey] = true;
            }
            if ($facId && ($weekFmt = $resDate?->format('Y-\WW'))) {
                $facilityReservations[$facId] ??= ['name' => $facName, 'weekly' => []];
                $facilityReservations[$facId]['weekly'][$weekFmt] = ($facilityReservations[$facId]['weekly'][$weekFmt] ?? 0) + 1;
            }

            $dayOfWeek = $resDate?->format('N');
            if ($dayOfWeek) {
                $dayName = $dayNames[(int)$dayOfWeek - 1];
                $hourlyHeatmap[$hour][$dayName] = ($hourlyHeatmap[$hour][$dayName] ?? 0) + 1;
            }
        }

        return compact(
            'facilityStats', 'facilityReservations', 'facilityDailyBookings',
            'statusCounts', 'monthlyTrends', 'weeklyTrends',
            'hourlyPeak', 'hourlyHeatmap', 'purposeCounts',
            'purposeSuccess', 'setupGaps', 'rsoCount', 'rsoCompleted'
        );
    }

    private function calcEventSuccess(array $purposeSuccess): array
    {
        $result = [];
        foreach ($purposeSuccess as $purpose => $data) {
            $result[$purpose] = $data['total'] > 0 ? round(($data['approved'] / $data['total']) * 100, 1) : 0;
        }
        arsort($result);
        return $result;
    }

    private function buildPerFacilityWeekly(array $facilityReservations): array
    {
        $result = [];
        foreach ($facilityReservations as $data) {
            if (count($data['weekly']) < 2) continue;
            $forecast = $this->generateForecast($data['weekly'], 'W');
            $forecastValues = array_slice($forecast['forecast'] ?? [], 0, 4, true);
            $chartValue = $forecastValues !== [] ? round(array_sum($forecastValues) / count($forecastValues), 1) : 0;
            $result[] = ['facility' => $data['name'], 'forecast' => $forecastValues, 'historical' => $data['weekly'], 'chart_value' => $chartValue];
        }
        return $result;
    }

    private function buildRoomUtilization(array $facilityStats, array $facilityDailyBookings): array
    {
        $allDates = [];
        foreach (array_keys($facilityDailyBookings) as $dayKey) {
            $parts = explode('_', $dayKey);
            if (isset($parts[1])) $allDates[] = $parts[1];
        }
        $uniqueDates = array_unique($allDates);
        sort($uniqueDates);
        $totalDays = count($uniqueDates) > 0
            ? (new \DateTime($uniqueDates[0]))->diff(new \DateTime(end($uniqueDates)))->days + 1
            : 1;

        $result = [];
        foreach ($facilityStats as $id => $stats) {
            $busyDays = count(array_filter(
                array_keys($facilityDailyBookings),
                fn($k) => str_starts_with($k, $id . '_')
            ));
            $result[$stats['name']] = [
                'utilization_rate' => round($busyDays / max(1, $totalDays), 2),
                'total_bookings'   => $stats['count'],
            ];
        }
        return $result;
    }

    private function buildHeatmap(array $hourlyHeatmap): array
    {
        $days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
        $data = ['hours' => [], 'days' => $days, 'values' => []];
        for ($h = 0; $h < 24; $h++) {
            $data['hours'][] = $h;
            $data['values'][] = array_map(fn($d) => $hourlyHeatmap[$h][$d] ?? 0, $days);
        }
        return $data;
    }

    private function averageSetupGap(array $gaps): float
    {
        return $gaps === [] ? 0 : round(array_sum($gaps) / count($gaps), 1);
    }

    private function setupComplianceRate(array $gaps): float
    {
        return $gaps === [] ? 0 : round((count(array_filter($gaps, fn($g) => $g <= 3)) / count($gaps)) * 100, 1);
    }

    private function naiveForecastRmse(array $weeklyTrends): float
    {
        if (count($weeklyTrends) < 8) return 0;
        $values = array_values($weeklyTrends);
        $n = count($values);
        $errors = [];
        for ($i = $n - 4; $i < $n; $i++) {
            $predicted = $values[$i - 4] ?? $values[$i - 1];
            $errors[]  = ($values[$i] - $predicted) ** 2;
        }
        return $errors !== [] ? round(sqrt(array_sum($errors) / count($errors)), 2) : 0;
    }

    private function aggregateFallbackRows(array $rows): array
    {
        $hourly = [];
        $facilityLoadDistribution = [];
        $statusCounts = [];
        $purposeTotals = [];
        $purposeCompleted = [];
        $monthlyAttendees = [];
        $rejectionReasons = [];
        $setupGaps = [];

        foreach ($rows as $row) {
            $hour = $row['reservationStartTime'] instanceof \DateTimeInterface
                ? $row['reservationStartTime']->format('H') : '00';
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

        return compact(
            'hourly', 'facilityLoadDistribution', 'statusCounts',
            'purposeTotals', 'purposeCompleted', 'monthlyAttendees',
            'rejectionReasons', 'setupGaps'
        );
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
