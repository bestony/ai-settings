<?php

// phpcs:ignoreFile -- dev-only CLI harness; .gitattributes export-ignores it from releases.

/**
 * Runnable self-check for the Bestony's AI Settings plugin.
 *
 * Covers what this plugin has to keep true — the slug and text domain, the absence of bundled
 * translations and of `load_plugin_textdomain()`, the version written in three places, and the
 * discovery and write paths — without WordPress, composer or a live AI plugin. One file, no test
 * framework, because this is the smallest thing that fails when the plugin breaks.
 *
 * Usage:
 *   php scripts/selfcheck.php
 *
 * @package AISettings
 */

declare(strict_types=1);

use AISettings\Config\Collector;
use AISettings\Config\Field;
use AISettings\Config\Model_Catalog;
use AISettings\Config\Writer;

$root = dirname(__DIR__);

/*
 * The plugin's autoloader refuses to run outside WordPress (Plugin Check requires a direct-access
 * guard on it), so this harness defines ABSPATH the way a WordPress test bootstrap does.
 */
if (!defined('ABSPATH')) {
    define('ABSPATH', $root . '/');
}

$GLOBALS['aisettings_options']         = array();
$GLOBALS['aisettings_domains']         = array();
$GLOBALS['aisettings_registered']      = array();
$GLOBALS['aisettings_connectors']      = array();
$GLOBALS['aisettings_providers_route'] = array();
$GLOBALS['aisettings_capabilities']    = array();

$checks   = 0;
$failures = 0;

/**
 * Asserts a condition and records the outcome.
 *
 * @param bool   $condition The condition to check.
 * @param string $description What is being checked.
 * @return void
 */
function check(bool $condition, string $description): void
{
    global $checks, $failures;

    ++$checks;

    if (!$condition) {
        ++$failures;
        fwrite(STDERR, "FAIL  {$description}\n");

        return;
    }

    fwrite(STDOUT, "ok    {$description}\n");
}

/**
 * Lists a directory's files with the given extensions, skipping development directories.
 *
 * @param string        $directory The directory to walk.
 * @param list<string>  $extensions Lower-case extensions to keep.
 * @return list<string> Absolute file paths, sorted.
 */
function aisettings_files(string $directory, array $extensions): array
{
    $skip = array('.git', '.commandcode', '.github', 'scripts', 'build', 'node_modules');

    $filter = new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        static function (SplFileInfo $current) use ($skip): bool {
            return !($current->isDir() && in_array($current->getFilename(), $skip, true));
        }
    );

    $files = array();

    foreach (new RecursiveIteratorIterator($filter) as $file) {
        if ($file->isFile() && in_array(strtolower($file->getExtension()), $extensions, true)) {
            $files[] = $file->getPathname();
        }
    }

    sort($files);

    return $files;
}

/**
 * Reads a file, failing the check when it cannot be read.
 *
 * @param string $path The file path.
 * @return string The contents, or an empty string.
 */
function aisettings_read(string $path): string
{
    $contents = @file_get_contents($path);

    return false === $contents ? '' : $contents;
}

/*
 * WordPress stubs. Only the handful of functions the exercised classes call at runtime are
 * provided; `__()` records every domain it is handed so the text domain can be checked in situ.
 */
function __($text, $domain = 'default')
{
    $GLOBALS['aisettings_domains'][] = $domain;

    return $text;
}

function is_wp_error($thing)
{
    return $thing instanceof WP_Error;
}

function get_registered_settings()
{
    return $GLOBALS['aisettings_registered'];
}

function get_option($name, $default = false)
{
    return array_key_exists($name, $GLOBALS['aisettings_options']) ? $GLOBALS['aisettings_options'][$name] : $default;
}

function update_option($name, $value, $autoload = null)
{
    $GLOBALS['aisettings_options'][$name] = $value;

    return true;
}

function sanitize_text_field($str)
{
    return trim(strip_tags((string) $str));
}

function rest_sanitize_boolean($value)
{
    return in_array($value, array(true, 1, '1', 'true', 'on', 'yes'), true);
}

