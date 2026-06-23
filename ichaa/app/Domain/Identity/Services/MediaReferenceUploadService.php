<?php

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Models\MediaReference;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MediaReferenceUploadService
{
    private const MAX_FILE_SIZE_BYTES = 52_428_800;
    private const MANAGED_DIRECTORY = 'media-library';

    public function payloadFromUploadedFile(UploadedFile $file): array
    {
        $relativePath = $file->store(self::MANAGED_DIRECTORY, 'public');
        $absolutePath = Storage::disk('public')->path($relativePath);
        $mimeType = $file->getMimeType() ?: $file->getClientMimeType() ?: 'application/octet-stream';
        [$width, $height] = $this->imageDimensions($absolutePath, $mimeType);

        return [
            'file_path' => $absolutePath,
            'url' => null,
            'file_name' => $file->getClientOriginalName(),
            'file_extension' => $file->extension(),
            'file_size_bytes' => $file->getSize(),
            'mime_type' => $mimeType,
            'width_px' => $width,
            'height_px' => $height,
        ];
    }

    public function payloadFromBase64(
        string $originalName,
        string $contentBase64,
        ?string $mimeType = null,
    ): array {
        $binary = $this->decodeBase64($contentBase64);
        $size = strlen($binary);

        if ($size > self::MAX_FILE_SIZE_BYTES) {
            throw ValidationException::withMessages([
                'data.file.content_base64' => 'Uploaded media must be 50 MB or smaller.',
            ]);
        }

        $extension = pathinfo($originalName, PATHINFO_EXTENSION);
        $storedName = (string) Str::uuid();
        if ($extension !== '') {
            $storedName .= '.'.$extension;
        }

        $relativePath = self::MANAGED_DIRECTORY.'/'.$storedName;
        Storage::disk('public')->put($relativePath, $binary);

        $absolutePath = Storage::disk('public')->path($relativePath);
        $detectedMimeType = $mimeType ?: mime_content_type($absolutePath) ?: 'application/octet-stream';
        [$width, $height] = $this->imageDimensions($absolutePath, $detectedMimeType);

        return [
            'file_path' => $absolutePath,
            'url' => null,
            'file_name' => $originalName,
            'file_extension' => $extension !== '' ? $extension : null,
            'file_size_bytes' => $size,
            'mime_type' => $detectedMimeType,
            'width_px' => $width,
            'height_px' => $height,
        ];
    }

    public function existingManagedUploadPayload(MediaReference $media): array
    {
        return [
            'file_path' => $media->file_path,
            'url' => null,
            'file_name' => $media->file_name,
            'file_extension' => $media->file_extension,
            'file_size_bytes' => $media->file_size_bytes,
            'mime_type' => $media->mime_type,
            'width_px' => $media->width_px,
            'height_px' => $media->height_px,
        ];
    }

    public function deleteManagedUpload(MediaReference $media, ?string $previousPath = null): void
    {
        $path = $previousPath ?? $media->file_path;

        if (! is_string($path) || $path === '') {
            return;
        }

        $root = Storage::disk('public')->path(self::MANAGED_DIRECTORY);
        $normalizedPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        $normalizedRoot = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $root);

        if (! str_starts_with($normalizedPath, $normalizedRoot)) {
            return;
        }

        $relativePath = ltrim(substr($normalizedPath, strlen($normalizedRoot)), DIRECTORY_SEPARATOR);

        if ($relativePath === '') {
            return;
        }

        Storage::disk('public')->delete(self::MANAGED_DIRECTORY.'/'.str_replace(DIRECTORY_SEPARATOR, '/', $relativePath));
    }

    private function decodeBase64(string $contentBase64): string
    {
        $payload = trim($contentBase64);

        if (preg_match('/^data:[^;]+;base64,(.*)$/s', $payload, $matches) === 1) {
            $payload = $matches[1];
        }

        $payload = preg_replace('/\s+/', '', $payload) ?? '';
        $decoded = base64_decode($payload, true);

        if ($decoded === false || $decoded === '') {
            throw ValidationException::withMessages([
                'data.file.content_base64' => 'Provide a valid base64-encoded media payload.',
            ]);
        }

        return $decoded;
    }

    private function imageDimensions(string $absolutePath, ?string $mimeType): array
    {
        if (! $mimeType || ! str_starts_with($mimeType, 'image/')) {
            return [null, null];
        }

        $dimensions = @getimagesize($absolutePath);

        if (! is_array($dimensions)) {
            return [null, null];
        }

        return [
            isset($dimensions[0]) ? (int) $dimensions[0] : null,
            isset($dimensions[1]) ? (int) $dimensions[1] : null,
        ];
    }
}
