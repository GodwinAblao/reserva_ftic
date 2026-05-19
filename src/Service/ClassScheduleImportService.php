<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ClassSchedule;
use App\Entity\Facility;
use App\Repository\ClassScheduleRepository;
use App\Repository\FacilityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class ClassScheduleImportService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ClassScheduleRepository $classScheduleRepo,
        private readonly FacilityRepository $facilityRepo,
        private readonly ClassScheduleFacultyMatcher $facultyMatcher,
    ) {
    }

    /**
     * @return array{
     *   success: bool,
     *   message: string,
     *   created: int,
     *   processed: int,
     *   relocated: int,
     *   warnings: list<string>,
     *   date: ?string
     * }
     */
    public function import(?UploadedFile $file, string $pasteData, string $sourceName): array
    {
        $rows = $this->readScheduleRows($file, $pasteData);
        if ($rows === []) {
            return [
                'success' => false,
                'message' => 'No schedule rows were found in the uploaded data.',
                'created' => 0,
                'processed' => 0,
                'relocated' => 0,
                'warnings' => [],
                'date' => null,
            ];
        }

        $previousFacilityMap = $this->classScheduleRepo->buildFacilityMapForImportDiff();
        $importBatchId = bin2hex(random_bytes(8));
        $this->classScheduleRepo->deleteAll();
        $this->em->flush();

        $created = 0;
        $relocated = 0;
        $processed = 0;
        $errors = [];
        $seen = [];
        $firstCreatedDate = null;
        $rowNum = 0;

        foreach ($rows as $data) {
            $rowNum++;
            $data = array_map(static fn ($value) => trim((string) $value), $data);

            if ($this->isScheduleHeaderRow($data)) {
                continue;
            }

            $parsed = $this->parseRow($data);
            if ($parsed === null) {
                $errors[] = "Row $rowNum: Unrecognized row format or missing required fields.";
                continue;
            }

            if (empty($parsed['facility']) || empty($parsed['day']) || empty($parsed['start']) || empty($parsed['end'])) {
                $errors[] = "Row $rowNum: Missing required fields.";
                continue;
            }

            $processed++;
            $facility = $this->findFacilityByName($parsed['facility']);
            if (!$facility) {
                $errors[] = "Row $rowNum: Facility '{$parsed['facility']}' not found.";
                continue;
            }

            $dates = $this->parseScheduleDates($parsed['day']);
            $start = $this->parseScheduleTime($parsed['start']);
            $end = $this->parseScheduleTime($parsed['end']);

            if ($dates === [] || !$start || !$end) {
                $errors[] = "Row $rowNum: Invalid date or time format.";
                continue;
            }

            if ($end <= $start) {
                $errors[] = "Row $rowNum: End time must be after start time.";
                continue;
            }

            $dayLabel = $this->normalizeDayLabel($parsed['day']);

            foreach ($dates as $date) {
                $dedupeKey = implode('|', [
                    $facility->getId(),
                    $date->format('Y-m-d'),
                    $start->format('H:i'),
                    $end->format('H:i'),
                    $parsed['course_code'],
                    $parsed['section'] ?? '',
                ]);

                if (isset($seen[$dedupeKey])) {
                    continue;
                }
                $seen[$dedupeKey] = true;

                $matchKey = ClassScheduleRepository::buildMatchKey(
                    $parsed['course_code'],
                    $parsed['section'] ?? '',
                    $dayLabel,
                    $start->format('H:i'),
                    $end->format('H:i'),
                );

                $schedule = new ClassSchedule();
                $schedule->setFacility($facility);
                $schedule->setScheduleDate($date);
                $schedule->setDayOfWeek($dayLabel);
                $schedule->setStartTime(clone $start);
                $schedule->setEndTime(clone $end);
                $schedule->setCourseCode($parsed['course_code']);
                $schedule->setSection($parsed['section'] ?: null);
                $schedule->setFacultyName($parsed['faculty_name'] ?: null);
                $schedule->setFacultyEmail($parsed['faculty_email'] ?: null);
                $this->facultyMatcher->attachFacultyUser($schedule);
                $schedule->setSource($sourceName);
                $schedule->setImportBatchId($importBatchId);
                $schedule->setScheduleIdentifier(sha1($dedupeKey));

                if (isset($previousFacilityMap[$matchKey])) {
                    $oldFacilityId = $previousFacilityMap[$matchKey];
                    if ($oldFacilityId !== $facility->getId()) {
                        $oldFacility = $this->em->getRepository(Facility::class)->find($oldFacilityId);
                        if ($oldFacility) {
                            $schedule->setPreviousFacility($oldFacility);
                            $schedule->setIsRelocated(true);
                            $relocated++;
                        }
                    }
                }

                $this->em->persist($schedule);
                $created++;

                if ($firstCreatedDate === null || $date < $firstCreatedDate) {
                    $firstCreatedDate = clone $date;
                }

                if ($created % 100 === 0) {
                    $this->em->flush();
                }
            }
        }

        $this->em->flush();

        $message = $created > 0
            ? "Imported $created class schedule entries from $processed row(s)." . ($relocated > 0 ? " $relocated relocated to a different facility." : '')
            : 'No class schedules were created.';

        $warnings = [];
        $today = new \DateTimeImmutable('today');
        $weekStart = $today->modify('monday this week');
        $weekEnd = $weekStart->modify('+6 days');

        if ($created > 0 && $firstCreatedDate && ($firstCreatedDate->format('Y-m-d') < $weekStart->format('Y-m-d') || $firstCreatedDate->format('Y-m-d') > $weekEnd->format('Y-m-d'))) {
            $warnings[] = 'Imported schedules are not in the current week; the calendar will open that week.';
        }

        return [
            'success' => $created > 0,
            'message' => $message,
            'created' => $created,
            'processed' => $processed,
            'relocated' => $relocated,
            'warnings' => array_slice(array_merge($warnings, $errors), 0, 15),
            'date' => $firstCreatedDate?->format('Y-m-d'),
        ];
    }

    /**
     * @param list<string> $data
     * @return array<string, string|null>|null
     */
    private function parseRow(array $data): ?array
    {
        $colCount = count(array_filter($data, static fn ($v) => $v !== ''));

        // Extended: facility, day, start, end, course_code, section, faculty_name [, faculty_email]
        if ($colCount >= 7) {
            [$facility, $day, $start, $end, $courseCode, $section, $facultyName, $facultyEmail] = array_pad($data, 8, null);

            return [
                'facility' => $facility,
                'day' => $day,
                'start' => $start,
                'end' => $end,
                'course_code' => $courseCode ?: 'CLASS',
                'section' => $section,
                'faculty_name' => $facultyName,
                'faculty_email' => $facultyEmail,
            ];
        }

        // Legacy: facility, day, start, end, title, type
        if ($colCount >= 4) {
            [$facility, $day, $start, $end, $title] = array_pad($data, 6, '');
            $title = trim($title);
            $courseCode = $title;
            $facultyName = null;

            if (str_contains($title, ' - ')) {
                [$courseCode, $facultyName] = array_map('trim', explode(' - ', $title, 2));
            }

            return [
                'facility' => $facility,
                'day' => $day,
                'start' => $start,
                'end' => $end,
                'course_code' => $courseCode ?: 'CLASS',
                'section' => null,
                'faculty_name' => $facultyName,
                'faculty_email' => null,
            ];
        }

        return null;
    }

    /**
     * @return list<array<int, string>>
     */
    private function readScheduleRows(?UploadedFile $file, string $pasteData): array
    {
        $rows = [];

        if ($file instanceof UploadedFile) {
            $handle = fopen($file->getPathname(), 'r');
            if ($handle === false) {
                return [];
            }

            while (($data = fgetcsv($handle)) !== false) {
                if ($data !== [null] && $data !== false) {
                    $rows[] = $data;
                }
            }

            fclose($handle);

            return $rows;
        }

        foreach (explode("\n", str_replace("\r", '', $pasteData)) as $line) {
            if (trim($line) !== '') {
                $rows[] = str_getcsv($line);
            }
        }

        return $rows;
    }

    /**
     * @param list<string> $data
     */
    private function isScheduleHeaderRow(array $data): bool
    {
        $first = strtolower(trim((string) ($data[0] ?? '')));
        $second = strtolower(trim((string) ($data[1] ?? '')));

        return $first === 'facility' && in_array($second, ['date', 'day'], true);
    }

    /**
     * @return list<\DateTimeInterface>
     */
    private function parseScheduleDates(string $value): array
    {
        $normalized = strtolower(trim($value));
        $dayMap = [
            'monday' => 0, 'mon' => 0,
            'tuesday' => 1, 'tue' => 1,
            'wednesday' => 2, 'wed' => 2,
            'thursday' => 3, 'thu' => 3,
            'friday' => 4, 'fri' => 4,
            'saturday' => 5, 'sat' => 5,
            'sunday' => 6, 'sun' => 6,
        ];

        if (isset($dayMap[$normalized])) {
            $today = new \DateTimeImmutable('today');
            $weekStart = $today->modify('monday this week');
            $dates = [];

            for ($week = 0; $week < 18; $week++) {
                $target = $weekStart
                    ->modify('+' . $week . ' weeks')
                    ->modify('+' . $dayMap[$normalized] . ' days');
                $dates[] = \DateTime::createFromImmutable($target);
            }

            return $dates;
        }

        $date = \DateTime::createFromFormat('!Y-m-d', trim($value));
        if ($date && $date->format('Y-m-d') === trim($value)) {
            return [$date];
        }

        return [];
    }

    private function parseScheduleTime(string $value): ?\DateTimeInterface
    {
        $value = trim($value);

        foreach (['!H:i', '!H:i:s', '!g:i A', '!h:i A'] as $format) {
            $time = \DateTime::createFromFormat($format, $value);
            if ($time instanceof \DateTimeInterface) {
                return $time;
            }
        }

        return null;
    }

    private function normalizeDayLabel(string $value): string
    {
        $normalized = strtolower(trim($value));
        $map = [
            'mon' => 'Monday', 'monday' => 'Monday',
            'tue' => 'Tuesday', 'tuesday' => 'Tuesday',
            'wed' => 'Wednesday', 'wednesday' => 'Wednesday',
            'thu' => 'Thursday', 'thursday' => 'Thursday',
            'fri' => 'Friday', 'friday' => 'Friday',
            'sat' => 'Saturday', 'saturday' => 'Saturday',
            'sun' => 'Sunday', 'sunday' => 'Sunday',
        ];

        return $map[$normalized] ?? ucfirst($normalized);
    }

    private function findFacilityByName(string $name): ?Facility
    {
        $facility = $this->facilityRepo->findOneBy(['name' => $name]);
        if ($facility) {
            return $facility;
        }

        $facility = $this->facilityRepo->createQueryBuilder('f')
            ->where('LOWER(f.name) = LOWER(:name)')
            ->setParameter('name', $name)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $facility ?: null;
    }
}
