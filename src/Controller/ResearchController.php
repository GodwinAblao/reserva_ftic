<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ResearchContent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Service\SupabaseStorageService;

#[Route('/research')]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
class ResearchController extends AbstractController
{
    public function __construct(
        private readonly SupabaseStorageService $storageService,
    ) {
    }

    #[Route('', name: 'research_index', methods: ['GET'])]
    public function index(Request $request, EntityManagerInterface $em): Response
    {
        $query = trim((string) $request->query->get('q', ''));
        $type = trim((string) $request->query->get('type', ''));
        if (!in_array($type, ['News', 'Article', 'Research'], true)) {
            $type = '';
        }
        $articlePage = max(1, $request->query->getInt('articlesPage', 1));
        $newsPage = max(1, $request->query->getInt('newsPage', 1));
        $researchPage = max(1, $request->query->getInt('researchPage', 1));
        $perPage = 6;

        $articleSection = $this->paginateResearchSection($em, 'Article', $query, $type, $articlePage, $perPage);
        $newsSection = $this->paginateResearchSection($em, 'News', $query, $type, $newsPage, $perPage);
        $researchSection = $this->paginateResearchSection($em, 'Research', $query, $type, $researchPage, $perPage);

        return $this->render('research/index.html.twig', [
            'articleItems' => $articleSection['items'],
            'newsItems' => $newsSection['items'],
            'researchItems' => $researchSection['items'],
            'query' => $query,
            'type' => $type,
            'articlePagination' => $articleSection['pagination'],
            'newsPagination' => $newsSection['pagination'],
            'researchPagination' => $researchSection['pagination'],
            'articleImageSrcById' => $articleSection['imageSrcById'],
            'researchFileAvailabilityById' => $researchSection['fileAvailabilityById'],
        ]);
    }

    #[Route('/new/{type}', name: 'research_new', methods: ['GET', 'POST'], defaults: ['type' => 'Article'], requirements: ['type' => 'Article|Research|News'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    public function new(Request $request, EntityManagerInterface $em, string $type = 'Article'): Response
    {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('research_new', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            $item = (new ResearchContent())
                ->setAuthor($this->getUser())
                ->setTitle((string) $request->request->get('title'))
                ->setType((string) $request->request->get('type', $type));

            // Handle different types
            if ($type === 'Research') {
                $item->setRepositoryType((string) $request->request->get('repositoryType'))
                    ->setAuthors((string) $request->request->get('authors'))
                    ->setAbstract((string) $request->request->get('abstract'))
                    ->setCategory('Research')
                    ->setVisibility('Public');
            } else {
                $item->setCategory((string) $request->request->get('category', 'General'))
                    ->setTags($request->request->get('tags'))
                    ->setSummary((string) $request->request->get('summary'))
                    ->setBody($request->request->get('body'))
                    ->setEmbeddedLink($request->request->get('embeddedLink'))
                    ->setExternalLink($request->request->get('externalLink'))
                    ->setVisibility((string) $request->request->get('visibility', 'Public'));
            }

            $uploadedFile = $request->files->get('file');
            if ($type === 'Research' && !$uploadedFile instanceof UploadedFile) {
                $this->addFlash('error', 'Please upload a PDF file for Research content.');
                return $this->render('research/new.html.twig', ['type' => $type]);
            }

            if ($uploadedFile instanceof UploadedFile) {
                $filePath = $this->storeResearchFile($uploadedFile, $type);
                if ($filePath === null) {
                    return $this->render('research/new.html.twig', ['type' => $type]);
                }

                $item->setFilePath($filePath);
            }

            $em->persist($item);
            $em->flush();

            $this->addFlash('success', 'Research content published.');

            return $this->redirectToRoute('research_index');
        }

        return $this->render('research/new.html.twig', ['type' => $type]);
    }