function rest_validate_value_from_schema($value, $schema, $param = '')
{
    $type = $schema['type'] ?? 'string';

    switch ($type) {
        case 'boolean':
            if (!is_bool($value) && !in_array($value, array(0, 1, '0', '1'), true)) {
                return new WP_Error('rest_invalid_type', sprintf('%s is not a boolean.', $param));
            }

            return true;

        case 'integer':
            if (!is_int($value) && !(is_string($value) && 1 === preg_match('/^-?\d+$/', $value))) {
                return new WP_Error('rest_invalid_type', sprintf('%s is not an integer.', $param));
            }

            $integer = (int) $value;

            if (isset($schema['minimum']) && $integer < $schema['minimum']) {
                return new WP_Error('rest_out_of_bounds', sprintf('%s is below the minimum.', $param));
            }

            if (isset($schema['maximum']) && $integer > $schema['maximum']) {
                return new WP_Error('rest_out_of_bounds', sprintf('%s is above the maximum.', $param));
            }

            return true;

        case 'object':
            if (!is_array($value)) {
                return new WP_Error('rest_invalid_type', sprintf('%s is not an object.', $param));
            }

            return true;

        case 'string':
        default:
            if (!is_string($value) && !is_numeric($value)) {
                return new WP_Error('rest_invalid_type', sprintf('%s is not a string.', $param));
            }

            if (isset($schema['enum'])) {
                $allowed = array_map('strval', $schema['enum']);

                if (!in_array((string) $value, $allowed, true)) {
                    return new WP_Error('rest_not_in_enum', sprintf('%s is not one of the allowed values.', $param));
                }
            }

            return true;
    }
}

function rest_sanitize_value_from_schema($value, $schema, $param = '')
{
    $type = $schema['type'] ?? 'string';

    if ('boolean' === $type) {
        return (bool) rest_sanitize_boolean($value);
    }

    if ('integer' === $type) {
        return (int) $value;
    }

    if ('object' === $type) {
        return is_array($value) ? $value : array();
    }

    return (string) $value;
}

function wp_get_connectors()
{
    return $GLOBALS['aisettings_connectors'];
}

function rest_do_request($request)
{
    $capability = $request->get_param('capability');

    $GLOBALS['aisettings_capabilities'][] = $capability;

    $data = $GLOBALS['aisettings_providers_route'][$capability] ?? array();

    if ($data instanceof WP_Error) {
        return $data;
    }

    return new WP_REST_Response($data);
}

/**
 * Minimal stand-in for WordPress' error object.
 */
class WP_Error
{
    /** @var string */
    private $message;

    /**
     * Constructor.
     *
     * @param string $code The error code.
     * @param string $message The error message.
     */
    public function __construct(string $code = '', string $message = '')
    {
        $this->message = $message;
    }

    /**
     * Gets the error message.
     *
     * @return string The message.
     */
    public function get_error_message(): string
    {
        return $this->message;
    }
}

/**
 * Minimal stand-in for WordPress' REST request.
 */
class WP_REST_Request
{
    /** @var array<string, mixed> */
    private $params = array();

    /**
     * Constructor.
     *
     * @param string $method The HTTP method.
     * @param string $route The route.
     */
    public function __construct(string $method = 'GET', string $route = '')
    {
    }

    /**
     * Sets a parameter.
     *
     * @param string $key The parameter name.
     * @param mixed  $value The value.
     * @return void
     */
    public function set_param(string $key, $value): void
    {
        $this->params[$key] = $value;
    }

    /**
     * Gets a parameter.
     *
     * @param string $key The parameter name.
     * @return mixed The value.
     */
    public function get_param(string $key)
    {
        return $this->params[$key] ?? null;
    }
}

/**
 * Minimal stand-in for WordPress' REST response.
 */
class WP_REST_Response
{
    /** @var mixed */
    private $data;

    /**
     * Constructor.
     *
     * @param mixed $data The response body.
     */
    public function __construct($data = null)
    {
        $this->data = $data;
    }

    /**
     * Whether the response is an error.
     *
     * @return bool Always false in this stub.
     */
    public function is_error(): bool
    {
        return false;
    }

