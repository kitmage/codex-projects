<?php
/**
 * Plugin Name: Fluent Forms - Private Uploads (Admin-only Links)
 * Description: Stores Fluent Forms uploads outside web root and serves them via admin-only links.
 * Version:     1.0.1
 */

if (!defined('ABSPATH')) {
    exit;
}

if (class_exists('FF_Private_Uploads_Admin_Only', false)) {
    return;
}

final class FF_Private_Uploads_Admin_Only {

    // CHANGE THIS to your desired private directory
    const PRIVATE_BASEDIR = '/home/1264996.cloudwaysapps.com/hgfynmchnh/private_html/fluentforms-uploads';

    // A fake baseurl so nothing points to a real public URL
    const FAKE_BASEURL_PATH = '/__ff_private_uploads__';

    // Debug toggles (set false when done)
    const DEBUG = true;

    public static function boot() {
        add_action('init', [__CLASS__, 'ensure_private_dir']);
        add_filter('upload_dir', [__CLASS__, 'filter_upload_dir'], 50);

        // Rewrite Fluent Forms tokenized URLs to our private route when rendering entry values.
        add_filter('fluentform/response_render_input_file', [__CLASS__, 'rewrite_file_response_urls'], 9, 4);
        add_filter('fluentform/response_render_input_image', [__CLASS__, 'rewrite_file_response_urls'], 9, 4);

        // Serve fake URLs (front-end route)
        add_action('template_redirect', [__CLASS__, 'maybe_serve_fake_upload']);

        // Debug hooks
        add_action('admin_init', [__CLASS__, 'debug_admin_context'], 1);
        add_action('wp_ajax_fluentform_file_upload', [__CLASS__, 'debug_ff_upload_request'], 0);
        add_action('wp_ajax_nopriv_fluentform_file_upload', [__CLASS__, 'debug_ff_upload_request'], 0);
        add_action('wp_ajax_fluentform_file_upload', [__CLASS__, 'tap_ff_upload_ajax'], 0);
        add_action('wp_ajax_nopriv_fluentform_file_upload', [__CLASS__, 'tap_ff_upload_ajax'], 0);
        add_filter('wp_handle_upload', [__CLASS__, 'debug_wp_handle_upload'], 1, 2);
        add_filter('wp_handle_sideload', [__CLASS__, 'debug_wp_handle_upload'], 1, 2);
        add_action('shutdown', [__CLASS__, 'debug_fatal_error'], 9999);
    }

    /**
     * Fluent Forms stores tokenized URLs like /wp-content/uploads/fluentform/<token>.
     * Rewrite them to /__ff_private_uploads__/fluentform/<token> only for HTML rendering.
     */
    public static function rewrite_file_response_urls($response, $field = null, $form_id = null, $isHtml = false) {
        if (!$isHtml) {
            return $response;
        }

        try {
            return self::map_urls_recursive($response);
        } catch (\Throwable $e) {
            self::log('FF PRIVATE rewrite ERROR: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            return $response;
        }
    }

    private static function map_urls_recursive($value) {
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $value[$k] = self::map_urls_recursive($v);
            }
            return $value;
        }

        if (!is_string($value) || $value === '') {
            return $value;
        }

        if (!preg_match('#(?:https?://[^/]+)?/?wp-content/uploads/fluentform/([^/?#]+)|(?:^|/)fluentform/([^/?#]+)$#', $value, $m)) {
            return $value;
        }

        $token = !empty($m[1]) ? $m[1] : (!empty($m[2]) ? $m[2] : '');
        if (!$token) {
            return $value;
        }

        $rewritten = home_url(self::FAKE_BASEURL_PATH . '/fluentform/' . rawurlencode($token));
        self::log('FF PRIVATE rewrite url=' . $value . ' -> ' . $rewritten);

