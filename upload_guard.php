<?php
declare(strict_types=1);

require_once __DIR__ . '/security_bootstrap.php';

if (!function_exists('bv_handle_secure_upload')) {
    function bv_handle_secure_upload(
        array $file,
        string $targetDir,
        array $allowedMime = ['image/jpeg', 'image/png', 'image/webp'],
        int $maxBytes = 5242880
    ): array {
        if (empty($file) || !isset($file['error'])) {
            throw new RuntimeException('No upload payload');
        }

        if ((int)$file['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Upload failed with error code: ' . (int)$file['error']);
        }

        if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            throw new RuntimeException('Invalid uploaded file');
        }

        if ((int)$file['size'] <= 0) {
            throw new RuntimeException('Empty file');
        }

        if ((int)$file['size'] > $maxBytes) {
            throw new RuntimeException('File too large');
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo ? (string)finfo_file($finfo, $file['tmp_name']) : '';
        if ($finfo) {
            finfo_close($finfo);
        }

        if (!in_array($mime, $allowedMime, true)) {
            throw new RuntimeException('Disallowed file type: ' . $mime);
        }

        $extMap = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
        ];
        $ext = $extMap[$mime] ?? 'bin';

        if (!is_dir($targetDir)) {
            if (!mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
                throw new RuntimeException('Cannot create upload directory');
            }
        }

        $newName = date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $dest = rtrim($targetDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $newName;

        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            throw new RuntimeException('Cannot move uploaded file');
        }

        @chmod($dest, 0644);

        return [
            'filename' => $newName,
            'mime'     => $mime,
            'size'     => (int)$file['size'],
            'path'     => $dest,
        ];
    }
}