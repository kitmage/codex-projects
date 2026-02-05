<?php
/**
 * Plugin Name: Fluent Forms Private Uploads
 * Description: Stores Fluent Forms uploads in a private directory and serves protected admin-only download links in entry details.
 * Version: 1.0.0
 * Author: Custom
 */

if (!defined('ABSPATH')) {
    exit;
}

final class FF_Private_Uploads_Extension
{
    private const OPTION_PRIVATE_DIR = 'ff_private_uploads_private_dir';
    private const DEFAULT_PRIVATE_DIR = '/home/1264996.cloudwaysapps.com/hgfynmchnh/private_html/fluentforms-uploads';
    private const PRIVATE_SCHEME = 'ff-private://';
    private const DOWNLOAD_ACTION = 'ff_private_fluentform_download';

    public static function boot()
    {
        $instance = new self();

        add_action('plugins_loaded', [$instance, 'registerHooks']);
        register_activation_hook(__FILE__, [self::class, 'activate']);
    }

    public static function activate()
    {
        if (!get_option(self::OPTION_PRIVATE_DIR)) {
            update_option(self::OPTION_PRIVATE_DIR, self::DEFAULT_PRIVATE_DIR, false);
        }

        $privateBaseDir = self::privateBaseDir();
        if (!is_dir($privateBaseDir)) {
            wp_mkdir_p($privateBaseDir);
        }
    }

    public function registerHooks()
    {
        add_filter('fluentform/filter_insert_data', [$this, 'storeFilesPrivately'], 99, 1);
        add_filter('fluentform/response_render_input_file', [$this, 'renderProtectedFileLinks'], 20, 4);

        add_action('admin_post_' . self::DOWNLOAD_ACTION, [$this, 'servePrivateFile']);
        add_action('admin_post_nopriv_' . self::DOWNLOAD_ACTION, [$this, 'denyGuestDownload']);
        add_filter('upload_dir', [$this, 'forcePrivateUploadDirForFluentForms'], 20, 1);
    }

    public function storeFilesPrivately($insertData)
    {
        $payload = json_decode((string) ($insertData['response'] ?? ''), true);

        if (!is_array($payload)) {
            return $insertData;
        }

        $payload = $this->mapFileValues($payload, function ($value) {
            return $this->relocateToPrivateAndMark($value);
        });

        $insertData['response'] = wp_json_encode($payload, JSON_UNESCAPED_UNICODE);

        return $insertData;
    }

    public function renderProtectedFileLinks($response, $field, $formId, $isHtml = false)
    {
        if (!$response) {
            return $response;
        }

        if (!$isHtml) {
            return $response;
        }

        $files = is_array($response) ? $response : [$response];
        $items = [];

        foreach ($files as $fileRef) {
            if (!$fileRef || !is_string($fileRef)) {
                continue;
            }

            $privateRelativePath = $this->privateRelativePathFromValue($fileRef);
            if (!$privateRelativePath) {
                $privateRelativePath = $this->relocateToPrivateAndMark($fileRef);
                if (strpos((string) $privateRelativePath, self::PRIVATE_SCHEME) === 0) {
                    $privateRelativePath = substr($privateRelativePath, strlen(self::PRIVATE_SCHEME));
                }
            }

            if (!$privateRelativePath) {
                continue;
            }

            $downloadUrl = $this->buildProtectedDownloadUrl($privateRelativePath);
            $items[] = sprintf(
                '<li><a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a></li>',
                esc_url($downloadUrl),
                esc_html(basename($privateRelativePath))
            );
        }

        if (!$items) {
            return '';
        }

        return '<ul class="ff_entry_list">' . implode('', $items) . '</ul>';
    }

    public function servePrivateFile()
    {
        if (!is_user_logged_in() || !current_user_can('manage_options')) {
            wp_die(esc_html__('Unauthorized', 'ff-private-uploads'), 403);
        }

        $relative = isset($_GET['ff_file']) ? wp_unslash($_GET['ff_file']) : '';
        $relative = $this->sanitizeRelativePath($relative);

        if (!$relative) {
            wp_die(esc_html__('Invalid file request.', 'ff-private-uploads'), 400);
        }

        if (!check_admin_referer(self::DOWNLOAD_ACTION . '|' . $relative)) {
            wp_die(esc_html__('Invalid or expired link.', 'ff-private-uploads'), 403);
        }

        $fullPath = $this->absolutePrivatePath($relative);
        if (!is_file($fullPath) || !is_readable($fullPath)) {
            wp_die(esc_html__('File not found.', 'ff-private-uploads'), 404);
        }

        nocache_headers();

        $mimeType = function_exists('mime_content_type') ? mime_content_type($fullPath) : 'application/octet-stream';
        if (!$mimeType) {
            $mimeType = 'application/octet-stream';
        }

        header('Content-Description: File Transfer');
        header('Content-Type: ' . $mimeType);
        header('Content-Disposition: attachment; filename="' . basename($fullPath) . '"');
        header('Content-Length: ' . (string) filesize($fullPath));

        readfile($fullPath);
        exit;
    }

    public function denyGuestDownload()
    {
        wp_die(esc_html__('Unauthorized', 'ff-private-uploads'), 403);
    }

