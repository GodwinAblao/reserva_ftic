<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class UploadController extends AbstractController
{
    #[Route('/uploads/profiles/{filename}', name: 'uploads_profiles', methods: ['GET'], requirements: ['filename' => '.+'])]
    public function serveProfile(string $filename): Response
    {
        $uploadDir = $this->getParameter('kernel.project_dir') . '/public/uploads/profiles';
        $filePath = $uploadDir . '/' . $filename;

        // Handle missing files gracefully for images
        if (!file_exists($filePath) || !is_readable($filePath)) {
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                // Return a 1x1 transparent pixel for missing images to prevent broken image icons
                $transparentPixel = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
                return new Response($transparentPixel, 200, [
                    'Content-Type' => 'image/png',
                    'X-Missing-File' => $filename,
                ]);
            }
            return new Response('File not found.', 404, [
                'Content-Type' => 'text/plain; charset=UTF-8',
                'X-Missing-File' => $filename,
            ]);
        }

        // Security: prevent directory traversal (only after confirming file exists)
        $realUploadDir = realpath($uploadDir);
        $realFilePath = realpath($filePath);

        if ($realFilePath === false || $realUploadDir === false || !str_starts_with($realFilePath, $realUploadDir)) {
            return new Response('File not found.', 404, [
                'Content-Type' => 'text/plain; charset=UTF-8',
                'X-Missing-File' => $filename,
            ]);
        }

        return new BinaryFileResponse($realFilePath);
    }

    #[Route('/uploads/{path}', name: 'uploads_serve', methods: ['GET'], requirements: ['path' => '.+'])]
    public function serveUpload(string $path): Response
    {
        $uploadDir = $this->getParameter('kernel.project_dir') . '/public/uploads';
        $filePath = $uploadDir . '/' . $path;

        // Security: normalize path and check for directory traversal before file_exists
        $normalizedPath = realpath(dirname($filePath)) . '/' . basename($filePath);
        $realUploadDir = realpath($uploadDir);

        if ($realUploadDir === false || !str_starts_with(realpath(dirname($filePath)), $realUploadDir)) {
            throw new NotFoundHttpException('File not found.');
        }

        if (!file_exists($filePath) || !is_readable($filePath)) {
            return new Response('File not found.', 404, [
                'Content-Type' => 'text/plain; charset=UTF-8',
                'X-Missing-File' => $path,
            ]);
        }

        return new BinaryFileResponse($filePath);
    }
}
