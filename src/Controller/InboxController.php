<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Message;
use App\Entity\MessageAttachment;
use App\Entity\User;
use App\Repository\MessageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
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

            $uploadedFiles = $this->getUploadedAttachmentFiles($request);
            $attachmentError = $this->validateUploadedAttachments($uploadedFiles);
            if ($attachmentError !== null) {
                $this->addFlash('inbox_error', $attachmentError);
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

            try {
                $this->storeUploadedAttachments($message, $uploadedFiles);
            } catch (\RuntimeException $e) {
                $this->addFlash('inbox_error', $e->getMessage());
                return $this->redirectToRoute('inbox_compose');
            }

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
        ReservationMailer $reservationMailer,
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

        $uploadedFiles = $this->getUploadedAttachmentFiles($request);
        $attachmentError = $this->validateUploadedAttachments($uploadedFiles);
        if ($attachmentError !== null) {
            $this->addFlash('inbox_error', $attachmentError);
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

        try {
            $this->storeUploadedAttachments($reply, $uploadedFiles);
        } catch (\RuntimeException $e) {
            $this->addFlash('inbox_error', $e->getMessage());
            return $this->redirectToRoute('inbox_view', ['id' => $message->getId()]);
        }

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

    #[Route('/attachment/{id}/download', name: 'inbox_attachment_download', methods: ['GET'])]
    public function downloadAttachment(MessageAttachment $attachment): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $message = $attachment->getMessage();

        if (
            !$message
            || ($message->getSender()?->getId() !== $user->getId() && $message->getRecipient()?->getId() !== $user->getId())
        ) {
            throw $this->createAccessDeniedException();
        }

        $storageRoot = $this->getInboxAttachmentStorageRoot();
        $filePath = $storageRoot . DIRECTORY_SEPARATOR . $attachment->getStoragePath();
        $realStorageRoot = realpath($storageRoot);
        $realFilePath = realpath($filePath);

        if ($realStorageRoot === false || $realFilePath === false || !str_starts_with($realFilePath, $realStorageRoot) || !is_readable($realFilePath)) {
            throw $this->createNotFoundException('Attachment file not found.');
        }

        $response = new BinaryFileResponse($realFilePath);
        $response->headers->set('Content-Type', $attachment->getMimeType());
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $attachment->getOriginalName());

        return $response;
    }

    #[Route('/unread-count', name: 'inbox_unread_count', methods: ['GET'])]
    public function unreadCount(MessageRepository $messageRepo): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->json(['count' => $messageRepo->countUnread($user)]);
    }

    /**
     * @return list<UploadedFile>
     */
    private function getUploadedAttachmentFiles(Request $request): array
    {
        $files = $request->files->get('attachments', []);
        if ($files instanceof UploadedFile) {
            $files = [$files];
        }
        if (!is_array($files)) {
            return [];
        }

        return array_values(array_filter($files, static fn (mixed $file): bool => $file instanceof UploadedFile && $file->getError() !== UPLOAD_ERR_NO_FILE));
    }

    /**
     * @param list<UploadedFile> $files
     */
    private function validateUploadedAttachments(array $files): ?string
    {
        if (count($files) > 3) {
            return 'You can attach up to 3 files per message.';
        }

        $allowedMimeTypes = array_keys($this->getAllowedAttachmentMimeTypes());
        foreach ($files as $file) {
            if (!$file->isValid()) {
                return 'One of the attached files could not be uploaded. Please try again.';
            }

            if (($file->getSize() ?: 0) > 10 * 1024 * 1024) {
                return sprintf('"%s" is too large. Attachments must be 10 MB or smaller.', $file->getClientOriginalName());
            }

            $mimeType = $file->getMimeType() ?: 'application/octet-stream';
            if (!in_array($mimeType, $allowedMimeTypes, true)) {
                return sprintf('"%s" is not an allowed file type. Use PDF, Word, PNG, or JPG files.', $file->getClientOriginalName());
            }
        }

        return null;
    }

    /**
     * @param list<UploadedFile> $files
     */
    private function storeUploadedAttachments(Message $message, array $files): void
    {
        if ($files === []) {
            return;
        }

        $storageRoot = $this->getInboxAttachmentStorageRoot();
        if (!is_dir($storageRoot) && !mkdir($storageRoot, 0775, true) && !is_dir($storageRoot)) {
            throw new \RuntimeException('Unable to prepare the attachment upload folder.');
        }

        foreach ($files as $file) {
            $originalName = $this->sanitizeAttachmentDisplayName($file->getClientOriginalName());
            $mimeType = $file->getMimeType() ?: 'application/octet-stream';
            $fileSize = (int) ($file->getSize() ?: 0);
            $extension = $this->getAllowedAttachmentMimeTypes()[$mimeType] ?? strtolower($file->getClientOriginalExtension() ?: 'bin');
            $storedName = bin2hex(random_bytes(16)) . '.' . $extension;

            try {
                $file->move($storageRoot, $storedName);
            } catch (\Throwable) {
                throw new \RuntimeException(sprintf('Could not save "%s". Please try again.', $file->getClientOriginalName()));
            }

            $message->addAttachment((new MessageAttachment())
                ->setOriginalName($originalName)
                ->setStoredName($storedName)
                ->setStoragePath($storedName)
                ->setMimeType($mimeType)
                ->setFileSize($fileSize));
        }
    }

    private function getInboxAttachmentStorageRoot(): string
    {
        return (string) $this->getParameter('kernel.project_dir') . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'inbox_attachments';
    }

    /**
     * @return array<string, string>
     */
    private function getAllowedAttachmentMimeTypes(): array
    {
        return [
            'application/pdf' => 'pdf',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
        ];
    }

    private function sanitizeAttachmentDisplayName(string $filename): string
    {
        $filename = trim(preg_replace('/[^\w.\- ()]+/u', '-', $filename) ?: 'attachment');

        return mb_substr($filename !== '' ? $filename : 'attachment', 0, 180);
    }
}
