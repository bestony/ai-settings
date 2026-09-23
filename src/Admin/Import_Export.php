<?php

/**
 * Settings import and export.
 *
 * @package AISettings
 */

declare(strict_types=1);

namespace AISettings\Admin;

/**
 * Exports and imports the AI plugin's settings.
 *
 * Both directions go through the AI plugin's own `ai/v1/settings` endpoints rather than reapplying
 * their logic here. That keeps the sensitive-option filter and the schema validation owned by the
 * plugin that defines them, and makes the files this screen produces interchangeable with the
 * plugin's own export.
 */
final class Import_Export
{
    /**
     * The `admin_post` action that downloads an export.
     *
     * @var string
     */
    public const EXPORT_ACTION = 'ai_settings_export';

    /**
     * The `admin_post` action that accepts an upload.
     *
     * @var string
     */
    public const IMPORT_ACTION = 'ai_settings_import';

    /**
     * The `name` attribute of the upload field.
     *
     * @var string
     */
    public const FILE_FIELD = 'ai_settings_import_file';

    /**
     * The REST route that produces an export.
     *
     * @var string
     */
    private const EXPORT_ROUTE = '/ai/v1/settings/export';

    /**
     * The REST route that consumes an import.
     *
     * @var string
     */
    private const IMPORT_ROUTE = '/ai/v1/settings/import';

    /**
     * The capability required for both operations.
     *
     * @var string
     */
    private const CAPABILITY = 'manage_options';

    /**
     * Registers the admin hooks.
     *
     * @return void
     */
    public function register(): void
    {
        add_action('admin_post_' . self::EXPORT_ACTION, array($this, 'handle_export'));
        add_action('admin_post_' . self::IMPORT_ACTION, array($this, 'handle_import'));
    }

    /**
     * Streams the AI plugin's settings export as a download.
     *
     * @return void
     */
    public function handle_export(): void
    {
        check_admin_referer(self::EXPORT_ACTION);

        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You are not allowed to export these settings.', 'bestonys-ai-settings'));
        }

        $response = $this->request(new \WP_REST_Request('GET', self::EXPORT_ROUTE));

        if (is_wp_error($response)) {
            $this->fail($response->get_error_message());
        }

        $payload  = $response->get_data();
        $filename = 'bestonys-ai-settings-' . gmdate('Ymd-His') . '.json';

        nocache_headers();
        header('Content-Type: application/json; charset=' . get_option('blog_charset'));
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        echo wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Applies an uploaded export.
     *
     * @return void
     */
    public function handle_import(): void
    {
        check_admin_referer(self::IMPORT_ACTION);

        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You are not allowed to import these settings.', 'bestonys-ai-settings'));
        }

        $payload = $this->read_upload();

        if (is_wp_error($payload)) {
            $this->fail($payload->get_error_message());
        }

        $request = new \WP_REST_Request('POST', self::IMPORT_ROUTE);
        $request->set_param('version', (int) ($payload['version'] ?? 0));
        $request->set_param('exported_at', (string) ($payload['exported_at'] ?? ''));
        $request->set_param('plugin_version', (string) ($payload['plugin_version'] ?? ''));
        $request->set_param(
            'providers',
            is_array($payload['providers'] ?? null) ? $payload['providers'] : array()
        );
        $request->set_param(
            'settings',
            is_array($payload['settings'] ?? null) ? $payload['settings'] : array()
        );

        $response = $this->request($request);

        if (is_wp_error($response)) {
            $this->fail($response->get_error_message());
        }

        $data = $response->get_data();

        $this->redirect(
            array(
                'ai_settings_updated'  => (int) ($data['imported'] ?? 0),
                'ai_settings_rejected' => (int) ($data['rejected'] ?? 0),
            )
        );
    }

    /**
     * Reads and decodes the uploaded file.
     *
     * @return array<string, mixed>|\WP_Error The decoded payload, or an error.
     */
    private function read_upload()
    {
        /*
         * handle_import() has already checked the nonce and the capability; what is read here is the
         * upload metadata WordPress itself populated, not user input.
         */
        // phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $file = isset($_FILES[self::FILE_FIELD]) && is_array($_FILES[self::FILE_FIELD])
            ? $_FILES[self::FILE_FIELD]
            : array();
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        // phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        if (UPLOAD_ERR_NO_FILE === $error) {
            return new \WP_Error(
                'ai_settings_no_file',
                __('Choose a settings JSON file to import.', 'bestonys-ai-settings')
            );
        }

        if (UPLOAD_ERR_OK !== $error || !isset($file['tmp_name'])) {
            return new \WP_Error(
                'ai_settings_upload_failed',
                __('The upload failed. Please try again.', 'bestonys-ai-settings')
            );
        }

        $tmp_name = (string) $file['tmp_name'];

        if ('' === $tmp_name || !is_uploaded_file($tmp_name)) {
            return new \WP_Error(
                'ai_settings_not_uploaded',
                __('The uploaded file could not be read.', 'bestonys-ai-settings')
            );
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading an uploaded temp file.
        $contents = file_get_contents($tmp_name);

        if (false === $contents) {
            return new \WP_Error(
                'ai_settings_unreadable',
                __('The uploaded file could not be read.', 'bestonys-ai-settings')
            );
        }

        $decoded = json_decode($contents, true);

        if (!is_array($decoded) || !isset($decoded['version'])) {
            return new \WP_Error(
                'ai_settings_invalid_payload',
                __('That file is not an AI plugin settings export.', 'bestonys-ai-settings')
            );
        }

        return $decoded;
    }

    /**
     * Performs a REST request against the AI plugin.
     *
     * @param \WP_REST_Request $request The request to perform.
     * @return \WP_REST_Response|\WP_Error The response, or an error.
     */
    private function request(\WP_REST_Request $request)
    {
        /*
         * Serving the request internally still runs WordPress' cookie authentication, but the AI
         * plugin's endpoints only ask for `manage_options` — which both callers have already checked
         * alongside their own nonce. WordPress skips its REST cookie-nonce check for internal
         * requests made by an authenticated user, so no nonce has to be supplied here.
         */
        $response = rest_do_request($request);

        if (is_wp_error($response)) {
            return $response;
        }

        if ($response->is_error()) {
            return $response->as_error();
        }

        return $response;
    }

    /**
     * Redirects back to the settings screen with an error message.
     *
     * @param string $message The message to show.
     * @return void
     */
    private function fail(string $message): void
    {
        $this->redirect(array('ai_settings_error' => $message));
    }

    /**
     * Redirects back to the settings screen, on the import and export tab, with result flags.
     *
     * @param array<string, int|string> $args The query arguments to append.
     * @return void
     */
    private function redirect(array $args): void
    {
        $args[Settings_Page::TAB_ARG] = Settings_Page::TAB_EXPORT;

        wp_safe_redirect(
            add_query_arg($args, admin_url('options-general.php?page=' . Settings_Page::PAGE_SLUG))
        );
        exit;
    }
}