    /**
     * Gets the response body.
     *
     * @return mixed The body.
     */
    public function get_data()
    {
        return $this->data;
    }
}

/**
 * A stand-in for one of the AI plugin's features.
 */
final class Fake_Feature
{
    /** @var string */
    private $label;

    /** @var string */
    private $description;

    /** @var string */
    private $capability;

    /** @var bool */
    private $enabled;

    /** @var array<string, mixed> */
    private $metadata;

    /**
     * Constructor.
     *
     * @param string               $label The label.
     * @param string               $description The description.
     * @param string               $capability The capability.
     * @param bool                 $enabled Whether it is running.
     * @param array<string, mixed> $metadata The settings-field metadata, keyed by option name.
     */
    public function __construct(
        string $label,
        string $description,
        string $capability,
        bool $enabled,
        array $metadata = array()
    ) {
        $this->label       = $label;
        $this->description = $description;
        $this->capability  = $capability;
        $this->enabled     = $enabled;
        $this->metadata    = $metadata;
    }

    /**
     * Gets the label.
     *
     * @return string The label.
     */
    public function get_label(): string
    {
        return $this->label;
    }

    /**
     * Gets the description.
     *
     * @return string The description.
     */
    public function get_description(): string
    {
        return $this->description;
    }

    /**
     * Gets the capability.
     *
     * @return string The capability.
     */
    public function get_capability(): string
    {
        return $this->capability;
    }

    /**
     * Whether the feature is running.
     *
     * @return bool True when running.
     */
    public function is_enabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Gets the settings-field metadata.
     *
     * @return array<string, mixed> The metadata.
     */
    public function get_settings_fields_metadata(): array
    {
        return $this->metadata;
    }
}

/**
 * A stand-in for the AI plugin's feature registry.
 */
final class Fake_Registry
{
    /** @var array<string, object> */
    private $features;

    /**
     * Constructor.
     *
     * @param array<string, object> $features The features, keyed by id.
     */
    public function __construct(array $features)
    {
        $this->features = $features;
    }

    /**
     * Gets every feature.
     *
     * @return array<string, object> The features.
     */
    public function get_all_features(): array
    {
        return $this->features;
    }
}

require $root . '/src/autoload.php';

$slug     = 'bestonys-ai-settings';
$name     = "Bestony's AI Settings";
$mainfile = $root . '/bestonys-ai-settings.php';

// --- The plugin file and its header. -----------------------------------------------------------
check(is_file($mainfile), 'the main plugin file is named after the slug (' . basename($mainfile) . ')');
check(!is_file($root . '/ai-settings.php'), 'the old main plugin file name is gone');

$header = aisettings_read($mainfile);
check(
    1 === preg_match('/^ \* Plugin Name:\s+(.+)$/m', $header, $matches) && trim($matches[1]) === $name,
    'the plugin header carries the new display name'
);
check(
    1 === preg_match('/^ \* Text Domain:\s+(.+)$/m', $header, $matches) && trim($matches[1]) === $slug,
    'the plugin header text domain matches the slug'
);

// --- Version written in three places. -----------------------------------------------------------
$version_header = 1 === preg_match('/^ \* Version:\s+([\d.]+)/m', $header, $matches) ? $matches[1] : '';
$version_const  = 1 === preg_match("/define\('AISETTINGS_VERSION', '([\d.]+)'\)/", $header, $matches) ? $matches[1] : '';
$readme         = aisettings_read($root . '/readme.txt');
$version_stable = 1 === preg_match('/^Stable tag:\s+([\d.]+)/m', $readme, $matches) ? $matches[1] : '';

check('' !== $version_header, 'the plugin header declares a version');
check($version_header === $version_const, 'AISETTINGS_VERSION matches the plugin header');
check($version_header === $version_stable, 'the readme Stable tag matches the plugin header');
check(1 === preg_match('/^= ' . preg_quote($version_header, '/') . ' =$/m', $readme), 'the readme has a changelog entry for this version');

// --- No bundled translations and no load_plugin_textdomain(). ------------------------------------
check(!is_dir($root . '/languages'), 'the languages directory is not shipped');

