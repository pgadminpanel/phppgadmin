<?php


namespace PhpPgAdmin\Core;


use PhpPgAdmin\Misc;
use PhpPgAdmin\PluginManager;
use PhpPgAdmin\Database\Postgres;

abstract class AppContext
{

	protected function lang(): array
	{
		return AppContainer::getLang();
	}

	protected function conf(): array
	{
		return AppContainer::getConf();
	}

	protected function postgres(): ?Postgres
	{
		return AppContainer::getPostgres();
	}

	protected function misc(): Misc
	{
		return AppContainer::getMisc();
	}

	protected function pluginManager(): PluginManager
	{
		return AppContainer::getPluginManager();
	}

}