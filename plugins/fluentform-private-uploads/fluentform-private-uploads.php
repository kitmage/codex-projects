<?php
/**
 * Plugin Name: Fluent Forms - Private Uploads (Admin-only Links)
 * Description: Stores Fluent Forms uploads outside web root and serves them via admin-only links.
 * Version:     1.0.0
 */

if (!defined('ABSPATH')) exit;

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

        // Serve fake URLs (front-end route)
        add_action('template_redirect', [__CLASS__, 'maybe_serve_fake_upload']);

        // Debug: admin context + ajax action
        add_action('admin_init', [__CLASS__, 'debug_admin_context'], 1);

        // Debug: see upload ajax request keys
        add_action('wp_ajax_fluentform_file_upload', [__CLASS__, 'debug_ff_upload_request'], 0);
        add_action('wp_ajax_nopriv_fluentform_file_upload', [__CLASS__, 'debug_ff_upload_request'], 0);

        // Debug: capture raw JSON response from upload endpoint
        add_action('wp_ajax_fluentform_file_upload', [__CLASS__, 'tap_ff_upload_ajax'], 0);
        add_action('wp_ajax_nopriv_fluentform_file_upload', [__CLASS__, 'tap_ff_upload_ajax'], 0);

        // Debug: see WP upload results (proves upload_dir changes worked)
        add_filter('wp_handle_upload', [__CLASS__, 'debug_wp_handle_upload'], 1, 2);
        add_filter('wp_handle_sideload', [__CLASS__, 'debug_wp_handle_upload'], 1, 2);
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

    /**
     * Detect Fluent Forms upload requests.
     * Fluent Forms uploads occur via admin-ajax.php?action=fluentform_file_upload
     */
    private static function is_fluentforms_upload_request(): bool {
        if (!wp_doing_ajax()) return false;
        $action = isset($_REQUEST['action']) ? (string) $_REQUEST['action'] : '';
        return ($action === 'fluentform_file_upload');
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

        // Fake baseurl; FF will store URLs under this path
        $fakeBaseurl = home_url(self::FAKE_BASEURL_PATH) . '/';

        $uploads['path']    = $targetDir;
        $uploads['basedir'] = $privateBase;
        $uploads['url']     = $fakeBaseurl . ltrim($subdir, '/'); // keeps YYYY/MM
        $uploads['baseurl'] = rtrim($fakeBaseurl, '/');

        return $uploads;
    }

    /**
     * Serve requests like:
     *   /__ff_private_uploads__/2026/02/<filename>
     * and also FF tokenized links:
     *   /__ff_private_uploads__/fluentform/<shortToken>
     *
     * Access: admins only.
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

        // normalize base64url -> base64 (defensive)
        $suffix = strtr($suffix, '-_', '+/');

        $base = realpath(rtrim(self::PRIVATE_BASEDIR, '/'));
        self::log('FF PRIVATE suffix=' . $suffix . ' base=' . (string)$base);

        if (!$base) {
            status_header(500);
            exit('Private directory missing');
        }

        // Prevent traversal
        if (strpos($suffix, '..') !== false) {
            status_header(400);
            exit('Bad request');
        }

        // 1) Direct mapping: suffix is real relative path like "2026/02/file.jpg"
        $candidate = $base . '/' . $suffix;
        $real = realpath($candidate);

        // 2) If FF stored "fluentform/<shortToken>", we must find the real file whose relpath ends with that token
        if (!$real || strpos($real, $base) !== 0 || !is_file($real) || !is_readable($real)) {

            $short = basename($suffix); // e.g. "Xiw=="

            // Search only two levels deep (YYYY/MM), then match end segment.
            $matches = glob($base . '/*/*/*');

            if ($matches) {
                foreach ($matches as $m) {
                    if (!is_file($m) || !is_readable($m)) continue;

                    $rel = ltrim(str_replace($base, '', $m), '/'); // "2026/02/<something...>"
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

    /**
     * Debug: admin page context & ajax actions
     */
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

    /**
     * Debug: file upload ajax keys
     */
    public static function debug_ff_upload_request() {
        if (!self::DEBUG) return;
        $action = isset($_REQUEST['action']) ? (string) $_REQUEST['action'] : '';
        $keys   = implode(',', array_keys($_REQUEST));
        error_log('FF FILE UPLOAD AJAX HIT action=' . $action . ' keys=' . $keys);
    }

    /**
     * Debug: capture the raw JSON output from the upload endpoint.
     * Must not change output.
     */
    public static function tap_ff_upload_ajax() {
        if (!self::DEBUG) return;

        // avoid stacking multiple buffers if this action fires twice for any reason
        static $started = false;
        if ($started) return;
        $started = true;

        ob_start(function ($buffer) {
            error_log('FF UPLOAD RAW RESPONSE: ' . $buffer);
            return $buffer;
        });
    }

    /**
     * Debug: confirm WP upload handler sees the private path + fake url
     */
    public static function debug_wp_handle_upload($upload, $context) {
        if (!self::DEBUG) return $upload;

        $file = isset($upload['file']) ? $upload['file'] : '';
        $url  = isset($upload['url']) ? $upload['url'] : '';
        error_log('FF UPLOAD DEBUG hook=' . current_filter() . ' file=' . $file . ' url=' . $url);

        return $upload;
    }
}

FF_Private_Uploads_Admin_Only::boot();