$translation_files = array();
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
    if ($file->isFile() && in_array(strtolower($file->getExtension()), array('po', 'mo', 'pot'), true)) {
        $translation_files[] = $file->getPathname();
    }
}
check(array() === $translation_files, 'no .po, .mo or .pot files are shipped');

$php_files = aisettings_files($root, array('php'));
check(array() !== $php_files, 'the shipped PHP files were found');

$with_loader = array();
foreach ($php_files as $file) {
    if (false !== strpos(aisettings_read($file), 'load_plugin_textdomain')) {
        $with_loader[] = basename($file);
    }
}
check(array() === $with_loader, 'load_plugin_textdomain() is not used' . ($with_loader ? ': ' . implode(', ', $with_loader) : ''));

// --- The old slug and camelCase global are gone. ------------------------------------------------
$code_files = aisettings_files($root, array('php', 'js'));

$stragglers = array();
foreach ($code_files as $file) {
    $contents = str_replace($slug, '', aisettings_read($file));

    if (false !== strpos($contents, 'ai-settings') || false !== strpos($contents, 'aiSettings')) {
        $stragglers[] = basename($file);
    }
}
check(array() === $stragglers, 'no files still reference the old slug' . ($stragglers ? ': ' . implode(', ', $stragglers) : ''));

$domained = array();
foreach ($code_files as $file) {
    if (false !== strpos(aisettings_read($file), "'" . $slug . "'")) {
        $domained[] = basename($file);
    }
}
check(array() !== $domained, 'the text domain is used by the shipped code');

// --- Discovery: the collector. ------------------------------------------------------------------
$empty_collector = new Collector(null);
check(!$empty_collector->is_available(), 'an empty registry reports no AI plugin options');
check($empty_collector->collect()->is_empty(), 'an empty registry yields an empty collection');

$GLOBALS['aisettings_domains']         = array();
$GLOBALS['aisettings_options']         = array();
$GLOBALS['aisettings_registered']      = array(
    'wpai_features_enabled'                                  => array(
        'group'        => 'ai_experiments',
        'type'         => 'boolean',
        'default'      => false,
        'show_in_rest' => array('schema' => array('type' => 'boolean')),
    ),
    'wpai_feature_alt-text-generation_enabled'               => array(
        'group'        => 'ai_experiments',
        'type'         => 'boolean',
        'default'      => true,
        'show_in_rest' => array('schema' => array('type' => 'boolean')),
    ),
    'wpai_feature_alt-text-generation_field_developer'       => array(
        'group'        => 'ai_experiments',
        'type'         => 'object',
        'default'      => array(),
        'show_in_rest' => array('schema' => array('type' => 'object')),
    ),
    'wpai_feature_content-classification_enabled'            => array(
        'group'        => 'ai_experiments',
        'type'         => 'boolean',
        'default'      => false,
        'show_in_rest' => array('schema' => array('type' => 'boolean')),
    ),
    'wpai_feature_content-classification_field_strategy'     => array(
        'group'        => 'ai_experiments',
        'type'         => 'string',
        'default'      => 'tags',
        'show_in_rest' => array('schema' => array('type' => 'string', 'enum' => array('tags', 'categories'))),
    ),
    'wpai_feature_content-classification_field_max_suggestions' => array(
        'group'        => 'ai_experiments',
        'type'         => 'integer',
        'default'      => 5,
        'show_in_rest' => array('schema' => array('type' => 'integer', 'minimum' => 0, 'maximum' => 20)),
    ),
    'wpai_feature_content-classification_field_developer'    => array(
        'group'        => 'ai_experiments',
        'type'         => 'object',
        'default'      => array(),
        'show_in_rest' => array('schema' => array('type' => 'object')),
    ),
    'wpai_feature_type-ahead_enabled'                        => array(
        'group'        => 'ai_experiments',
        'type'         => 'boolean',
        'default'      => false,
        'show_in_rest' => array('schema' => array('type' => 'boolean')),
    ),
    'wpai_feature_mystery-feature_enabled'                   => array(
        'group'        => 'ai_experiments',
        'type'         => 'boolean',
        'default'      => false,
        'show_in_rest' => array('schema' => array('type' => 'boolean')),
    ),
    // A registered option outside the AI plugin's settings group must be ignored.
    'some_other_plugin_option'                               => array(
        'group' => 'general',
        'type'  => 'string',
    ),
);

