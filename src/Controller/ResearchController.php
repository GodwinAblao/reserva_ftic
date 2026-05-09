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
use Symfony\Component\String\Slugger\SluggerInterface;

#[Route('/research')]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
class ResearchController extends AbstractController
{
    #[Route('', name: 'research_index', methods: ['GET'])]
    public function index(Request $request, EntityManagerInterface $em): Response
    {
        $query = trim((string) $request->query->get('q', ''));
        $type = trim((string) $request->query->get('type', ''));
        $category = trim((string) $request->query->get('category', ''));

        $qb = $em->createQueryBuilder()
            ->select('r')
            ->from(ResearchContent::class, 'r')
            ->leftJoin('r.author', 'u')
            ->addSelect('u')
            ->orderBy('r.createdAt', 'DESC');

        if (!$this->isGranted('ROLE_SUPER_ADMIN')) {
            $qb->andWhere('r.visibility = :public OR r.author = :author')
                ->setParameter('public', 'Public')
                ->setParameter('author', $this->getUser());
        }

        if ($query !== '') {
            $qb->andWhere('r.title LIKE :query OR r.summary LIKE :query OR r.abstract LIKE :query OR r.body LIKE :query OR r.tags LIKE :query')
                ->setParameter('query', '%' . $query . '%');
        }

        if ($category !== '') {
            $qb->andWhere('r.category = :category')
                ->setParameter('category', $category);
        }

        if ($type !== '') {
            $qb->andWhere('r.type = :type')
                ->setParameter('type', $type);
        }

        $categoriesQb = $em->createQueryBuilder()
            ->select('DISTINCT r.category')
            ->from(ResearchContent::class, 'r');

        if (!$this->isGranted('ROLE_SUPER_ADMIN')) {
            $categoriesQb->andWhere('r.visibility = :public OR r.author = :author')
                ->setParameter('public', 'Public')
                ->setParameter('author', $this->getUser());
        }

        $categories = $categoriesQb
            ->orderBy('r.category', 'ASC')
            ->getQuery()
            ->getSingleColumnResult();

        return $this->render('research/index.html.twig', [
            'items' => $qb->getQuery()->getResult(),
            'query' => $query,
            'category' => $category,
            'type' => $type,
            'categories' => $categories,
        ]);
    }

    #[Route('/new/{type}', name: 'research_new', methods: ['GET', 'POST'], defaults: ['type' => 'Article'], requirements: ['type' => 'Article|Research|News'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    public function new(Request $request, EntityManagerInterface $em, SluggerInterface $slugger, string $type = 'Article'): Response
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
            if ($uploadedFile instanceof UploadedFile) {
                // For Research type, only allow PDF
                if ($type === 'Research' && $uploadedFile->getClientOriginalExtension() !== 'pdf') {
                    $this->addFlash('error', 'Only PDF files are allowed for Research content.');
                    return $this->render('research/new.html.twig', ['type' => $type]);
                }
                
                $name = pathinfo($uploadedFile->getClientOriginalName(), PATHINFO_FILENAME);
                $ext = $type === 'Research' ? 'pdf' : $uploadedFile->guessExtension();
                $filename = $slugger->slug($name) . '-' . uniqid() . '.' . $ext;
                $uploadedFile->move($this->getParameter('kernel.project_dir') . '/public/uploads/research', $filename);
                $item->setFilePath('/uploads/research/' . $filename);
            }

            $em->persist($item);
            $em->flush();

            $this->addFlash('success', 'Research content published.');

            return $this->redirectToRoute('research_index');
        }

        return $this->render('research/new.html.twig', ['type' => $type]);
    }

    #[Route('/{id}/edit', name: 'research_edit', methods: ['GET', 'POST'])]
    public function edit(ResearchContent $item, Request $request, EntityManagerInterface $em): Response
    {
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

            $em->flush();

            return $this->redirectToRoute('research_index');
        }

return $this->render('research/edit.html.twig', ['item' => $item]);
    }

    #[Route('/{id}/delete', name: 'research_delete', methods: ['POST'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    public function delete(ResearchContent $item, Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('research_delete_' . $item->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $em->remove($item);
        $em->flush();

        $this->addFlash('success', 'Research content deleted.');

        return $this->redirectToRoute('research_index');
    }

    #[Route('/{id}', name: 'research_show', methods: ['GET'])]
    public function show(ResearchContent $item): Response
    {
        if (!$this->isGranted('ROLE_SUPER_ADMIN') && $item->getVisibility() !== 'Public' && $item->getAuthor() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        return $this->render('research/show.html.twig', ['item' => $item]);
    }
}
