<?php

/**
 * Plugin bootstrap.
 *
 * @package AISettings
 */

declare(strict_types=1);

namespace AISettings;

use AISettings\Admin\Import_Export;
use AISettings\Admin\Settings_Page;
use AISettings\Config\Collector;

/**
 * Wires the plugin up.
 *
 * The AI plugin keeps its feature registry in a local variable and exposes it only through the
 * `wpai_register_features` action, so it is captured here and handed to the collector.
 */
final class Plugin
{
    /**
     * The single instance.
     *
     * @var Plugin|null
     */
    private static ?Plugin $instance = null;

    /**
     * The AI plugin's feature registry, when the action fired.
     *
     * Intentionally untyped: the class belongs to the AI plugin.
     *
     * @var object|null
     */
    private $registry = null;

    /**
     * The configuration collector.
     *
     * @var Collector|null
     */
    private ?Collector $collector = null;

    /**
     * Constructor.
     */
    private function __construct()
    {
    }

    /**
     * Gets the single instance.
     *
     * @return Plugin The instance.
     */
    public static function get_instance(): Plugin
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Registers the plugin's hooks.
     *
     * @return void
     */
    public function setup(): void
    {
        add_action('wpai_register_features', array($this, 'capture_registry'));

        (new Settings_Page($this))->register();
        (new Import_Export())->register();
    }

    /**
     * Captures the AI plugin's feature registry.
     *
     * The parameter is intentionally untyped and unchecked beyond its shape: this plugin supports
     * any AI plugin version that fires the action, including ones where the registry class has been
     * renamed.
     *
     * @param mixed $registry The registry the AI plugin passes to the action.
     * @return void
     */
    public function capture_registry($registry): void
    {
        if (is_object($registry) && method_exists($registry, 'get_all_features')) {
            $this->registry  = $registry;
            $this->collector = null;
        }
    }

    /**
     * Gets the configuration collector.
     *
     * Built lazily so it sees the registry captured on `init`; the collector is first needed on
     * `admin_init`, which runs well after that.
     *
     * @return Collector The collector.
     */
    public function collector(): Collector
    {
        if (null === $this->collector) {
            $this->collector = new Collector($this->registry);
        }

        return $this->collector;
    }
}