$registry = new Fake_Registry(
    array(
        'alt-text-generation'    => new Fake_Feature('Alt text', 'Describe the image.', 'vision', true),
        'content-classification' => new Fake_Feature(
            'Content classification',
            'Suggest terms.',
            'text_generation',
            false,
            array(
                'wpai_feature_content-classification_field_strategy' => array(
                    'id'       => 'wpai_feature_content-classification_field_strategy',
                    'type'     => 'text',
                    'elements' => array(
                        array('value' => 'tags', 'label' => 'Tags'),
                    ),
                ),
            )
        ),
        'type-ahead'             => new Fake_Feature('Type-ahead', 'Suggest as you type.', 'text_generation', false),
        'mystery-feature'        => new Fake_Feature('Mystery', 'Nobody mapped this.', 'none', true),
    )
);

$collector  = new Collector($registry);
$collection = $collector->collect();

check($collector->is_available(), 'a populated settings group reports the AI plugin as available');
check(!$collection->is_empty(), 'the collection is not empty');

$domains = array_values(array_unique($GLOBALS['aisettings_domains']));
check(array($slug) === $domains, 'every translated string discovered uses the new text domain');
check(count($GLOBALS['aisettings_domains']) > 0, 'the discovery path translates its labels');

$section_ids = array_keys($collection->sections());
check('global' === $section_ids[0], 'the master switch is the first section');
check(true === $collection->section('global')->is_master(), 'the master switch section is marked as such');
check('' === $collection->section('global')->module(), 'the master switch belongs to no module');

check(
    array('assist', 'seo', 'media', 'other') === array_keys($collection->modules()),
    'only the modules that hold a section are offered, in order'
);
check('media' === $collection->section('alt-text-generation')->module(), 'a known feature is filed under its module');
check('seo' === $collection->section('content-classification')->module(), 'a second known feature is filed under its module');
check('other' === $collection->section('mystery-feature')->module(), 'an unknown feature lands in Other');

check(
    null === $collection->field('some_other_plugin_option'),
    'an option outside the AI plugin settings group is not collected'
);

// --- Field shapes. ------------------------------------------------------------------------------
$master = $collection->field('wpai_features_enabled');
check(Field::KIND_BOOL === $master->kind(), 'a boolean schema yields a toggle');
check($master->is_toggle(), 'the master switch is a toggle');
check($master->is_registered(), 'a registered option is marked as registered');

$strategy = $collection->field('wpai_feature_content-classification_field_strategy');
check(Field::KIND_SELECT === $strategy->kind(), 'field metadata with elements yields a dropdown');
check($strategy->has_choices(), 'the dropdown carries its choices');
check(
    array(array('value' => 'tags', 'label' => 'Tags')) === $strategy->choices(),
    'the feature registry\'s labels win over the schema enum'
);

$max = $collection->field('wpai_feature_content-classification_field_max_suggestions');
check(Field::KIND_INTEGER === $max->kind(), 'an integer schema yields a number input');
check(0 === $max->min() && 20 === $max->max(), 'the schema bounds are carried onto the field');

$developer = $collection->field('wpai_feature_alt-text-generation_field_developer');
check(Field::KIND_PROVIDER_MODEL === $developer->kind(), 'an object schema yields a provider/model override');

// --- Options the AI plugin reads but never registers. --------------------------------------------
$mode = $collection->field('wpai_feature_type-ahead_field_mode');
check(null !== $mode, 'an unregistered type-ahead option is collected');
check(Field::KIND_SELECT === $mode->kind(), 'the unregistered completion mode is a dropdown');
check(!$mode->is_registered(), 'the unregistered option is marked as unregistered');
check('smart' === $mode->default_value(), 'the unregistered option keeps its default');

$delay = $collection->field('wpai_feature_type-ahead_field_delay');
check(Field::KIND_INTEGER === $delay->kind(), 'the unregistered delay is a number input');
check(200 === $delay->min(), 'the unregistered delay keeps its minimum');

