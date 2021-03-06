<?php
namespace Fisharebest\ExtCalendar;

use InvalidArgumentException;

/**
 * Class ArabicCalendar - calculations for the Arabic (Hijri) calendar.
 *
 * @author    Greg Roach <fisharebest@gmail.com>
 * @copyright (c) 2014-2017 Greg Roach
 * @license   This program is free software: you can redistribute it and/or modify
 *            it under the terms of the GNU General Public License as published by
 *            the Free Software Foundation, either version 3 of the License, or
 *            (at your option) any later version.
 *
 *            This program is distributed in the hope that it will be useful,
 *            but WITHOUT ANY WARRANTY; without even the implied warranty of
 *            MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *            GNU General Public License for more details.
 *
 *            You should have received a copy of the GNU General Public License
 *            along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */
class ArabicCalendar implements CalendarInterface {
	public function daysInMonth($year, $month) {
		if ($month === 2) {
			return 28;
		} elseif ($month % 2 === 1 || $month === 12 && $this->isLeapYear($year)) {
			return 30;
		} else {
			return 29;
		}
	}

	public function daysInWeek() {
		return 7;
	}

	public function gedcomCalendarEscape() {
		return '@#DHIJRI@';
	}

	public function isLeapYear($year) {
		return ((11 * $year + 14) % 30) < 11;
	}

	public function jdEnd() {
		return PHP_INT_MAX;
	}

	public function jdStart() {
		return 1948440; // 1 Muharram 1 AH, 16 July 622 AD
	}

	public function jdToYmd($julian_day) {
		$year  = (int) ((30 * ($julian_day - 1948440) + 10646) / 10631);
		$month = (int) ((11 * ($julian_day - $year * 354 - (int) ((3 + 11 * $year) / 30) - 1948086) + 330) / 325);
		$day   = $julian_day - 29 * ($month - 1) - (int) ((6 * $month - 1) / 11) - $year * 354 - (int) ((3 + 11 * $year) / 30) - 1948085;

		return array($year, $month, $day);
	}

	public function monthsInYear() {
		return 12;
	}

	public function ymdToJd($year, $month, $day) {
		if ($month < 1 || $month > $this->monthsInYear()) {
			throw new InvalidArgumentException('Month ' . $month . ' is invalid for this calendar');
		}

		return $day + 29 * ($month - 1) + (int) ((6 * $month - 1) / 11) + $year * 354 + (int) ((3 + 11 * $year) / 30) + 1948085;
	}

	/**
	 * The algorithm used above require a mod() function that always returns a positive
	 * modules.  The native PHP function returns a negative modulus for a negative dividend.
	 *
	 * @param number $dividend
	 * @param number $divisor
	 * @return number
	 */
	public static function mod($dividend, $divisor) {
		if ($divisor === 0) return 0;

		$modulus = $dividend % $divisor;
		if($modulus < 0) {
			$modulus += $divisor;
		}

		return $modulus;
	}
}
