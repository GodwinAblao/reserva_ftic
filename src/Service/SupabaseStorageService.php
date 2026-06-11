<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Supabase Storage Service using HTTP API
 * 
 * This service provides file upload/download/delete operations
 * using Supabase Storage REST API via cURL.
 */
class SupabaseStorageService
{
    private string $bucketName;
    private ?string $projectUrl = null;
    private ?string $projectRef = null;
    private ?string $apiKey = null;
    private bool $configured = false;

    public function __construct()
    {
        $this->apiKey = $_ENV['SUPABASE_STORAGE_KEY'] ?? null;
        $this->projectRef = $_ENV['SUPABASE_PROJECT_REF'] ?? null;
        $this->bucketName = $_ENV['SUPABASE_STORAGE_BUCKET'] ?? 'facility-images';

        if (empty($this->apiKey)) {
            error_log('ERROR: SUPABASE_STORAGE_KEY is not set');
        }
        if (empty($this->projectRef)) {
            error_log('ERROR: SUPABASE_PROJECT_REF is not set');
        }

        if (empty($this->apiKey) || empty($this->projectRef)) {
            error_log('Supabase storage is disabled because credentials are missing.');
            return;
        }

        $this->projectUrl = "https://{$this->projectRef}.supabase.co";
        $this->configured = true;
        error_log('SupabaseStorageService initialized for project: ' . $this->projectRef);
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    /**
     * Upload a file to Supabase Storage using HTTP API
     * 
     * @param UploadedFile $file The file to upload
     * @param string $folder Optional folder path (e.g., 'facilities')
     * @return array Result with 'success', 'url', 'path', and 'error' keys
     */
    public function uploadFile(UploadedFile $file, string $folder = ''): array
    {
        if (!$this->configured || !$this->projectUrl || !$this->apiKey) {
            return [
                'success' => false,
                'error' => 'Supabase storage is not configured in this environment.',
                'path' => null,
                'url' => null,
            ];
        }

        try {
            // Generate unique filename
            $ext = $file->guessExtension() ?? strtolower($file->getClientOriginalExtension()) ?: 'jpg';
            $filename = uniqid() . '.' . $ext;
            
            // Build full path
            $fullPath = $folder ? $folder . '/' . $filename : $filename;
            
            // Read file content
            $fileContent = file_get_contents($file->getPathname());
            
            // Upload via HTTP API
            $url = "{$this->projectUrl}/storage/v1/object/{$this->bucketName}/{$fullPath}";
            
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $fileContent);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: ' . $file->getMimeType(),
                'x-upsert: true'
            ]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 60);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);

            error_log('Supabase: Uploading file ' . $fullPath . ' (' . strlen($fileContent) . ' bytes)');

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            
            if ($error) {
                error_log('Supabase upload cURL error: ' . $error);
                return [
                    'success' => false,
                    'error' => 'Upload failed: ' . $error,
                    'path' => null,
                    'url' => null
                ];
            }
            
            error_log('Supabase upload HTTP code: ' . $httpCode . ', Response: ' . $response);
            
            if ($httpCode >= 200 && $httpCode < 300) {
                return [
                    'success' => true,
                    'path' => $fullPath,
                    'url' => $this->getPublicUrl($fullPath),
                    'data' => json_decode($response, true)
                ];
            } else {
                error_log('Supabase upload failed: HTTP ' . $httpCode . ' - ' . $response);
                return [
                    'success' => false,
                    'error' => 'Upload failed with HTTP ' . $httpCode . ': ' . $response,
                    'path' => null,
                    'url' => null
                ];
            }
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => 'Upload exception: ' . $e->getMessage(),
                'path' => null,
                'url' => null
            ];
        }
    }

    /**
     * Delete a file from Supabase Storage using HTTP API
     * 
     * @param string $path The file path in the bucket
     * @return bool True if deleted successfully
     */
    public function deleteFile(string $path): bool
    {
        if (!$this->configured || !$this->projectUrl || !$this->apiKey) {
            return false;
        }

        try {
            $url = "{$this->projectUrl}/storage/v1/object/{$this->bucketName}/{$path}";
            
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $this->apiKey
            ]);
            
            curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            return $httpCode >= 200 && $httpCode < 300;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get public URL for a file
     * 
     * @param string $path The file path in the bucket
     * @return string The public URL
     */
    public function getPublicUrl(string $path): string
    {
        if (!$this->projectUrl) {
            return $path;
        }

        return "{$this->projectUrl}/storage/v1/object/public/{$this->bucketName}/{$path}";
    }
}