    public function forcePrivateUploadDirForFluentForms($uploads)
    {
        if (!$this->isFluentFormUploadRequest()) {
            return $uploads;
        }

        $privateBaseDir = self::privateBaseDir();
        if (!is_dir($privateBaseDir)) {
            wp_mkdir_p($privateBaseDir);
        }

        $subdir = isset($uploads['subdir']) ? (string) $uploads['subdir'] : '';
        $path = wp_normalize_path(trailingslashit($privateBaseDir) . ltrim($subdir, '/'));

        if (!is_dir($path)) {
            wp_mkdir_p($path);
        }

        $uploads['basedir'] = $privateBaseDir;
        $uploads['path'] = $path;

        return $uploads;
    }


    private function isFluentFormUploadRequest()
    {
        if (defined('DOING_AJAX') && DOING_AJAX) {
            $action = isset($_REQUEST['action']) ? sanitize_text_field(wp_unslash($_REQUEST['action'])) : '';
            if (strpos($action, 'fluentform') !== false) {
                return true;
            }
        }

        if (defined('REST_REQUEST') && REST_REQUEST) {
            $uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '';
            if (strpos($uri, '/fluentform/') !== false) {
                return true;
            }
        }

        foreach ($_REQUEST as $key => $value) {
            if (is_string($key) && strpos($key, '_fluentform_') !== false) {
                return true;
            }
            if (is_string($value) && strpos($value, 'fluentform') !== false && strpos($value, 'nonce') !== false) {
                return true;
            }
        }

        return false;
    }

    private function relocateToPrivateAndMark($value)
    {
        if (!is_string($value) || $value === '') {
            return $value;
        }

        if (strpos($value, self::PRIVATE_SCHEME) === 0) {
            return $value;
        }

        $relative = $this->extractUploadRelativePath($value);
        if (!$relative) {
            return $value;
        }

        $source = $this->sourceAbsolutePathFromRelative($relative);
        if (!$source || !is_file($source)) {
            $existingPrivate = $this->absolutePrivatePath($relative);
            if (is_file($existingPrivate)) {
                return self::PRIVATE_SCHEME . $relative;
            }

            return $value;
        }

        $destination = $this->absolutePrivatePath($relative);
        $destinationDir = dirname($destination);

        if (!is_dir($destinationDir)) {
            wp_mkdir_p($destinationDir);
        }

        if (!@rename($source, $destination)) {
            if (!@copy($source, $destination)) {
                return $value;
            }

            @unlink($source);
        }

        return self::PRIVATE_SCHEME . $relative;
    }

    private function mapFileValues(array $payload, callable $mapper)
    {
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->mapFileValues($value, $mapper);
                continue;
            }

            if (!is_string($value)) {
                continue;
            }

            $mapped = $mapper($value);
            if (is_string($mapped)) {
                $payload[$key] = $mapped;
            }
        }

        return $payload;
    }

    private function privateRelativePathFromValue($value)
    {
        if (!is_string($value) || $value === '') {
            return '';
        }

        if (strpos($value, self::PRIVATE_SCHEME) === 0) {
            return $this->sanitizeRelativePath(substr($value, strlen(self::PRIVATE_SCHEME)));
        }

        return $this->extractUploadRelativePath($value);
    }

    private function buildProtectedDownloadUrl($relative)
    {
        $base = add_query_arg(
            [
                'action'  => self::DOWNLOAD_ACTION,
                'ff_file' => $relative,
            ],
            admin_url('admin-post.php')
        );

        return wp_nonce_url($base, self::DOWNLOAD_ACTION . '|' . $relative);
    }

    private function extractUploadRelativePath($value)
    {
        if (!is_string($value) || $value === '') {
            return '';
        }

        $upload = wp_upload_dir();
        $baseUrl = (string) ($upload['baseurl'] ?? '');

        if ($baseUrl && strpos($value, $baseUrl) === 0) {
            $relative = ltrim(substr($value, strlen($baseUrl)), '/');
            return $this->sanitizeRelativePath($relative);
        }

        $path = wp_parse_url($value, PHP_URL_PATH);
        if (!$path) {
            return '';
        }

        $path = wp_normalize_path((string) $path);
        $marker = '/fluentform/';
        $pos = strpos($path, $marker);

        if ($pos === false) {
            return '';
        }

        $relative = ltrim(substr($path, $pos + 1), '/');

        return $this->sanitizeRelativePath($relative);
    }

    private function sourceAbsolutePathFromRelative($relative)
    {
        $relative = $this->sanitizeRelativePath($relative);

        if (!$relative) {
            return '';
        }

        $upload = wp_upload_dir();
        $basedir = (string) ($upload['basedir'] ?? '');

        if (!$basedir) {
            return '';
        }

        return wp_normalize_path(trailingslashit($basedir) . $relative);
    }

    private function absolutePrivatePath($relative)
    {
        $relative = $this->sanitizeRelativePath($relative);
        return wp_normalize_path(trailingslashit(self::privateBaseDir()) . $relative);
    }

    private function sanitizeRelativePath($relative)
    {
        $relative = wp_normalize_path((string) $relative);
        $relative = ltrim($relative, '/');

        if ($relative === '' || strpos($relative, '..') !== false) {
            return '';
        }

        return $relative;
    }

    private static function privateBaseDir()
    {
        $configured = (string) get_option(self::OPTION_PRIVATE_DIR, self::DEFAULT_PRIVATE_DIR);
        $configured = trim($configured);

        if ($configured === '') {
            $configured = self::DEFAULT_PRIVATE_DIR;
        }

        return wp_normalize_path($configured);
    }
}

FF_Private_Uploads_Extension::boot();
