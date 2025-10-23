<?php

namespace PhpPgAdmin\Core;

use PhpPgAdmin\Misc;
use PhpPgAdmin\PluginManager;
use PhpPgAdmin\Database\Postgres;

/**
 * Simple singleton container to hold shared application objects during
 * the migration away from globals. Provides explicit getters to retain
 * IDE type support while we phase out globals.
 */
#[\AllowDynamicProperties]
class AppContainer
{

	/** @var ?AppContainer */
	private static $instance;

	/**
	 * @var string
	 */
	private $appName;

	/**
	 * @var string
	 */
	private $appVersion;

	/**
	 * @var bool
	 */
	private $skipHtmlFrame = false;

	/**
	 * @var bool
	 */
	private $shouldReloadTree = false;

	/**
	 * @var bool
	 */
	private $shouldReloadPage = false;

	/**
	 * @var string
	 */
	private $pgServerMinVersion;

	/**
	 * @var array
	 */
	private $conf;

	/**
	 * @var array
	 */
	private $lang;

	/**
	 * @var Misc
	 */
	private $misc;

	/**
	 * @var Postgres
	 */
	private $postgres;

	/**
	 * @var PluginManager
	 */
	private $pluginManager;


	/** Retrieve the singleton instance. */
	private static function instance(): AppContainer
	{
		if (self::$instance === null) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/** Store a value by key. */
	public static function set(string $key, $value): void
	{
		$container = self::instance();
		$container->{$key} = $value;
	}

	/** Retrieve a value or a default if absent. */
	public static function get(string $key, $default = null)
	{
		$container = self::instance();
		return $container->{$key} ?? $default;
	}

	/** Check whether a key exists in the container. */
	public static function has(string $key): bool
	{
		$container = self::instance();
		return property_exists($container, $key);
	}

	/** Explicit setters/getters for common dependencies */

	/**
	 * Config array by reference.
	 */
	public static function setConf(array &$conf): void
	{
		self::instance()->conf = &$conf;
	}

	/**
	 * Config array by reference.
	 */
	public static function &getConf(): array
	{
		return self::instance()->conf;
	}

	/**
	 * Language array.
	 */
	public static function setLang(array $lang): void
	{
		self::instance()->lang = $lang;
	}

	public static function getLang(): array
	{
		return self::instance()->lang;
	}

	public static function setMisc(Misc $misc): void
	{
		self::instance()->misc = $misc;
	}

	public static function getMisc(): Misc
	{
		return self::instance()->misc;
	}

	public static function setPostgres(Postgres $pg): void
	{
		self::instance()->postgres = $pg;
	}

	/**
	 * New Postgres class for modern code.
	 * @return Postgres
	 */
	public static function getPostgres(): ?Postgres
	{
		return self::instance()->postgres;
	}

	public static function setPluginManager(PluginManager $pluginManager): void
	{
		self::instance()->pluginManager = $pluginManager;
	}

	public static function getPluginManager(): PluginManager
	{
		return self::instance()->pluginManager;
	}

	/**
	 * @return string
	 */
	public static function getAppName(): string
	{
		return self::instance()->appName;
	}

	/**
	 * @param string $appName
	 */
	public static function setAppName(string $appName): void
	{
		self::instance()->appName = $appName;
	}

	/**
	 * @return string
	 */
	public static function getAppVersion(): string
	{
		return self::instance()->appVersion;
	}

	/**
	 * @param string $appVersion
	 */
	public static function setAppVersion(string $appVersion): void
	{
		self::instance()->appVersion = $appVersion;
	}

	/**
	 * @return string
	 */
	public static function getPgServerMinVersion(): string
	{
		return self::instance()->pgServerMinVersion;
	}

	/**
	 * @param string $pgServerMinVersion
	 */
	public static function setPgServerMinVersion(string $pgServerMinVersion): void
	{
		self::instance()->pgServerMinVersion = $pgServerMinVersion;
	}

	/**
	 * @return bool
	 */
	public static function isSkipHtmlFrame(): bool
	{
		return self::instance()->skipHtmlFrame;
	}

	/**
	 * @param bool $skipHtmlFrame
	 */
	public static function setSkipHtmlFrame(bool $skipHtmlFrame): void
	{
		self::instance()->skipHtmlFrame = $skipHtmlFrame;
	}

	/**
	 * Return whether the layout tree should be reloaded.
	 */
	public static function shouldReloadTree(): bool
	{
		return self::instance()->shouldReloadTree;
	}

	/**
	 * Mark whether the layout tree should be reloaded.
	 */
	public static function setShouldReloadTree(bool $should): void
	{
		self::instance()->shouldReloadTree = $should;
	}

	/**
	 * Return whether the page should be reloaded.
	 */
	public static function shouldReloadPage(): bool
	{
		return self::instance()->shouldReloadPage;
	}

	/**
	 * Mark whether the page should be reloaded.
	 */
	public static function setShouldReloadPage(bool $should): void
	{
		self::instance()->shouldReloadPage = $should;
	}
}