        return $rewritten;
    }

    public static function ensure_private_dir() {
        $base = rtrim(self::PRIVATE_BASEDIR, '/');
        if (!is_dir($base)) {
            wp_mkdir_p($base);
        }
    }

    private static function log($msg) {
        if (self::DEBUG) {
            error_log($msg);
        }
    }

    public static function debug_fatal_error() {
        if (!self::DEBUG) return;

        $error = error_get_last();
        if (!$error) return;

        $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR];
        if (!in_array($error['type'], $fatalTypes, true)) {
            return;
        }

        $uri = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '';
        self::log('FF PRIVATE FATAL type=' . $error['type'] . ' msg=' . $error['message'] . ' file=' . $error['file'] . ':' . $error['line'] . ' uri=' . $uri);
    }

    /**
     * Detect Fluent Forms upload requests.
     */
    private static function is_fluentforms_upload_request(): bool {
        $hasFiles = !empty($_FILES);
        $action = isset($_REQUEST['action']) ? (string) $_REQUEST['action'] : '';
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';

        // Canonical Fluent Forms AJAX upload endpoint.
        if (wp_doing_ajax() && $action === 'fluentform_file_upload') {
            return true;
        }

        // Fallback: Fluent Forms variations can use different upload/submit actions.
        if (wp_doing_ajax() && $action && preg_match('/^fluentform.*(upload|submit)/i', $action)) {
            self::log('FF PRIVATE upload detect fallback action=' . $action . ' files=' . ($hasFiles ? '1' : '0'));
            return true;
        }

        // Defensive fallback for non-ajax handlers that still carry FF form payload + files.
        $hasFluentFormMarker = isset($_REQUEST['fluent_forms_form_id'])
            || isset($_REQUEST['_fluentform'])
            || isset($_REQUEST['_fluentform_nonce'])
            || isset($_REQUEST['_wp_http_referer']) && strpos((string) $_REQUEST['_wp_http_referer'], 'fluentform') !== false
            || strpos($uri, 'fluentform') !== false;

        if ($hasFiles && $hasFluentFormMarker) {
            self::log('FF PRIVATE upload detect marker files=1 action=' . $action . ' uri=' . $uri);
            return true;
        }

        return false;
    }

    /**
     * Force uploaded files to land in PRIVATE_BASEDIR while a FF upload request is happening.
     */
    public static function filter_upload_dir(array $uploads): array {
        if (!self::is_fluentforms_upload_request()) {
            return $uploads;
        }

        $privateBase = rtrim(self::PRIVATE_BASEDIR, '/');
        $subdir      = $uploads['subdir'] ?? ''; // keep YYYY/MM
        $targetDir   = $privateBase . $subdir;

        if (!is_dir($targetDir)) {
            wp_mkdir_p($targetDir);
        }

        $fakeBaseurl = home_url(self::FAKE_BASEURL_PATH) . '/';

        $uploads['path']    = $targetDir;
        $uploads['basedir'] = $privateBase;
        $uploads['url']     = $fakeBaseurl . ltrim($subdir, '/');
        $uploads['baseurl'] = rtrim($fakeBaseurl, '/');

        return $uploads;
    }

    /**
     * Serve requests for direct relative paths and tokenized links.
     */
    public static function maybe_serve_fake_upload() {
        $prefix = self::FAKE_BASEURL_PATH . '/';

        $uri  = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        $path = parse_url($uri, PHP_URL_PATH);

        self::log('FF PRIVATE HIT path=' . (string)$path . ' uri=' . $uri);

        if (!$path || strpos($path, $prefix) !== 0) {
            return;
        }

        if (!is_user_logged_in() || !current_user_can('manage_options')) {
            status_header(403);
            exit('Forbidden');
        }

        $suffix = ltrim(substr($path, strlen($prefix)), '/');
        $suffix = rawurldecode($suffix);
        $suffix = strtr($suffix, '-_', '+/');

        $base = realpath(rtrim(self::PRIVATE_BASEDIR, '/'));
        self::log('FF PRIVATE suffix=' . $suffix . ' base=' . (string)$base);

        if (!$base) {
            status_header(500);
            exit('Private directory missing');
        }

        if (strpos($suffix, '..') !== false) {
            status_header(400);
            exit('Bad request');
        }

        $candidate = $base . '/' . $suffix;
        $real = realpath($candidate);

        if (!$real || strpos($real, $base) !== 0 || !is_file($real) || !is_readable($real)) {
            $short = basename($suffix);
            $matches = glob($base . '/*/*/*');

            if ($matches) {
                foreach ($matches as $m) {
                    if (!is_file($m) || !is_readable($m)) {
                        continue;
                    }

                    $rel = ltrim(str_replace($base, '', $m), '/');
                    if (strlen($short) && substr($rel, -strlen($short)) === $short) {
                        $real = realpath($m);
                        self::log('FF PRIVATE endswith match short=' . $short . ' -> ' . $rel);
                        break;
                    }
                }
            }
        }

        if (!$real || strpos($real, $base) !== 0 || !is_file($real) || !is_readable($real)) {
            status_header(404);
            exit('Not found');
        }

        if (ob_get_level()) {
            @ob_end_clean();
        }

        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . rawurlencode(basename($real)) . '"');
        header('Content-Length: ' . (string) filesize($real));
        header('X-Content-Type-Options: nosniff');

        $fh = fopen($real, 'rb');
        if ($fh) {
            while (!feof($fh)) {
                echo fread($fh, 1024 * 1024);
                flush();
            }
            fclose($fh);
        }
        exit;
    }

    public static function debug_admin_context() {
        if (!self::DEBUG) return;
        if (!current_user_can('manage_options')) return;

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $sid = $screen ? $screen->id : '(no screen)';
        $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        error_log('FF ADMIN CONTEXT screen=' . $sid . ' uri=' . $uri);

        if (wp_doing_ajax()) {
            $action = isset($_REQUEST['action']) ? (string) $_REQUEST['action'] : '';
            error_log('FF ADMIN AJAX action=' . $action);
        }
    }

    public static function debug_ff_upload_request() {
        if (!self::DEBUG) return;
        $action = isset($_REQUEST['action']) ? (string) $_REQUEST['action'] : '';
        $keys   = implode(',', array_keys($_REQUEST));
        error_log('FF FILE UPLOAD AJAX HIT action=' . $action . ' keys=' . $keys);
    }

    public static function tap_ff_upload_ajax() {
        if (!self::DEBUG) return;

        static $started = false;
        if ($started) return;
        $started = true;

        ob_start(function ($buffer) {
            error_log('FF UPLOAD RAW RESPONSE: ' . $buffer);
            return $buffer;
        });
    }

    public static function debug_wp_handle_upload($upload, $context) {
        if (!self::DEBUG) return $upload;

        $file = isset($upload['file']) ? $upload['file'] : '';
        $url  = isset($upload['url']) ? $upload['url'] : '';
        error_log('FF UPLOAD DEBUG hook=' . current_filter() . ' file=' . $file . ' url=' . $url);

        return $upload;
    }
}

FF_Private_Uploads_Admin_Only::boot();
