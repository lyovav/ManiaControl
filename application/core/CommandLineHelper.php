<?php

namespace ManiaControl;

/**
 * Command Line Helper Class
 * 
 * @author steeffeen & kremsy
 * @copyright ManiaControl Copyright © 2014 ManiaControl Team
 * @license http://www.gnu.org/licenses/ GNU General Public License, Version 3
 */
class CommandLineHelper {

	/**
	 * Get the Command Line Parameter with the given Name
	 * 
	 * @param string $paramName
	 * @return mixed
	 */
	public static function getParameter($paramName) {
		global $argv;
		$paramName = (string) $paramName;
		foreach ($argv as $arg) {
			$parts = explode('=', $arg, 2);
			if (count($parts) < 2) {
				continue;
			}
			if ($parts[0] !== $paramName) {
				continue;
			}
			return $parts[1];
		}
		return null;
	}
}
