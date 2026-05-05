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
            $qb->andWhere('r.title LIKE :query OR r.summary LIKE :query OR r.tags LIKE :query')
                ->setParameter('query', '%' . $query . '%');
        }

        if ($category !== '') {
            $qb->andWhere('r.category = :category')
                ->setParameter('category', $category);
        }

        $categories = $em->createQueryBuilder()
            ->select('DISTINCT r.category')
            ->from(ResearchContent::class, 'r')
            ->orderBy('r.category', 'ASC')
            ->getQuery()
            ->getSingleColumnResult();

        return $this->render('research/index.html.twig', [
            'items' => $qb->getQuery()->getResult(),
            'query' => $query,
            'category' => $category,
            'categories' => $categories,
        ]);
    }

    #[Route('/new', name: 'research_new', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    public function new(Request $request, EntityManagerInterface $em, SluggerInterface $slugger): Response
    {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('research_new', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            $item = (new ResearchContent())
                ->setAuthor($this->getUser())
                ->setTitle((string) $request->request->get('title'))
                ->setType((string) $request->request->get('type', 'Article'))
                ->setCategory((string) $request->request->get('category', 'General'))
                ->setTags($request->request->get('tags'))
                ->setSummary($request->request->get('summary'))
                ->setBody($request->request->get('body'))
                ->setVisibility((string) $request->request->get('visibility', 'Public'));

            $uploadedFile = $request->files->get('file');
            if ($uploadedFile instanceof UploadedFile) {
                $name = pathinfo($uploadedFile->getClientOriginalName(), PATHINFO_FILENAME);
                $filename = $slugger->slug($name) . '-' . uniqid() . '.' . $uploadedFile->guessExtension();
                $uploadedFile->move($this->getParameter('kernel.project_dir') . '/public/uploads/research', $filename);
                $item->setFilePath('/uploads/research/' . $filename);
            }

            $em->persist($item);
            $em->flush();

            $this->addFlash('success', 'Research content published.');

            return $this->redirectToRoute('research_index');
        }

        return $this->render('research/new.html.twig');
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
                ->setType((string) $request->request->get('type', 'Article'))
                ->setCategory((string) $request->request->get('category', 'General'))
                ->setTags($request->request->get('tags'))
                ->setSummary($request->request->get('summary'))
                ->setBody($request->request->get('body'))
                ->setVisibility((string) $request->request->get('visibility', 'Public'));

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
}
