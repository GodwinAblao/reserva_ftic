<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Message;
use App\Entity\User;
use App\Repository\MessageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use App\Service\ReservationMailer;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/inbox')]
class InboxController extends AbstractController
{
    #[Route('', name: 'inbox_index', methods: ['GET'])]
    public function index(MessageRepository $messageRepo): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->render('inbox/index.html.twig', [
            'messages' => $messageRepo->findInbox($user),
            'unreadCount' => $messageRepo->countUnread($user),
            'activeTab' => 'inbox',
        ]);
    }

    #[Route('/sent', name: 'inbox_sent', methods: ['GET'])]
    public function sent(MessageRepository $messageRepo): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->render('inbox/index.html.twig', [
            'messages' => $messageRepo->findSent($user),
            'unreadCount' => $messageRepo->countUnread($user),
            'activeTab' => 'sent',
        ]);
    }

    #[Route('/compose', name: 'inbox_compose', methods: ['GET', 'POST'])]
    public function compose(
        Request $request,
        EntityManagerInterface $em,
        MessageRepository $messageRepo,
        ReservationMailer $reservationMailer,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('inbox_compose', (string) $request->request->get('_token'))) {
                $this->addFlash('inbox_error', 'Invalid security token.');
                return $this->redirectToRoute('inbox_compose');
            }

            $recipientEmail = trim((string) $request->request->get('recipient_email', ''));
            $subject = trim((string) $request->request->get('subject', ''));
            $body = trim((string) $request->request->get('body', ''));

            if (!$recipientEmail || !$subject || !$body) {
                $this->addFlash('inbox_error', 'Please fill in all required fields.');
                return $this->redirectToRoute('inbox_compose');
            }

            $recipient = $em->getRepository(User::class)->findOneBy(['email' => $recipientEmail]);
            if (!$recipient) {
                $this->addFlash('inbox_error', 'Recipient not found.');
                return $this->redirectToRoute('inbox_compose');
            }

            if ($recipient->getId() === $user->getId()) {
                $this->addFlash('inbox_error', 'You cannot send a message to yourself.');
                return $this->redirectToRoute('inbox_compose');
            }

            $message = (new Message())
                ->setSender($user)
                ->setRecipient($recipient)
                ->setSubject($subject)
                ->setBody($body);

            $em->persist($message);
            $em->flush();

            $message->setThreadId($message->getId());
            $em->flush();

            $reservationMailer->notifyInboxMessage($message);

            $this->addFlash('inbox_success', 'Message sent successfully.');
            return $this->redirectToRoute('inbox_sent');
        }

        return $this->render('inbox/compose.html.twig', [
            'unreadCount' => $messageRepo->countUnread($user),
            'prefillRecipient' => $request->query->get('to', ''),
            'prefillSubject' => $request->query->get('subject', ''),
        ]);
    }

    #[Route('/view/{id}', name: 'inbox_view', methods: ['GET'])]
    public function view(
        Message $message,
        EntityManagerInterface $em,
        MessageRepository $messageRepo,
        ReservationMailer $reservationMailer,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

        if ($message->getSender()?->getId() !== $user->getId() && $message->getRecipient()?->getId() !== $user->getId()) {
            throw $this->createAccessDeniedException();
        }

        if ($message->getRecipient()?->getId() === $user->getId() && !$message->isReadByRecipient()) {
            $message->setIsReadByRecipient(true);
            $em->flush();
        }

        $thread = $message->getThreadId()
            ? $messageRepo->findThread($message->getThreadId(), $user)
            : [$message];

        return $this->render('inbox/view.html.twig', [
            'message' => $message,
            'thread' => $thread,
            'unreadCount' => $messageRepo->countUnread($user),
        ]);
    }

    #[Route('/reply/{id}', name: 'inbox_reply', methods: ['POST'])]
    public function reply(
        Message $message,
        Request $request,
        EntityManagerInterface $em,
        MessageRepository $messageRepo,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

        if ($message->getSender()?->getId() !== $user->getId() && $message->getRecipient()?->getId() !== $user->getId()) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->isCsrfTokenValid('inbox_reply_' . $message->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('inbox_error', 'Invalid security token.');
            return $this->redirectToRoute('inbox_view', ['id' => $message->getId()]);
        }

        $body = trim((string) $request->request->get('body', ''));
        if (!$body) {
            $this->addFlash('inbox_error', 'Reply body cannot be empty.');
            return $this->redirectToRoute('inbox_view', ['id' => $message->getId()]);
        }

        $recipient = $message->getSender()?->getId() === $user->getId()
            ? $message->getRecipient()
            : $message->getSender();

        $reply = (new Message())
            ->setSender($user)
            ->setRecipient($recipient)
            ->setSubject('Re: ' . $message->getSubject())
            ->setBody($body)
            ->setParentMessage($message)
            ->setThreadId($message->getThreadId() ?? $message->getId());

        $em->persist($reply);
        $em->flush();

        $reservationMailer->notifyInboxMessage($reply);

        $this->addFlash('inbox_success', 'Reply sent.');
        return $this->redirectToRoute('inbox_view', ['id' => $message->getId()]);
    }

    #[Route('/delete/{id}', name: 'inbox_delete', methods: ['POST'])]
    public function delete(
        Message $message,
        Request $request,
        EntityManagerInterface $em,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

        if ($message->getSender()?->getId() !== $user->getId() && $message->getRecipient()?->getId() !== $user->getId()) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->isCsrfTokenValid('inbox_delete_' . $message->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('inbox_error', 'Invalid security token.');
            return $this->redirectToRoute('inbox_index');
        }

        if ($message->getSender()?->getId() === $user->getId()) {
            $message->setIsDeletedBySender(true);
        }
        if ($message->getRecipient()?->getId() === $user->getId()) {
            $message->setIsDeletedByRecipient(true);
        }

        $em->flush();

        $this->addFlash('inbox_success', 'Message deleted.');
        return $this->redirectToRoute('inbox_index');
    }

    #[Route('/unread-count', name: 'inbox_unread_count', methods: ['GET'])]
    public function unreadCount(MessageRepository $messageRepo): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->json(['count' => $messageRepo->countUnread($user)]);
    }
}
