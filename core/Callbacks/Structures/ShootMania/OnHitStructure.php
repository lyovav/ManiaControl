<?php

namespace ManiaControl\Callbacks\Structures\ShootMania;


use ManiaControl\ManiaControl;

/**
 * Structure Class for the OnHit Structure Callback
 *
 * @author    ManiaControl Team <mail@maniacontrol.com>
 * @copyright 2014-2017 ManiaControl Team
 * @license   http://www.gnu.org/licenses/ GNU General Public License, Version 3
 */
class OnHitStructure extends OnHitNearMissArmorEmptyBaseStructure {
	private $damage;


	public function __construct(ManiaControl $maniaControl, array $data) {
		parent::__construct($maniaControl, $data);

		$this->damage = $this->getPlainJsonObject()->damage;
	}

	/**
	 * < Amount of Damage done by the hit (only on onHit)
	 *
	 * @return int
	 */
	public function getDamage() {
		return $this->damage;
	}
}