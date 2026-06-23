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
        // All data (user-inputted + simulated) is treated as a single "Live" dataset
        $qb = $this->entityManager->createQueryBuilder()
            ->select('r, f')
            ->from(Reservation::class, 'r')
            ->innerJoin('r.facility', 'f')
            ->where('f.availableForReservation = true');

        if ($facilityId) {
            $qb->andWhere('f.id = :facilityId')
               ->setParameter('facilityId', (int) $facilityId);
        }

        $reservations = $qb->getQuery()->getResult();
        $totalCount = count($reservations);

        $base = [
            'source' => 'live',
            'dataSourceLabel' => 'Live',
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
        $dayOfWeekDemand      = $metrics['dayOfWeekDemand'];

        $derived = $this->buildDerivedMetrics($reservations, $statusCounts, $rsoCount, $rsoCompleted, $setupGaps);
        $overallCompletionRate = $derived['overallCompletionRate'];
        $rsoCompletionRate     = $derived['rsoCompletionRate'];
        $averageSetupGap       = $derived['averageSetupGap'];
        $setupComplianceRate   = $derived['setupComplianceRate'];
        $noShowRate            = $derived['noShowRate'];

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
        $weeklyMae       = $this->mae($weeklyTrends);
        $monthlyRmse     = $this->rmse($monthlyTrends);
        $monthlyMae      = $this->mae($monthlyTrends);
        $weeklyMape      = $this->mape($weeklyTrends);
        $monthlyMape     = $this->mape($monthlyTrends);

        // Build actual vs naive forecast comparison for model evaluation
        $actualVsForecast = $this->buildActualVsForecast($weeklyTrends);

        // CDO-level advanced metrics
        $demandVolatility = $this->calculateDemandVolatility($weeklyTrends);
        $seasonalPattern = $this->detectSeasonalPattern($monthlyTrends);
        $capacityEfficiency = $this->calculateCapacityEfficiency($facilityStats, $roomUtilization);
        $rollingMape = $this->calculateRollingMape($weeklyTrends);
        $dataQualityScore = $this->calculateDataQualityScore($reservations, $totalCount);
        $approvalFunnel = $this->buildApprovalFunnel($statusCounts, $totalCount);
        $facilityRiskScores = $this->calculateFacilityRiskScores($facilityStats, $statusCounts, $noShowRate);
        $trendMomentum = $this->calculateTrendMomentum($weeklyTrends);
        $peakTroughRatio = $this->calculatePeakTroughRatio($monthlyTrends);

        // Facility demand metrics (approved-only, separate from general reservation demand)
        $fdMetrics = $this->aggregateFacilityDemandMetrics($reservations);
        $approvedWeeklyTrends  = $fdMetrics['approvedWeeklyTrends'];
        $approvedMonthlyTrends = $fdMetrics['approvedMonthlyTrends'];
        $approvedWeeklyForecast  = $this->generateForecast($approvedWeeklyTrends, 'W');
        $approvedMonthlyForecast = $this->generateForecast($approvedMonthlyTrends, 'M');
        $approvedWeeklyRmse = $this->naiveForecastRmse($approvedWeeklyTrends);
        $approvedWeeklyMae  = $this->mae($approvedWeeklyTrends);
        $approvedWeeklyMape = $this->mape($approvedWeeklyTrends);
        $approvedMonthlyRmse = $this->rmse($approvedMonthlyTrends);
        $approvedMonthlyMae  = $this->mae($approvedMonthlyTrends);
        $approvedMonthlyMape = $this->mape($approvedMonthlyTrends);
        $facilityDemandActualVsForecast = $this->buildActualVsForecast($approvedWeeklyTrends);
        $facilityDemandRollingMape = $this->calculateRollingMape($approvedWeeklyTrends);
        $facilityDemandVolatility = $this->calculateDemandVolatility($approvedWeeklyTrends);
        $facilityDemandSeasonalPattern = $this->detectSeasonalPattern($approvedMonthlyTrends);
        $facilityDemandTrendMomentum = $this->calculateTrendMomentum($approvedWeeklyTrends);
        $facilityDemandPeakTroughRatio = $this->calculatePeakTroughRatio($approvedMonthlyTrends);
        $selectedFacilityName = $facilityId ? ($this->getFacilityNameById($facilities, (int) $facilityId) ?: 'Selected facility') : 'All facilities';
        $aggregateWeeklyDemand = ['facility' => $selectedFacilityName, 'historical' => $approvedWeeklyTrends, 'forecast' => $approvedWeeklyForecast['forecast'] ?? []];
        $aggregateMonthlyDemand = ['facility' => $selectedFacilityName, 'historical' => $approvedMonthlyTrends, 'forecast' => $approvedMonthlyForecast['forecast'] ?? []];
        $facilityDemandInsightWeekly = $this->buildFacilityDemandInsight($aggregateWeeklyDemand);
        $facilityDemandInsightMonthly = $this->buildFacilityDemandInsight($aggregateMonthlyDemand);

        return $this->buildEndpointResponse($endpoint, $base, compact(
            'facilities', 'weeklyTrends', 'weeklyForecast', 'monthlyTrends', 'monthlyForecast',
            'weeklyRmse', 'weeklyMae', 'weeklyMape', 'monthlyRmse', 'monthlyMae', 'monthlyMape',
            'hourlyPeak', 'purposeCounts', 'dayOfWeekDemand',
            'actualVsForecast', 'demandVolatility', 'seasonalPattern', 'capacityEfficiency',
            'rollingMape', 'dataQualityScore', 'approvalFunnel', 'facilityRiskScores',
            'trendMomentum', 'peakTroughRatio',
            'roomUtilization', 'heatmapData', 'overallCompletionRate', 'rsoCompletionRate',
            'statusCounts',
            'setupComplianceRate', 'noShowRate', 'averageSetupGap',
            'approvedWeeklyTrends', 'approvedWeeklyForecast', 'approvedMonthlyTrends', 'approvedMonthlyForecast',
            'approvedWeeklyRmse', 'approvedWeeklyMae', 'approvedWeeklyMape',
            'approvedMonthlyRmse', 'approvedMonthlyMae', 'approvedMonthlyMape',
            'facilityDemandActualVsForecast', 'facilityDemandRollingMape',
            'facilityDemandVolatility', 'facilityDemandSeasonalPattern', 'facilityDemandTrendMomentum',
            'facilityDemandPeakTroughRatio', 'facilityDemandInsightWeekly', 'facilityDemandInsightMonthly'
        ));
    }

    private function getFacilityNameById(array $facilities, int $facilityId): ?string
    {
        foreach ($facilities as $f) {
            if ((int) ($f['id'] ?? 0) === $facilityId) {
                return $f['name'] ?? null;
            }
        }
        return null;
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

    private function mape(array $series): float
    {
        $values = array_values($series);
        if (count($values) < 2) {
            return 0.0;
        }
        $errors = [];
        for ($i = 1; $i < count($values); $i++) {
            if ($values[$i] > 0) {
                $errors[] = abs($values[$i] - $values[$i - 1]) / $values[$i];
            }
        }
        if (empty($errors)) {
            return 0.0;
        }
        return round((array_sum($errors) / count($errors)) * 100, 2);
    }

    private function buildActualVsForecast(array $weeklyTrends): array
    {
        ksort($weeklyTrends);
        $keys = array_keys($weeklyTrends);
        $values = array_values($weeklyTrends);
        if (count($values) < 4) {
            return ['labels' => [], 'actual' => [], 'arima' => [], 'naive' => []];
        }

        $labels = [];
        $actual = [];
        $arima = [];
        $naive = [];

        // For ARIMA: use 3-period moving average + linear trend as prediction
        for ($i = 3; $i < count($values); $i++) {
            $labels[] = $keys[$i];
            $actual[] = $values[$i];

            // Naïve: previous period's value
            $naive[] = $values[$i - 1];

            // ARIMA (SMA+trend): 3-period moving average + trend adjustment
            $window = array_slice($values, $i - 3, 3);
            $sma = array_sum($window) / 3;
            $trend = ($window[2] - $window[0]) / 2;
            $arima[] = max(0, round($sma + $trend, 1));
        }

        return ['labels' => $labels, 'actual' => $actual, 'arima' => $arima, 'naive' => $naive];
    }

    private function localAnalyticsFallback(EntityManagerInterface $em, array $ctx): array
    {
        ['usage' => $usage, 'series' => $series, 'forecast' => $forecast, 'weeklySeries' => $weeklySeries, 'weeklyForecast' => $weeklyForecast] = $ctx;
        $rows = $em->createQueryBuilder()
            ->select('r.reservationDate, r.reservationStartTime, r.capacity, r.purpose, r.eventPurpose, r.eventPurposeOther, r.status, r.rejectionReason, r.createdAt, f.name AS facilityName, f.capacity AS facilityCapacity')
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

        [$roomUtilization, $facilityUtilizationRate] = $this->buildLocalRoomUtilization($rows);

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
                    'weekly_mape' => $this->mape($weeklySeries),
                    'monthly_mae' => $this->mae($series),
                    'monthly_rmse' => $this->rmse($series),
                    'monthly_mape' => $this->mape($series),
                ],
                'actual_vs_forecast' => $this->buildActualVsForecast($weeklySeries),
                'day_of_week_demand' => $this->buildFallbackDayOfWeek($rows),
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

    private function buildFallbackDayOfWeek(array $rows): array
    {
        $dayNames = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
        $result = array_fill_keys($dayNames, 0);
        foreach ($rows as $row) {
            $date = $row['reservationDate'] ?? null;
            if ($date instanceof \DateTimeInterface) {
                $dayIdx = (int) $date->format('N') - 1;
                $result[$dayNames[$dayIdx]]++;
            }
        }
        return $result;
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
        $dayOfWeekDemand = ['Mon' => 0, 'Tue' => 0, 'Wed' => 0, 'Thu' => 0, 'Fri' => 0, 'Sat' => 0, 'Sun' => 0];
        $dayNames = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

        foreach ($reservations as $res) {
            $effectiveStatus = $this->normalizeStatus($res->getStatus());
            $statusCounts[$effectiveStatus] = ($statusCounts[$effectiveStatus] ?? 0) + 1;

            $facility = $res->getFacility();
            $facId    = $facility?->getId() ?? 0;
            $facName  = $facility->getName() ?? 'Unknown';
            $facilityStats[$facId] ??= [
                'name' => $facName,
                'count' => 0,
                'capacity' => 0,
                'id' => $facId,
                'status_counts' => ['Approved' => 0, 'Pending' => 0, 'Rejected' => 0, 'Cancelled' => 0],
            ];
            $facilityStats[$facId]['count']++;
            $facilityStats[$facId]['capacity'] += $res->getCapacity() ?? 0;
            $facilityStats[$facId]['status_counts'][$effectiveStatus] = ($facilityStats[$facId]['status_counts'][$effectiveStatus] ?? 0) + 1;

            $resDate = $res->getReservationDate();
            if ($month = $resDate?->format('Y-m')) {
                $monthlyTrends[$month] = ($monthlyTrends[$month] ?? 0) + 1;
            }
            if ($week = $resDate?->format('o-\WW')) {
                $weeklyTrends[$week] = ($weeklyTrends[$week] ?? 0) + 1;
            }

            if ($resDate) {
                $dayIdx = (int) $resDate->format('N') - 1; // 0=Mon ... 6=Sun
                $dayOfWeekDemand[$dayNames[$dayIdx]]++;
            }

            $hour = (int) $res->getReservationStartTime()?->format('G');
            $timeSlot = $this->classifyTimeSlot($hour);
            $hourlyPeak[$timeSlot] = ($hourlyPeak[$timeSlot] ?? 0) + 1;

            $this->accumulateResMetrics(
                $res, $resDate, $facId, $facName, $effectiveStatus, $hour,
                $purposeCounts, $purposeSuccess, $rsoCount, $rsoCompleted,
                $setupGaps, $facilityDailyBookings, $facilityReservations, $hourlyHeatmap, $dayNames
            );
        }

        return compact(
            'facilityStats', 'facilityReservations', 'facilityDailyBookings',
            'statusCounts', 'monthlyTrends', 'weeklyTrends',
            'hourlyPeak', 'hourlyHeatmap', 'purposeCounts',
            'purposeSuccess', 'setupGaps', 'rsoCount', 'rsoCompleted', 'dayOfWeekDemand'
        );
    }

    private function aggregateFacilityDemandMetrics(array $reservations): array
    {
        $approvedMonthlyTrends = [];
        $approvedWeeklyTrends = [];

        foreach ($reservations as $res) {
            $effectiveStatus = $this->normalizeStatus($res->getStatus());

            if ($effectiveStatus !== 'Approved') {
                continue;
            }

            $resDate = $res->getReservationDate();
            if ($month = $resDate?->format('Y-m')) {
                $approvedMonthlyTrends[$month] = ($approvedMonthlyTrends[$month] ?? 0) + 1;
            }
            if ($week = $resDate?->format('o-\WW')) {
                $approvedWeeklyTrends[$week] = ($approvedWeeklyTrends[$week] ?? 0) + 1;
            }
        }

        return compact('approvedMonthlyTrends', 'approvedWeeklyTrends');
    }

    private function buildFacilityDemandInsight(?array $fc): string
    {
        if (!$fc || empty($fc['historical'])) {
            $name = $fc['facility'] ?? 'the selected facility';
            return "Not enough approved reservation history for {$name} to generate a reliable demand forecast.";
        }
        $name = $fc['facility'];
        $hist = $fc['historical'];
        $forecast = $fc['forecast'] ?? [];
        $values = array_values($hist);
        $first = $values[0];
        $last = end($values);
        $trend = $last > $first ? 'rising' : ($last < $first ? 'falling' : 'stable');
        if ($forecast) {
            $total = array_sum($forecast);
            $avg = $total / count($forecast);
            return "Approved-only demand for {$name} is {$trend} from {$first} to {$last} reservations. ARIMA anticipates ~" . round($avg, 1) . " approved reservations per upcoming period, with a total of " . round($total, 1) . " over the forecast horizon.";
        }
        return "Approved-only demand for {$name} is {$trend} from {$first} to {$last} reservations. No forecast could be generated due to limited data.";
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
        for ($h = 7; $h <= 20; $h++) {
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

            $status = $this->normalizeStatus((string) $row['status']);
            $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;

            $purpose = $this->resolveFallbackEventPurpose($row);
            $purposeTotals[$purpose] = ($purposeTotals[$purpose] ?? 0) + 1;
            if ($status === 'Approved') {
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
    private function classifyTimeSlot(int $hour): string
    {
        if ($hour >= 7  && $hour < 12) return 'Morning (7AM-12PM)';
        if ($hour >= 12 && $hour < 17) return 'Afternoon (12PM-5PM)';
        if ($hour >= 17 && $hour <= 20) return 'Evening (5PM-8PM)';
        return 'Outside School Hours';
    }

    private function accumulateResMetrics(
        mixed $res,
        ?\DateTimeInterface $resDate,
        int $facId,
        string $facName,
        string $effectiveStatus,
        int $hour,
        array &$purposeCounts,
        array &$purposeSuccess,
        int &$rsoCount,
        int &$rsoCompleted,
        array &$setupGaps,
        array &$facilityDailyBookings,
        array &$facilityReservations,
        array &$hourlyHeatmap,
        array $dayNames,
    ): void {
        $purpose = $this->resolveEventPurpose($res);
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
            $dayName = $dayNames[(int) $dayOfWeek - 1];
            $hourlyHeatmap[$hour][$dayName] = ($hourlyHeatmap[$hour][$dayName] ?? 0) + 1;
        }
    }

    private function resolveEventPurpose(mixed $res): string
    {
        $eventPurpose = $res->getEventPurpose();
        $eventPurposeOther = $res->getEventPurposeOther();
        $generalPurpose = $res->getPurpose();

        if (is_string($eventPurpose) && strtolower(trim($eventPurpose)) === 'others') {
            $other = $this->normalizePurposeText($eventPurposeOther);
            if ($other !== '') {
                return $other;
            }
        }

        $eventPurpose = $this->normalizePurposeText($eventPurpose);
        if ($eventPurpose !== '') {
            return $eventPurpose;
        }

        $generalPurpose = $this->normalizePurposeText($generalPurpose);
        if ($generalPurpose !== '') {
            return $generalPurpose;
        }

        return 'General';
    }

    private function normalizePurposeText(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }
        $value = trim($value);
        return $value === '' ? '' : $value;
    }

    private function normalizeStatus(?string $status): string
    {
        $status = trim($status ?? '');
        $map = [
            'approved' => 'Approved',
            'pending' => 'Pending',
            'rejected' => 'Rejected',
            'cancelled' => 'Cancelled',
            'canceled' => 'Cancelled',
            'completed' => 'Approved',
            'suggested' => 'Suggested',
        ];
        return $map[strtolower($status)] ?? $status;
    }

    private function resolveFallbackEventPurpose(array $row): string
    {
        $eventPurpose = $this->normalizePurposeText($row['eventPurpose'] ?? null);
        $eventPurposeOther = $this->normalizePurposeText($row['eventPurposeOther'] ?? null);
        $generalPurpose = $this->normalizePurposeText($row['purpose'] ?? null);

        if (strtolower($eventPurpose) === 'others' && $eventPurposeOther !== '') {
            return $eventPurposeOther;
        }
        if ($eventPurpose !== '') {
            return $eventPurpose;
        }
        if ($generalPurpose !== '') {
            return $generalPurpose;
        }
        return 'General';
    }

    private function buildDerivedMetrics(
        array $reservations,
        array $statusCounts,
        int $rsoCount,
        int $rsoCompleted,
        array $setupGaps,
    ): array {
        $total = count($reservations);
        $approvedCompleted = ($statusCounts['Approved'] ?? 0) + ($statusCounts['Completed'] ?? 0);
        return [
            'overallCompletionRate' => $total > 0 ? round(($approvedCompleted / $total) * 100, 1) : 0,
            'rsoCompletionRate'     => $rsoCount > 0 ? round(($rsoCompleted / $rsoCount) * 100, 1) : 0,
            'averageSetupGap'       => $this->averageSetupGap($setupGaps),
            'setupComplianceRate'   => $this->setupComplianceRate($setupGaps),
            'noShowRate'            => $total > 0 ? round((($statusCounts['Cancelled'] + $statusCounts['Rejected']) / $total) * 100, 1) : 0,
        ];
    }

    private function buildEndpointResponse(string $endpoint, array $base, array $d): array
    {
        return match ($endpoint) {
            'meta' => array_merge($base, [
                'facilities' => array_slice($d['facilities'], 0, 20),
                'data_quality' => $d['dataQualityScore'],
            ]),
            'planning' => array_merge($base, [
                'forecast_series' => [
                    'weekly'  => ['historical' => $d['weeklyTrends'],  'forecast' => $d['weeklyForecast']['forecast']  ?? [], 'lower' => $d['weeklyForecast']['lower']  ?? [], 'upper' => $d['weeklyForecast']['upper']  ?? []],
                    'monthly' => ['historical' => $d['monthlyTrends'], 'forecast' => $d['monthlyForecast']['forecast'] ?? [], 'lower' => $d['monthlyForecast']['lower'] ?? [], 'upper' => $d['monthlyForecast']['upper'] ?? []],
                ],
                'forecast_accuracy' => [
                    'weekly_rmse' => $d['weeklyRmse'],
                    'weekly_mae'  => $d['weeklyMae'],
                    'weekly_mape' => $d['weeklyMape'],
                    'monthly_rmse'=> $d['monthlyRmse'],
                    'monthly_mae' => $d['monthlyMae'],
                    'monthly_mape'=> $d['monthlyMape'],
                ],
                'actual_vs_forecast'     => $d['actualVsForecast'],
                'day_of_week_demand'     => $d['dayOfWeekDemand'],
                'peak_demand_hours'      => $d['hourlyPeak'],
                'event_type_distribution'=> $d['purposeCounts'],
                'demand_volatility'      => $d['demandVolatility'],
                'seasonal_pattern'       => $d['seasonalPattern'],
                'rolling_mape'           => $d['rollingMape'],
                'trend_momentum'         => $d['trendMomentum'],
                'peak_trough_ratio'      => $d['peakTroughRatio'],
                'data_quality'           => $d['dataQualityScore'],
            ]),
            'organizing' => array_merge($base, [
                'facility_load_distribution' => array_column(array_slice($d['facilities'], 0, 10), 'count', 'name'),
                'peak_usage_times'  => $d['hourlyPeak'],
                'room_utilization'  => $d['roomUtilization'],
                'peak_usage_heatmap'=> $d['heatmapData'],
                'capacity_efficiency' => $d['capacityEfficiency'],
                'facility_risk_scores'=> $d['facilityRiskScores'],
                'data_quality'        => $d['dataQualityScore'],
            ]),
            'leading' => array_merge($base, [
                'overall_completion_rate' => $d['overallCompletionRate'],
                'rso_completion_rate'     => $d['rsoCompletionRate'],
                'participant_demand_trend'=> $d['monthlyTrends'],
                'approval_funnel'         => $d['approvalFunnel'],
                'data_quality'            => $d['dataQualityScore'],
            ]),
            'controlling' => array_merge($base, [
                'target_achievement' => [
                    'Approved'  => $d['statusCounts']['Approved']  ?? 0,
                    'Pending'   => $d['statusCounts']['Pending']   ?? 0,
                    'Rejected'  => $d['statusCounts']['Rejected']  ?? 0,
                    'Cancelled' => $d['statusCounts']['Cancelled'] ?? 0,
                ],
                'facility_utilization_rate' => $d['roomUtilization'],
                'setup_compliance_rate'     => $d['setupComplianceRate'],
                'no_show_rate'              => $d['noShowRate'],
                'average_setup_gap'         => $d['averageSetupGap'],
                'rejection_analysis'        => ['Rejected' => $d['statusCounts']['Rejected'] ?? 0, 'Cancelled' => $d['statusCounts']['Cancelled'] ?? 0],
                'facility_risk_scores'      => $d['facilityRiskScores'],
                'approval_funnel'           => $d['approvalFunnel'],
                'data_quality'              => $d['dataQualityScore'],
            ]),
            'facility_demand' => array_merge($base, [
                'facility_demand_series' => [
                    'weekly'  => ['historical' => $d['approvedWeeklyTrends'],  'forecast' => $d['approvedWeeklyForecast']['forecast']  ?? [], 'lower' => $d['approvedWeeklyForecast']['lower']  ?? [], 'upper' => $d['approvedWeeklyForecast']['upper']  ?? []],
                    'monthly' => ['historical' => $d['approvedMonthlyTrends'], 'forecast' => $d['approvedMonthlyForecast']['forecast'] ?? [], 'lower' => $d['approvedMonthlyForecast']['lower'] ?? [], 'upper' => $d['approvedMonthlyForecast']['upper'] ?? []],
                ],
                'facility_demand_accuracy' => [
                    'weekly_rmse' => $d['approvedWeeklyRmse'],
                    'weekly_mae'  => $d['approvedWeeklyMae'],
                    'weekly_mape' => $d['approvedWeeklyMape'],
                    'monthly_rmse'=> $d['approvedMonthlyRmse'],
                    'monthly_mae' => $d['approvedMonthlyMae'],
                    'monthly_mape'=> $d['approvedMonthlyMape'],
                ],
                'facility_actual_vs_forecast' => $d['facilityDemandActualVsForecast'],
                'facility_rolling_mape'       => $d['facilityDemandRollingMape'],
                'facility_demand_volatility'  => $d['facilityDemandVolatility'],
                'facility_demand_seasonal_pattern' => $d['facilityDemandSeasonalPattern'],
                'facility_demand_trend_momentum'   => $d['facilityDemandTrendMomentum'],
                'facility_demand_peak_trough_ratio'=> $d['facilityDemandPeakTroughRatio'],
                'facility_demand_insight_weekly'  => $d['facilityDemandInsightWeekly'],
                'facility_demand_insight_monthly' => $d['facilityDemandInsightMonthly'],
                'data_quality' => $d['dataQualityScore'],
            ]),
            default => array_merge($base, ['error' => 'Unknown endpoint']),
        };
    }

    private function buildLocalRoomUtilization(array $rows): array
    {
        $roomUtilization = [];
        foreach ($rows as $row) {
            $facilityName = (string) $row['facilityName'];
            $roomUtilization[$facilityName] ??= ['reservations' => 0, 'total_capacity' => 0, 'available_capacity' => 0, 'utilization_rate' => 0];
            $roomUtilization[$facilityName]['reservations']++;
            $roomUtilization[$facilityName]['total_capacity']       += (int) $row['capacity'];
            $roomUtilization[$facilityName]['available_capacity']   += max(0, (int) ($row['facilityCapacity'] ?? 0));
        }
        foreach ($roomUtilization as $name => $values) {
            $roomUtilization[$name]['utilization_rate'] = $values['available_capacity'] === 0
                ? 0
                : round($values['total_capacity'] / $values['available_capacity'], 4);
        }
        $facilityUtilizationRate = array_map(fn(array $v): float => $v['utilization_rate'], $roomUtilization);
        return [$roomUtilization, $facilityUtilizationRate];
    }

    // ═══════════════════════════════════════════════════════════════
    // CDO-LEVEL ADVANCED ANALYTICS METHODS
    // ═══════════════════════════════════════════════════════════════

    private function calculateDemandVolatility(array $weeklyTrends): array
    {
        $values = array_values($weeklyTrends);
        if (count($values) < 3) {
            return ['cv' => 0, 'label' => 'Insufficient data', 'std_dev' => 0, 'mean' => 0];
        }
        $mean = array_sum($values) / count($values);
        $stdDev = $this->calculateStdDev($values);
        $cv = $mean > 0 ? round(($stdDev / $mean) * 100, 1) : 0;
        $label = $cv < 15 ? 'Stable' : ($cv < 30 ? 'Moderate' : ($cv < 50 ? 'Volatile' : 'Highly Volatile'));
        return ['cv' => $cv, 'label' => $label, 'std_dev' => round($stdDev, 2), 'mean' => round($mean, 2)];
    }

    private function detectSeasonalPattern(array $monthlyTrends): array
    {
        if (count($monthlyTrends) < 4) {
            return ['detected' => false, 'peak_months' => [], 'trough_months' => [], 'seasonality_strength' => 0];
        }
        ksort($monthlyTrends);
        $values = array_values($monthlyTrends);
        $keys = array_keys($monthlyTrends);
        $mean = array_sum($values) / count($values);

        $peakMonths = [];
        $troughMonths = [];
        foreach ($values as $i => $v) {
            if ($v > $mean * 1.3) {
                $peakMonths[] = $keys[$i];
            } elseif ($v < $mean * 0.7) {
                $troughMonths[] = $keys[$i];
            }
        }

        $max = max($values);
        $min = min($values);
        $strength = $mean > 0 ? round((($max - $min) / $mean) * 100, 1) : 0;

        return [
            'detected' => count($peakMonths) > 0 || count($troughMonths) > 0,
            'peak_months' => array_slice($peakMonths, -3),
            'trough_months' => array_slice($troughMonths, -3),
            'seasonality_strength' => $strength,
            'peak_value' => $max,
            'trough_value' => $min,
        ];
    }

    private function calculateCapacityEfficiency(array $facilityStats, array $roomUtilization): array
    {
        $result = [];
        foreach ($facilityStats as $stats) {
            $name = $stats['name'];
            $util = $roomUtilization[$name] ?? null;
            $rate = $util ? ($util['utilization_rate'] ?? 0) : 0;
            $efficiency = min(100, round($rate * 100, 1));
            $status = $efficiency >= 70 ? 'optimal' : ($efficiency >= 40 ? 'adequate' : ($efficiency >= 15 ? 'underused' : 'idle'));
            $result[$name] = ['efficiency' => $efficiency, 'status' => $status, 'bookings' => $stats['count']];
        }
        uasort($result, fn($a, $b) => $b['efficiency'] <=> $a['efficiency']);
        return $result;
    }

    private function calculateRollingMape(array $weeklyTrends): array
    {
        ksort($weeklyTrends);
        $values = array_values($weeklyTrends);
        $keys = array_keys($weeklyTrends);
        $windowSize = 4;
        $rollingMape = [];

        if (count($values) < $windowSize + 1) {
            return ['labels' => [], 'values' => []];
        }

        for ($i = $windowSize; $i < count($values); $i++) {
            $window = array_slice($values, $i - $windowSize, $windowSize);
            $predicted = array_sum($window) / $windowSize;
            $actual = $values[$i];
            $mape = $actual > 0 ? abs(($actual - $predicted) / $actual) * 100 : 0;
            $rollingMape[] = ['label' => $keys[$i], 'value' => round($mape, 1)];
        }

        return [
            'labels' => array_column($rollingMape, 'label'),
            'values' => array_column($rollingMape, 'value'),
        ];
    }

    private function calculateDataQualityScore(array $reservations, int $totalCount): array
    {
        if ($totalCount === 0) {
            return ['score' => 0, 'grade' => 'N/A', 'completeness' => 0, 'consistency' => 0, 'timeliness' => 0];
        }

        $hasDate = 0; $hasFacility = 0; $hasTime = 0; $hasPurpose = 0;
        $futureCount = 0; $now = new \DateTime();

        foreach ($reservations as $res) {
            if ($res->getReservationDate()) $hasDate++;
            if ($res->getFacility()) $hasFacility++;
            if ($res->getReservationStartTime()) $hasTime++;
            if ($res->getPurpose() || $res->getEventPurpose()) $hasPurpose++;
            if ($res->getReservationDate() && $res->getReservationDate() <= $now) $futureCount++;
        }

        $completeness = round((($hasDate + $hasFacility + $hasTime + $hasPurpose) / ($totalCount * 4)) * 100, 1);
        $consistency = $totalCount > 0 ? round(($hasFacility / $totalCount) * 100, 1) : 0;
        $timeliness = $totalCount > 0 ? round(($futureCount / $totalCount) * 100, 1) : 0;
        $score = round(($completeness * 0.4 + $consistency * 0.3 + $timeliness * 0.3), 1);
        $grade = $score >= 90 ? 'A+' : ($score >= 80 ? 'A' : ($score >= 70 ? 'B' : ($score >= 60 ? 'C' : 'D')));

        return ['score' => $score, 'grade' => $grade, 'completeness' => $completeness, 'consistency' => $consistency, 'timeliness' => $timeliness];
    }

    private function buildApprovalFunnel(array $statusCounts, int $totalCount): array
    {
        $submitted = $totalCount;
        $pending = ($statusCounts['Pending'] ?? 0);
        $approved = ($statusCounts['Approved'] ?? 0) + ($statusCounts['Completed'] ?? 0);
        $rejected = ($statusCounts['Rejected'] ?? 0);
        $cancelled = ($statusCounts['Cancelled'] ?? 0);

        return [
            'submitted' => $submitted,
            'pending' => $pending,
            'approved' => $approved,
            'rejected' => $rejected,
            'cancelled' => $cancelled,
            'approval_rate' => $submitted > 0 ? round(($approved / $submitted) * 100, 1) : 0,
            'rejection_rate' => $submitted > 0 ? round(($rejected / $submitted) * 100, 1) : 0,
            'cancellation_rate' => $submitted > 0 ? round(($cancelled / $submitted) * 100, 1) : 0,
            'conversion_rate' => $submitted > 0 ? round(($approved / $submitted) * 100, 1) : 0,
        ];
    }

    private function calculateFacilityRiskScores(array $facilityStats, array $statusCounts, float $noShowRate): array
    {
        $total = array_sum($statusCounts);
        $result = [];

        foreach ($facilityStats as $stats) {
            $bookings = $stats['count'];
            $overloadRisk = $bookings > ($total / max(1, count($facilityStats))) * 1.5 ? 'High' : ($bookings > ($total / max(1, count($facilityStats))) ? 'Medium' : 'Low');
            $facilityStatuses = $stats['status_counts'] ?? [];
            $facilityFailed = ($facilityStatuses['Cancelled'] ?? 0) + ($facilityStatuses['Rejected'] ?? 0);
            $facilityNoShowRate = $bookings > 0 ? round(($facilityFailed / $bookings) * 100, 1) : $noShowRate;
            $noShowRisk = $facilityNoShowRate > 30 ? 'High' : ($facilityNoShowRate > 15 ? 'Medium' : 'Low');
            $riskScore = ($overloadRisk === 'High' ? 30 : ($overloadRisk === 'Medium' ? 15 : 0)) + ($noShowRisk === 'High' ? 30 : ($noShowRisk === 'Medium' ? 15 : 0));
            $result[$stats['name']] = ['risk_score' => $riskScore, 'overload_risk' => $overloadRisk, 'no_show_risk' => $noShowRisk, 'bookings' => $bookings, 'no_show_rate' => $facilityNoShowRate];
        }
        uasort($result, fn($a, $b) => $b['risk_score'] <=> $a['risk_score']);
        return $result;
    }

    private function calculateTrendMomentum(array $weeklyTrends): array
    {
        ksort($weeklyTrends);
        $values = array_values($weeklyTrends);
        $count = count($values);
        if ($count < 4) {
            return ['direction' => 'neutral', 'strength' => 0, 'short_term' => 0, 'long_term' => 0];
        }

        // Short-term: last 4 weeks slope
        $shortSlice = array_slice($values, -4);
        $shortTrend = ($shortSlice[3] - $shortSlice[0]) / 3;

        // Long-term: overall slope
        $longTrend = ($values[$count - 1] - $values[0]) / max(1, $count - 1);

        $direction = $shortTrend > 0.5 ? 'up' : ($shortTrend < -0.5 ? 'down' : 'neutral');
        $mean = array_sum($values) / $count;
        $strength = $mean > 0 ? round(abs($shortTrend) / $mean * 100, 1) : 0;

        return [
            'direction' => $direction,
            'strength' => min(100, $strength),
            'short_term' => round($shortTrend, 2),
            'long_term' => round($longTrend, 2),
        ];
    }

    private function calculatePeakTroughRatio(array $monthlyTrends): float
    {
        $values = array_values($monthlyTrends);
        if (count($values) < 2) return 1.0;
        $min = min($values);
        return $min > 0 ? round(max($values) / $min, 2) : (float) max($values);
    }
}