$headings = $collection->field('wpai_feature_type-ahead_field_headings');
check(Field::KIND_BOOL === $headings->kind(), 'the unregistered headings option is a toggle');

check(
    null === $collection->field('wpai_feature_type-ahead_field_missing'),
    'an option with no definition and no registration is not invented'
);

// --- Collection lookups. ------------------------------------------------------------------------
check(
    array_keys($collection->fields()) === $collection->names(),
    'the write whitelist is every collected option name'
);
check(
    in_array('wpai_feature_alt-text-generation_enabled', $collection->names(), true),
    'the whitelist contains the collected feature switches'
);
check(
    !in_array('some_other_plugin_option', $collection->names(), true),
    'the whitelist excludes options the AI plugin does not own'
);
check(
    array('wpai_feature_alt-text-generation_enabled', 'wpai_feature_alt-text-generation_field_developer')
        === array_keys($collection->fields_in('alt-text-generation')),
    'fields_in lists one feature\'s options in order'
);
check(
    array('alt-text-generation') === array_keys($collection->sections_in('media')),
    'sections_in lists one module\'s features'
);
check(null === $collection->section('nope'), 'an unknown section id yields null');
check(null === $collection->field('nope'), 'an unknown option name yields null');

// --- Capabilities. ------------------------------------------------------------------------------
check('vision' === $collector->capability_of('alt-text-generation'), 'a declared capability is reported');
check('none' === $collector->capability_of('mystery-feature'), 'a feature that declares "none" keeps it');
check('text_generation' === $collector->capability_of('not-a-feature'), 'an unknown feature defaults to text generation');

// --- Providers. ---------------------------------------------------------------------------------
$GLOBALS['aisettings_connectors'] = array(
    'commandcode' => array('type' => 'ai_provider', 'name' => 'Command Code'),
    'openai'      => array('type' => 'ai_provider', 'name' => ''),
    'other'       => array('type' => 'connector', 'name' => 'Not a provider'),
);
check(
    array('commandcode' => 'Command Code', 'openai' => 'openai') === $collector->providers(),
    'only AI providers are offered, and a nameless one falls back to its id'
);
$GLOBALS['aisettings_connectors'] = array();

// --- The model catalog. -------------------------------------------------------------------------
$catalog = new Model_Catalog();
check(array() === $catalog->models('not_a_capability'), 'an unsupported capability offers no models');

$GLOBALS['aisettings_providers_route'] = array(
    'text_generation' => array(
        array(
            'id'     => 'openai',
            'name'   => 'OpenAI',
            'models' => array(
                array('id' => 'gpt-x', 'name' => 'GPT-X'),
                array('id' => 'gpt-y'),
            ),
        ),
        array('id' => 'broken'),
        array('name' => 'no id'),
    ),
    'vision'          => array(
        array(
            'id'     => 'openai',
            'name'   => 'OpenAI',
            'models' => array(array('id' => 'gpt-v')),
        ),
        array(
            'id'     => 'anthropic',
            'name'   => 'Anthropic',
            'models' => array(array('id' => 'claude-v', 'name' => 'Claude V')),
        ),
    ),
    'image_generation' => new WP_Error('ai_no_client', 'No AI client.'),
);

$catalog = new Model_Catalog();
check(
    array(
        'openai' => array('name' => 'OpenAI', 'models' => array('gpt-x' => 'GPT-X', 'gpt-y' => 'gpt-y')),
        'broken' => array('name' => 'broken', 'models' => array()),
    ) === $catalog->models('text_generation'),
    'a provider is normalised, a nameless one keeps its id, a nameless model keeps its id, and an id-less provider is dropped'
);

$catalog = new Model_Catalog();
check(
    array(
        'openai'    => array('name' => 'OpenAI', 'models' => array('gpt-x' => 'GPT-X', 'gpt-y' => 'gpt-y', 'gpt-v' => 'gpt-v')),
        'broken'    => array('name' => 'broken', 'models' => array()),
        'anthropic' => array('name' => 'Anthropic', 'models' => array('claude-v' => 'Claude V')),
    ) === $catalog->union(array('text_generation', 'vision')),
    'the union merges each provider\'s models'
);