    #[Route('/{id}/edit', name: 'research_edit', methods: ['GET', 'POST'])]
    public function edit(int $id, Request $request, EntityManagerInterface $em): Response
    {
        $item = $em->getRepository(ResearchContent::class)->find($id);
        if (!$item instanceof ResearchContent) {
            $this->addFlash('error', 'Research content not found.');

            return $this->redirectToRoute('research_index');
        }

        if (!$this->isGranted('ROLE_SUPER_ADMIN') && $item->getAuthor() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('research_edit_' . $item->getId(), (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            $item
                ->setTitle((string) $request->request->get('title'))
                ->setType((string) $request->request->get('type', 'Article'));

            // Handle different types
            if ($item->getType() === 'Research') {
                $item->setRepositoryType((string) $request->request->get('repositoryType'))
                    ->setAuthors((string) $request->request->get('authors'))
                    ->setAbstract((string) $request->request->get('abstract'));
            } else {
                $item->setCategory((string) $request->request->get('category', 'General'))
                    ->setTags($request->request->get('tags'))
                    ->setSummary($request->request->get('summary'))
                    ->setBody($request->request->get('body'))
                    ->setEmbeddedLink($request->request->get('embeddedLink'))
                    ->setExternalLink($request->request->get('externalLink'))
                    ->setVisibility((string) $request->request->get('visibility', 'Public'));
            }

            $uploadedFile = $request->files->get('file');
            if ($uploadedFile instanceof UploadedFile) {
                $filePath = $this->storeResearchFile($uploadedFile, $item->getType());
                if ($filePath === null) {
                    return $this->render('research/edit.html.twig', [
                        'item' => $item,
                        'researchMediaSrc' => $item->getType() === 'Article'
                            ? $this->resolvePublicImageUrl($item->getFilePath())
                            : $this->resolvePublicAssetUrl($item->getFilePath()),
                    ]);
                }

                $item->setFilePath($filePath);
            }

            $em->flush();

            return $this->redirectToRoute('research_index');
        }

        return $this->render('research/edit.html.twig', [
            'item' => $item,
            'researchMediaSrc' => $item->getType() === 'Article'
                ? $this->resolvePublicImageUrl($item->getFilePath())
                : $this->resolvePublicAssetUrl($item->getFilePath()),
        ]);
    }

    #[Route('/{id}/delete', name: 'research_delete', methods: ['POST'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    public function delete(int $id, Request $request, EntityManagerInterface $em): Response
    {
        $item = $em->getRepository(ResearchContent::class)->find($id);
        if (!$item instanceof ResearchContent) {
            $this->addFlash('error', 'Research content not found.');

            return $this->redirectToRoute('research_index');
        }

        if (!$this->isCsrfTokenValid('research_delete_' . $item->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $em->remove($item);
        $em->flush();

        $this->addFlash('success', 'Research content deleted.');

        return $this->redirectToRoute('research_index');
    }

    #[Route('/{id}', name: 'research_show', methods: ['GET'])]
    public function show(int $id, EntityManagerInterface $em): Response
    {
        $item = $em->getRepository(ResearchContent::class)->find($id);
        if (!$item instanceof ResearchContent) {
            $this->addFlash('error', 'Research content not found.');

            return $this->redirectToRoute('research_index');
        }

        if (!$this->isGranted('ROLE_ADMIN') && $item->getVisibility() !== 'Public' && $item->getAuthor() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        return $this->render('research/show.html.twig', [
            'item' => $item,
            'researchFileAvailable' => $this->isResearchFileAvailable($item->getFilePath()),
            'researchMediaSrc' => $item->getType() === 'Article'
                ? $this->resolvePublicImageUrl($item->getFilePath())
            : $this->resolvePublicAssetUrl($item->getFilePath()),
        ]);
    }

    private function paginateResearchSection(
        EntityManagerInterface $em,
        string $sectionType,
        string $query,
        string $type,
        int $page,
        int $limit,
    ): array {
        $qb = $this->buildResearchListQueryBuilder($em, $query, $type)
            ->andWhere('r.type = :sectionType')
            ->setParameter('sectionType', $sectionType)
            ->orderBy('r.createdAt', 'DESC');

        $countQb = clone $qb;
        $countQb->resetDQLPart('orderBy');
        $countQb->select('COUNT(r.id)');
        $total = (int) $countQb->getQuery()->getSingleScalarResult();
        $pages = (int) max(1, ceil($total / $limit));
        $currentPage = min(max(1, $page), $pages);

        $items = $qb
            ->setFirstResult(($currentPage - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        $imageSrcById = [];
        $fileAvailabilityById = [];
        foreach ($items as $item) {
            if (!$item instanceof ResearchContent) {
                continue;
            }

            $imageSrcById[$item->getId()] = $this->resolvePublicImageUrl($item->getFilePath());
            $fileAvailabilityById[$item->getId()] = $this->isResearchFileAvailable($item->getFilePath());
        }

        return [
            'items' => $items,
            'pagination' => [
                'page' => $currentPage,
                'limit' => $limit,
                'total' => $total,
                'pages' => $pages,
            ],
            'imageSrcById' => $imageSrcById,
            'fileAvailabilityById' => $fileAvailabilityById,
        ];
    }

    private function buildResearchListQueryBuilder(
        EntityManagerInterface $em,
        string $query,
        string $type,
    ): \Doctrine\ORM\QueryBuilder {
        $qb = $em->createQueryBuilder()
            ->select('r')
            ->from(ResearchContent::class, 'r')
            ->leftJoin('r.author', 'u')
            ->addSelect('u');

        if (!$this->isGranted('ROLE_ADMIN')) {
            $qb->andWhere('(r.visibility = :public OR r.author = :author)')
                ->setParameter('public', 'Public')
                ->setParameter('author', $this->getUser());
        }

        if ($query !== '') {
            $searchTerms = $this->normalizeResearchSearchTerms($query);
            $dateRanges = $this->extractResearchSearchDateRanges($query);
            if ($dateRanges !== []) {
                $searchTerms = array_values(array_filter(
                    $searchTerms,
                    fn (string $term): bool => !$this->isDateSearchToken($term)
                ));
            }

            foreach ($searchTerms as $index => $term) {
                $parameter = 'queryTerm' . $index;
                $termConditions = [sprintf(
                    'LOWER(r.title) LIKE :%1$s
                    OR LOWER(r.category) LIKE :%1$s
                    OR LOWER(COALESCE(r.tags, \'\')) LIKE :%1$s
                    OR LOWER(COALESCE(r.summary, \'\')) LIKE :%1$s
                    OR LOWER(COALESCE(r.body, \'\')) LIKE :%1$s
                    OR LOWER(COALESCE(r.authors, \'\')) LIKE :%1$s
                    OR LOWER(COALESCE(r.abstract, \'\')) LIKE :%1$s
                    OR LOWER(COALESCE(u.email, \'\')) LIKE :%1$s
                    OR LOWER(COALESCE(u.firstName, \'\')) LIKE :%1$s
                    OR LOWER(COALESCE(u.middleName, \'\')) LIKE :%1$s
                    OR LOWER(COALESCE(u.lastName, \'\')) LIKE :%1$s',
                    $parameter
                )];

                foreach ($this->extractResearchSearchDateRanges($term) as $dateIndex => $range) {
                    $startParameter = 'dateStart' . $index . '_' . $dateIndex;
                    $endParameter = 'dateEnd' . $index . '_' . $dateIndex;
                    $termConditions[] = sprintf('(r.createdAt >= :%s AND r.createdAt < :%s)', $startParameter, $endParameter);
                    $qb
                        ->setParameter($startParameter, $range['start'])
                        ->setParameter($endParameter, $range['end']);
                }

                $qb
                    ->andWhere('(' . implode(' OR ', $termConditions) . ')')
                    ->setParameter($parameter, '%' . $term . '%');
            }

            if ($dateRanges !== []) {
                $dateConditions = [];
                foreach ($dateRanges as $index => $range) {
                    $startParameter = 'dateStart' . $index;
                    $endParameter = 'dateEnd' . $index;
                    $dateConditions[] = sprintf('(r.createdAt >= :%s AND r.createdAt < :%s)', $startParameter, $endParameter);
                    $qb
                        ->setParameter($startParameter, $range['start'])
                        ->setParameter($endParameter, $range['end']);
                }

                if ($dateConditions !== []) {
                    $qb->andWhere('(' . implode(' OR ', $dateConditions) . ')');
                }
            }
        }

        if ($type !== '') {
            $qb->andWhere('r.type = :type')
                ->setParameter('type', $type);
        }

        return $qb;
    }

    /**
     * @return list<string>
     */
    private function normalizeResearchSearchTerms(string $query): array
    {
        $terms = preg_split('/\s+/', mb_strtolower(trim($query))) ?: [];
        $terms = array_map(static fn (string $term): string => trim($term, " \t\n\r\0\x0B,.;:()[]{}\"'"), $terms);

        return array_values(array_unique(array_filter($terms, static fn (string $term): bool => $term !== '')));
    }

    private function isDateSearchToken(string $term): bool
    {
        return preg_match('/^\d{4}(?:[-\/]\d{1,2}(?:[-\/]\d{1,2})?)?$/', $term) === 1
            || preg_match('/^\d{1,2}(?:st|nd|rd|th)?$/', $term) === 1
            || preg_match('/^(jan(?:uary)?|feb(?:ruary)?|mar(?:ch)?|apr(?:il)?|may|jun(?:e)?|jul(?:y)?|aug(?:ust)?|sep(?:tember)?|oct(?:ober)?|nov(?:ember)?|dec(?:ember)?)$/', $term) === 1;
    }

    /**
     * @return list<array{start:\DateTimeImmutable,end:\DateTimeImmutable}>
     */
    private function extractResearchSearchDateRanges(string $query): array
    {
        $normalized = mb_strtolower(trim($query));
        if ($normalized === '') {
            return [];
        }

        $ranges = [];

        if (preg_match('/^\d{4}$/', $normalized) === 1) {
            $start = new \DateTimeImmutable($normalized . '-01-01 00:00:00');
            $ranges[] = ['start' => $start, 'end' => $start->modify('+1 year')];
        }

        if (preg_match('/^(\d{4})[-\/](\d{1,2})$/', $normalized, $matches) === 1) {
            $start = \DateTimeImmutable::createFromFormat('!Y-n', $matches[1] . '-' . $matches[2]);
            if ($start instanceof \DateTimeImmutable) {
                $ranges[] = ['start' => $start, 'end' => $start->modify('+1 month')];
            }
        }

        if (preg_match('/^(\d{4})[-\/](\d{1,2})[-\/](\d{1,2})$/', $normalized, $matches) === 1) {
            $start = \DateTimeImmutable::createFromFormat('!Y-n-j', $matches[1] . '-' . $matches[2] . '-' . $matches[3]);
            if ($start instanceof \DateTimeImmutable) {
                $ranges[] = ['start' => $start, 'end' => $start->modify('+1 day')];
            }
        }

        if (preg_match('/\b(jan(?:uary)?|feb(?:ruary)?|mar(?:ch)?|apr(?:il)?|may|jun(?:e)?|jul(?:y)?|aug(?:ust)?|sep(?:tember)?|oct(?:ober)?|nov(?:ember)?|dec(?:ember)?)\b.*\b(\d{4})\b/', $normalized, $matches) === 1) {
            $start = new \DateTimeImmutable($matches[1] . ' 1 ' . $matches[2]);
            $ranges[] = ['start' => $start, 'end' => $start->modify('+1 month')];
        }

        if (preg_match('/\b(jan(?:uary)?|feb(?:ruary)?|mar(?:ch)?|apr(?:il)?|may|jun(?:e)?|jul(?:y)?|aug(?:ust)?|sep(?:tember)?|oct(?:ober)?|nov(?:ember)?|dec(?:ember)?)\s+(\d{1,2})(?:st|nd|rd|th)?(?:,)?\s+(\d{4})\b/', $normalized, $matches) === 1) {
            $start = new \DateTimeImmutable($matches[1] . ' ' . $matches[2] . ' ' . $matches[3]);
            $ranges[] = ['start' => $start, 'end' => $start->modify('+1 day')];
        }

        $unique = [];
        foreach ($ranges as $range) {
            $key = $range['start']->format('Y-m-d') . ':' . $range['end']->format('Y-m-d');
            $unique[$key] = $range;
        }

        return array_values($unique);
    }

    private function isResearchFileAvailable(?string $filePath): bool
    {
        return $this->resolvePublicAssetUrl($filePath) !== null;
    }

    private function resolvePublicAssetUrl(?string $filePath): ?string
    {
        if ($filePath === null || trim($filePath) === '') {
            return null;
        }

        if (preg_match('#^https?://#i', $filePath) === 1 || str_starts_with($filePath, 'data:')) {
            return $filePath;
        }

        $projectDir = (string) $this->getParameter('kernel.project_dir');
        $path = parse_url($filePath, PHP_URL_PATH);
        $candidatePaths = [];

        if (is_string($path) && $path !== '') {
            $normalizedPath = ltrim(rawurldecode($path), '/');
            $candidatePaths[] = $normalizedPath;
            if (str_starts_with($normalizedPath, 'public/')) {
                $candidatePaths[] = substr($normalizedPath, 7);
            }
            $candidatePaths[] = basename($normalizedPath);
            $candidatePaths[] = 'uploads/' . basename($normalizedPath);
            $candidatePaths[] = 'uploads/research/' . basename($normalizedPath);
        } else {
            $normalizedPath = ltrim(rawurldecode($filePath), '/');
            $candidatePaths[] = $normalizedPath;
            $candidatePaths[] = basename($normalizedPath);
            $candidatePaths[] = 'uploads/' . basename($normalizedPath);
            $candidatePaths[] = 'uploads/research/' . basename($normalizedPath);
        }

        $candidatePaths = array_values(array_unique(array_filter($candidatePaths, static fn ($value) => is_string($value) && $value !== '')));

        foreach ($candidatePaths as $relativePath) {
            $publicRelativePath = str_starts_with($relativePath, 'public/')
                ? substr($relativePath, 7)
                : $relativePath;

            $fullPath = $projectDir . '/public/' . ltrim($publicRelativePath, '/');
            if (is_file($fullPath) && is_readable($fullPath)) {
                return '/' . ltrim($publicRelativePath, '/');
            }
        }

        return null;
    }

    private function resolvePublicImageUrl(?string $filePath): ?string
    {
        $assetUrl = $this->resolvePublicAssetUrl($filePath);
        if ($assetUrl === null) {
            return null;
        }

        $path = parse_url($assetUrl, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return null;
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (!in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'], true)) {
            return null;
        }

        return $assetUrl;
    }

    private function storeResearchFile(UploadedFile $uploadedFile, string $type): ?string
    {
        if ($type === 'Research' && strtolower($uploadedFile->getClientOriginalExtension()) !== 'pdf') {
            $this->addFlash('error', 'Only PDF files are allowed for Research content.');

            return null;
        }

        $result = $this->storageService->uploadFile($uploadedFile, 'research');
        if (($result['success'] ?? false) && !empty($result['url'])) {
            return (string) $result['url'];
        }

        if (!$this->storageService->isConfigured() || $this->getParameter('kernel.environment') === 'dev') {
            $uploadDir = $this->getParameter('kernel.project_dir') . '/public/uploads/research';
            if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
                $this->addFlash('error', 'Unable to create the research upload folder.');

                return null;
            }

            $originalName = pathinfo($uploadedFile->getClientOriginalName(), PATHINFO_FILENAME);
            $safeBaseName = preg_replace('/[^A-Za-z0-9._-]+/', '-', $originalName) ?: 'research-file';
            $extension = strtolower($uploadedFile->guessExtension() ?: $uploadedFile->getClientOriginalExtension() ?: 'bin');
            $filename = $safeBaseName . '-' . uniqid() . '.' . $extension;

            try {
                $uploadedFile->move($uploadDir, $filename);
            } catch (\Throwable $e) {
                $this->addFlash('error', 'Research file upload failed locally: ' . $e->getMessage());

                return null;
            }

            $this->addFlash('success', 'Supabase storage is not configured locally, so the file was saved to the local uploads folder for development.');

            return '/uploads/research/' . $filename;
        }

        $this->addFlash('error', (string) ($result['error'] ?? 'Research file upload failed.'));

        return null;
    }
}
