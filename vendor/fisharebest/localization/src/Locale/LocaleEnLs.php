<?php namespace Fisharebest\Localization;

/**
 * Class LocaleEnLs
 *
 * @author        Greg Roach <fisharebest@gmail.com>
 * @copyright (c) 2015 Greg Roach
 * @license       GPLv3+
 */
class LocaleEnLs extends LocaleEn {
	/** {@inheritdoc} */
	public function territory() {
		return new TerritoryLs;
	}
}