$catalog = new Model_Catalog();
check(array() === $catalog->models('image_generation'), 'a route that errors yields an empty catalog');

// --- Saving: the write whitelist and validation. -------------------------------------------------
$GLOBALS['aisettings_options'] = array();
$writer                        = new Writer($collector);

$result = $writer->save(array('some_other_plugin_option' => 'x'));
check(0 === $result['updated'] && array() === $result['rejected'], 'an option outside the whitelist is never written');
check(!array_key_exists('some_other_plugin_option', $GLOBALS['aisettings_options']), 'the ignored option is absent from storage');

$result = $writer->save(array('wpai_feature_content-classification_field_max_suggestions' => 999));
check(1 === count($result['rejected']) && 0 === $result['updated'], 'a value above the schema maximum is rejected, not stored');
check(!array_key_exists('wpai_feature_content-classification_field_max_suggestions', $GLOBALS['aisettings_options']), 'the rejected value is not stored');

$result = $writer->save(array('wpai_feature_content-classification_field_strategy' => 'bogus'));
check(1 === count($result['rejected']), 'a value outside the schema enum is rejected');

$result = $writer->save(
    array(
        'wpai_features_enabled'                         => '1',
        'wpai_feature_content-classification_field_strategy' => 'tags',
        'wpai_feature_content-classification_field_max_suggestions' => 5,
    )
);
check(3 === $result['updated'] && array() === $result['rejected'], 'schema-valid values are written');
check(true === $GLOBALS['aisettings_options']['wpai_features_enabled'], 'a boolean is sanitised to a bool');
check('tags' === $GLOBALS['aisettings_options']['wpai_feature_content-classification_field_strategy'], 'a string is stored as submitted');
check(5 === $GLOBALS['aisettings_options']['wpai_feature_content-classification_field_max_suggestions'], 'an integer is stored as an int');

$result = $writer->save(
    array(
        'wpai_feature_type-ahead_field_delay'   => 100,
        'wpai_feature_type-ahead_field_max_words' => 999,
    )
);
check(2 === $result['updated'], 'unregistered options are written via their field kind');
check(200 === $GLOBALS['aisettings_options']['wpai_feature_type-ahead_field_delay'], 'a below-minimum integer is clamped up');
check(50 === $GLOBALS['aisettings_options']['wpai_feature_type-ahead_field_max_words'], 'an above-maximum integer is clamped down');

// --- Bulk switches. -----------------------------------------------------------------------------
$GLOBALS['aisettings_options'] = array();
$written                       = $writer->set_all_enabled(true);
check(5 === $written, 'every registered switch, including the master one, is flipped');
check(true === $GLOBALS['aisettings_options']['wpai_features_enabled'], 'the master switch is written');
check(
    !array_key_exists('wpai_feature_type-ahead_field_headings', $GLOBALS['aisettings_options']),
    'an unregistered toggle is left alone by the bulk switch'
);

// --- Bulk model overrides. ----------------------------------------------------------------------
$GLOBALS['aisettings_options'] = array();
$written                       = $writer->set_model_for_all('openai', 'gpt-x', false);
check(2 === $written, 'every feature with a provider/model override is written');
check(
    array('provider' => 'openai', 'model' => 'gpt-x') === $GLOBALS['aisettings_options']['wpai_feature_alt-text-generation_field_developer'],
    'the provider and model are stored on the override'
);

$GLOBALS['aisettings_options'] = array();
$written                       = $writer->set_model_for_all('openai', 'gpt-x', true);
check(1 === $written, 'with the running-only scope, only features that are running are written');
check(
    !array_key_exists('wpai_feature_content-classification_field_developer', $GLOBALS['aisettings_options']),
    'a feature that is not running is skipped by the running-only scope'
);

// --- Result. ------------------------------------------------------------------------------------
fwrite(STDOUT, sprintf("\n%d checks, %d failure(s)\n", $checks, $failures));

exit(0 === $failures ? 0 : 1);
