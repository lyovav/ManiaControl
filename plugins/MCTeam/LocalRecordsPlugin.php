<?php

namespace MCTeam;

use FML\Controls\Frame;
use FML\Controls\Label;
use FML\Controls\Quad;
use FML\Controls\Quads\Quad_BgsPlayerCard;
use FML\ManiaLink;
use FML\Script\Features\Paging;
use ManiaControl\Admin\AuthenticationManager;
use ManiaControl\Callbacks\CallbackListener;
use ManiaControl\Callbacks\CallbackManager;
use ManiaControl\Callbacks\Callbacks;
use ManiaControl\Callbacks\Structures\TrackMania\OnWayPointEventStructure;
use ManiaControl\Callbacks\TimerListener;
use ManiaControl\Commands\CommandListener;
use ManiaControl\Logger;
use ManiaControl\ManiaControl;
use ManiaControl\Manialinks\LabelLine;
use ManiaControl\Manialinks\ManialinkManager;
use ManiaControl\Maps\Map;
use ManiaControl\Players\Player;
use ManiaControl\Players\PlayerManager;
use ManiaControl\Plugins\Plugin;
use ManiaControl\Settings\Setting;
use ManiaControl\Settings\SettingManager;
use ManiaControl\Utils\Formatter;

/**
 * ManiaControl Local Records Plugin
 *
 * @author    ManiaControl Team <mail@maniacontrol.com>
 * @copyright 2014-2017 ManiaControl Team
 * @license   http://www.gnu.org/licenses/ GNU General Public License, Version 3
 */
class LocalRecordsPlugin implements CallbackListener, CommandListener, TimerListener, Plugin {
	/*
	 * Constants
	 */
	const ID                          = 7;
	const VERSION                     = 0.3;
	const NAME                        = 'Local Records Plugin';
	const AUTHOR                      = 'MCTeam';
	const MLID_RECORDS                = 'ml_local_records';
	const TABLE_RECORDS               = 'mc_localrecords';
	const SETTING_WIDGET_TITLE        = 'Widget Title';
	const SETTING_WIDGET_POSX         = 'Widget Position: X';
	const SETTING_WIDGET_POSY         = 'Widget Position: Y';
	const SETTING_WIDGET_WIDTH        = 'Widget Width';
	const SETTING_WIDGET_LINESCOUNT   = 'Widget Displayed Lines Count';
	const SETTING_WIDGET_LINEHEIGHT   = 'Widget Line Height';
	const SETTING_WIDGET_ENABLE       = 'Enable Local Records Widget';
	const SETTING_NOTIFY_ONLY_DRIVER  = 'Notify only the Driver on New Records';
	const SETTING_NOTIFY_BEST_RECORDS = 'Notify Publicly only for the X Best Records';
	const SETTING_ADJUST_OUTER_BORDER = 'Adjust outer Border to Number of actual Records';
	const CB_LOCALRECORDS_CHANGED     = 'LocalRecords.Changed';
	const ACTION_SHOW_RECORDSLIST     = 'LocalRecords.ShowRecordsList';

	/*
	 * Private properties
	 */
	/** @var ManiaControl $maniaControl */
	private $maniaControl    = null;
	private $updateManialink = false;
	private $checkpoints     = array();

	/**
	 * @see \ManiaControl\Plugins\Plugin::prepare()
	 */
	public static function prepare(ManiaControl $maniaControl) {
	}

	/**
	 * @see \ManiaControl\Plugins\Plugin::getId()
	 */
	public static function getId() {
		return self::ID;
	}

	/**
	 * @see \ManiaControl\Plugins\Plugin::getName()
	 */
	public static function getName() {
		return self::NAME;
	}

	/**
	 * @see \ManiaControl\Plugins\Plugin::getVersion()
	 */
	public static function getVersion() {
		return self::VERSION;
	}

	/**
	 * @see \ManiaControl\Plugins\Plugin::getAuthor()
	 */
	public static function getAuthor() {
		return self::AUTHOR;
	}

	/**
	 * @see \ManiaControl\Plugins\Plugin::getDescription()
	 */
	public static function getDescription() {
		return 'Plugin offering tracking of local records and manialinks to display them.';
	}

	/**
	 * @see \ManiaControl\Plugins\Plugin::load()
	 * @param \ManiaControl\ManiaControl $maniaControl
	 * @return bool
	 */
	public function load(ManiaControl $maniaControl) {
		$this->maniaControl = $maniaControl;
		$this->initTables();

		// Settings
		$this->maniaControl->getSettingManager()->initSetting($this, self::SETTING_WIDGET_TITLE, 'Local Records');
		$this->maniaControl->getSettingManager()->initSetting($this, self::SETTING_WIDGET_POSX, -139.);
		$this->maniaControl->getSettingManager()->initSetting($this, self::SETTING_WIDGET_POSY, 75);
		$this->maniaControl->getSettingManager()->initSetting($this, self::SETTING_WIDGET_WIDTH, 40.);
		$this->maniaControl->getSettingManager()->initSetting($this, self::SETTING_WIDGET_LINESCOUNT, 15);
		$this->maniaControl->getSettingManager()->initSetting($this, self::SETTING_WIDGET_LINEHEIGHT, 4.);
		$this->maniaControl->getSettingManager()->initSetting($this, self::SETTING_WIDGET_ENABLE, true);
		$this->maniaControl->getSettingManager()->initSetting($this, self::SETTING_NOTIFY_ONLY_DRIVER, false);
		$this->maniaControl->getSettingManager()->initSetting($this, self::SETTING_NOTIFY_BEST_RECORDS, 10);
		$this->maniaControl->getSettingManager()->initSetting($this, self::SETTING_ADJUST_OUTER_BORDER, false);

		// Callbacks
		$this->maniaControl->getTimerManager()->registerTimerListening($this, 'handle1Second', 1000);

		$this->maniaControl->getCallbackManager()->registerCallbackListener(Callbacks::AFTERINIT, $this, 'handleAfterInit');
		$this->maniaControl->getCallbackManager()->registerCallbackListener(Callbacks::BEGINMAP, $this, 'handleMapBegin');
		$this->maniaControl->getCallbackManager()->registerCallbackListener(SettingManager::CB_SETTING_CHANGED, $this, 'handleSettingChanged');
		$this->maniaControl->getCallbackManager()->registerCallbackListener(CallbackManager::CB_MP_PLAYERMANIALINKPAGEANSWER, $this, 'handleManialinkPageAnswer');

		$this->maniaControl->getCallbackManager()->registerCallbackListener(PlayerManager::CB_PLAYERCONNECT, $this, 'handlePlayerConnect');

		$this->maniaControl->getCallbackManager()->registerCallbackListener(Callbacks::TM_ONWAYPOINT, $this, 'handleCheckpointCallback');
		$this->maniaControl->getCallbackManager()->registerCallbackListener(Callbacks::TM_ONFINISHLINE, $this, 'handleFinishCallback');
		$this->maniaControl->getCallbackManager()->registerCallbackListener(Callbacks::TM_ONLAPFINISH, $this, 'handleFinishCallback');

		$this->maniaControl->getCommandManager()->registerCommandListener(array('recs', 'records'), $this, 'showRecordsList', false, 'Shows a list of Local Records on the current map.');
		$this->maniaControl->getCommandManager()->registerCommandListener('delrec', $this, 'deleteRecord', true, 'Removes a record from the database.');

		$this->updateManialink = true;

		return true;
	}

	/**
	 * Initialize needed database tables
	 */
	private function initTables() {
		$mysqli = $this->maniaControl->getDatabase()->getMysqli();
		$query  = "CREATE TABLE IF NOT EXISTS `" . self::TABLE_RECORDS . "` (
				`index` int(11) NOT NULL AUTO_INCREMENT,
				`mapIndex` int(11) NOT NULL,
				`playerIndex` int(11) NOT NULL,
				`time` int(11) NOT NULL,
				`changed` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (`index`),
				UNIQUE KEY `player_map_record` (`mapIndex`,`playerIndex`)
				) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci AUTO_INCREMENT=1;";
		$mysqli->query($query);
		if ($mysqli->error) {
			trigger_error($mysqli->error, E_USER_ERROR);
		}

		$mysqli->query("ALTER TABLE `" . self::TABLE_RECORDS . "` ADD `checkpoints` TEXT CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL");
		if ($mysqli->error) {
			if (!strstr($mysqli->error, 'Duplicate')) {
				trigger_error($mysqli->error, E_USER_ERROR);
			}
		}
	}

	/**
	 * @see \ManiaControl\Plugins\Plugin::unload()
	 */
	public function unload() {
		$this->maniaControl->getManialinkManager()->hideManialink(self::MLID_RECORDS);
	}

	/**
	 * Handle ManiaControl After Init
	 */
	public function handleAfterInit() {
		$this->updateManialink = true;
	}

	/**
	 * Handle 1 Second Callback
	 */
	public function handle1Second() {
		if (!$this->updateManialink) {
			return;
		}

		$this->updateManialink = false;
		if ($this->maniaControl->getSettingManager()->getSettingValue($this, self::SETTING_WIDGET_ENABLE)) {
			$manialink = $this->buildManialink();
			$this->maniaControl->getManialinkManager()->sendManialink($manialink);
		}
	}

	/**
	 * Build the local records manialink
	 *
	 * @return string
	 */
	private function buildManialink() {
		$map = $this->maniaControl->getMapManager()->getCurrentMap();
		if (!$map) {
			return null;
		}

		$title        = $this->maniaControl->getSettingManager()->getSettingValue($this, self::SETTING_WIDGET_TITLE);
		$posX         = $this->maniaControl->getSettingManager()->getSettingValue($this, self::SETTING_WIDGET_POSX);
		$posY         = $this->maniaControl->getSettingManager()->getSettingValue($this, self::SETTING_WIDGET_POSY);
		$width        = $this->maniaControl->getSettingManager()->getSettingValue($this, self::SETTING_WIDGET_WIDTH);
		$lines        = $this->maniaControl->getSettingManager()->getSettingValue($this, self::SETTING_WIDGET_LINESCOUNT);
		$lineHeight   = $this->maniaControl->getSettingManager()->getSettingValue($this, self::SETTING_WIDGET_LINEHEIGHT);
		$labelStyle   = $this->maniaControl->getManialinkManager()->getStyleManager()->getDefaultLabelStyle();
		$quadStyle    = $this->maniaControl->getManialinkManager()->getStyleManager()->getDefaultQuadStyle();
		$quadSubstyle = $this->maniaControl->getManialinkManager()->getStyleManager()->getDefaultQuadSubstyle();

		$records = $this->getLocalRecords($map);
		if (!is_array($records)) {
			Logger::logError("Couldn't fetch player records.");
			return null;
		}

		$manialink = new ManiaLink(self::MLID_RECORDS);
		$frame     = new Frame();
		$manialink->addChild($frame);
		$frame->setPosition($posX, $posY);

		$backgroundQuad = new Quad();
		$frame->addChild($backgroundQuad);
		$backgroundQuad->setVerticalAlign($backgroundQuad::TOP);
		$adjustOuterBorder = $this->maniaControl->getSettingManager()->getSettingValue($this, self::SETTING_ADJUST_OUTER_BORDER);
		$height            = 7. + ($adjustOuterBorder ? count($records) : $lines) * $lineHeight;
		$backgroundQuad->setSize($width * 1.05, $height);
		$backgroundQuad->setStyles($quadStyle, $quadSubstyle);
		$backgroundQuad->setAction(self::ACTION_SHOW_RECORDSLIST);

		$titleLabel = new Label();
		$frame->addChild($titleLabel);
		$titleLabel->setPosition(0, $lineHeight * -0.9);
		$titleLabel->setWidth($width);
		$titleLabel->setStyle($labelStyle);
		$titleLabel->setTextSize(2);
		$titleLabel->setText($title);
		$titleLabel->setTranslate(true);

		// Times
		foreach ($records as $index => $record) {
			if ($index >= $lines) {
				break;
			}

			$y = -8. - $index * $lineHeight;

			$recordFrame = new Frame();
			$frame->addChild($recordFrame);
			$recordFrame->setPosition(0, $y);

			/*
			 * $backgroundQuad = new Quad(); $recordFrame->addChild($backgroundQuad); $backgroundQuad->setSize($width * 1.04, $lineHeight * 1.4); $backgroundQuad->setStyles($quadStyle, $quadSubstyle);
			 */

			$rankLabel = new Label();
			$recordFrame->addChild($rankLabel);
			$rankLabel->setHorizontalAlign($rankLabel::LEFT);
			$rankLabel->setX($width * -0.47);
			$rankLabel->setSize($width * 0.06, $lineHeight);
			$rankLabel->setTextSize(1);
			$rankLabel->setTextPrefix('$o');
			$rankLabel->setText($record->rank);
			$rankLabel->setTextEmboss(true);

			$nameLabel = new Label();
			$recordFrame->addChild($nameLabel);
			$nameLabel->setHorizontalAlign($nameLabel::LEFT);
			$nameLabel->setX($width * -0.4);
			$nameLabel->setSize($width * 0.6, $lineHeight);
			$nameLabel->setTextSize(1);
			$nameLabel->setText($record->nickname);
			$nameLabel->setTextEmboss(true);

			$timeLabel = new Label();
			$recordFrame->addChild($timeLabel);
			$timeLabel->setHorizontalAlign($timeLabel::RIGHT);
			$timeLabel->setX($width * 0.47);
			$timeLabel->setSize($width * 0.25, $lineHeight);
			$timeLabel->setTextSize(1);
			$timeLabel->setText(Formatter::formatTime($record->time));
			$timeLabel->setTextEmboss(true);
		}

		return $manialink;
	}

	/**
	 * Fetch local records for the given map
	 *
	 * @param Map $map
	 * @param int $limit
	 * @return array
	 */
	public function getLocalRecords(Map $map, $limit = -1) {
		$mysqli = $this->maniaControl->getDatabase()->getMysqli();
		$limit  = ($limit > 0 ? 'LIMIT ' . $limit : '');
		$query  = "SELECT * FROM (
					SELECT recs.*, @rank := @rank + 1 as `rank` FROM `" . self::TABLE_RECORDS . "` recs, (SELECT @rank := 0) ra
					WHERE recs.`mapIndex` = {$map->index}
					ORDER BY recs.`time` ASC
					{$limit}) records
				LEFT JOIN `" . PlayerManager::TABLE_PLAYERS . "` players
				ON records.`playerIndex` = players.`index`;";
		$result = $mysqli->query($query);
		if ($mysqli->error) {
			trigger_error($mysqli->error);
			return null;
		}
		$records = array();
		while ($record = $result->fetch_object()) {
			array_push($records, $record);
		}
		$result->free();
		return $records;
	}

	/**
	 * Handle Setting Changed Callback
	 *
	 * @param Setting $setting
	 */
	public function handleSettingChanged(Setting $setting) {
		if (!$setting->belongsToClass($this)) {
			return;
		}

		switch ($setting->setting) {
			case self::SETTING_WIDGET_ENABLE: {
				if ($setting->value) {
					$this->updateManialink = true;
				} else {
					$this->maniaControl->getManialinkManager()->hideManialink(self::MLID_RECORDS);
				}
				break;
			}
			default:
				$this->updateManialink = true;
				break;
		}
	}

	/**
	 * Handle Checkpoint Callback
	 *
	 * @param RecordCallback $callback
	 */
	public function handleCheckpointCallback(OnWayPointEventStructure $structure) {
		$playerLogin = $structure->getPlayer()->login;
		if (!isset($this->checkpoints[$playerLogin])) {
			$this->checkpoints[$playerLogin] = array();
		}
		$this->checkpoints[$playerLogin][$structure->getCheckPointInLap()] = $structure->getLapTime();
	}

	/**
	 * Handle Finish Callback
	 *
	 * @param RecordCallback $callback
	 */
	public function handleFinishCallback(OnWayPointEventStructure $structure) {
		if ($structure->getRaceTime() <= 0) {
			// Invalid time
			return;
		}

		$map = $this->maniaControl->getMapManager()->getCurrentMap();

		$player = $structure->getPlayer();

		$checkpointsString                 = $this->getCheckpoints($player->login);
		$this->checkpoints[$player->login] = array();

		// Check old record of the player
		$oldRecord = $this->getLocalRecord($map, $player);
		if ($oldRecord) {
			if ($oldRecord->time < $structure->getRaceTime()) {
				// Not improved
				return;
			}
			if ($oldRecord->time == $structure->getRaceTime()) {
				// Same time
				// TODO: respect notify-settings
				$message = '$<$fff' . $player->nickname . '$> equalized the $<$ff0' . $oldRecord->rank . '.$> Local Record: $<$fff' . Formatter::formatTime($oldRecord->time) . '$>!';
				$this->maniaControl->getChat()->sendInformation('$3c0' . $message);
				return;
			}
		}

		// Save time
		$mysqli = $this->maniaControl->getDatabase()->getMysqli();
		$query  = "INSERT INTO `" . self::TABLE_RECORDS . "` (
				`mapIndex`,
				`playerIndex`,
				`time`,
				`checkpoints`
				) VALUES (
				{$map->index},
				{$player->index},
				{$structure->getRaceTime()},
				'{$checkpointsString}'
				) ON DUPLICATE KEY UPDATE
				`time` = VALUES(`time`),
				`checkpoints` = VALUES(`checkpoints`);";
		$mysqli->query($query);
		if ($mysqli->error) {
			trigger_error($mysqli->error);
			return;
		}
		$this->updateManialink = true;

		// Announce record
		$newRecord    = $this->getLocalRecord($map, $player);
		$improvedRank = (!$oldRecord || $newRecord->rank < $oldRecord->rank);

		$notifyOnlyDriver      = $this->maniaControl->getSettingManager()->getSettingValue($this, self::SETTING_NOTIFY_ONLY_DRIVER);
		$notifyOnlyBestRecords = $this->maniaControl->getSettingManager()->getSettingValue($this, self::SETTING_NOTIFY_BEST_RECORDS);

		$message = '$3c0';
		if ($notifyOnlyDriver) {
			$message .= 'You';
		} else {
			$message .= '$<$fff' . $player->nickname . '$>';
		}
		$message .= ' ' . ($improvedRank ? 'gained' : 'improved') . ' the';
		$message .= ' $<$ff0' . $newRecord->rank . '.$> Local Record:';
		$message .= ' $<$fff' . Formatter::formatTime($newRecord->time) . '$>!';
		if ($oldRecord) {
			$message .= ' (';
			if ($improvedRank) {
				$message .= '$<$ff0' . $oldRecord->rank . '.$> ';
			}
			$timeDiff = $oldRecord->time - $newRecord->time;
			$message  .= '$<$fff-' . Formatter::formatTime($timeDiff) . '$>)';
		}

		if ($notifyOnlyDriver) {
			$this->maniaControl->getChat()->sendInformation($message, $player);
		} else if (!$notifyOnlyBestRecords || $newRecord->rank <= $notifyOnlyBestRecords) {
			$this->maniaControl->getChat()->sendInformation($message);
		}

		$this->maniaControl->getCallbackManager()->triggerCallback(self::CB_LOCALRECORDS_CHANGED, $newRecord);
	}

	/**
	 * Get current checkpoint string for dedimania record
	 *
	 * @param string $login
	 * @return string
	 */
	private function getCheckpoints($login) {
		if (!$login || !isset($this->checkpoints[$login])) {
			return null;
		}
		$string = '';
		$count  = count($this->checkpoints[$login]);
		foreach ($this->checkpoints[$login] as $index => $check) {
			$string .= $check;
			if ($index < $count - 1) {
				$string .= ',';
			}
		}
		return $string;
	}

	/**
	 * Retrieve the local record for the given map and login
	 *
	 * @param Map    $map
	 * @param Player $player
	 * @return mixed
	 */
	private function getLocalRecord(Map $map, Player $player) {
		$mysqli = $this->maniaControl->getDatabase()->getMysqli();
		$query  = "SELECT records.* FROM (
					SELECT recs.*, @rank := @rank + 1 as `rank` FROM `" . self::TABLE_RECORDS . "` recs, (SELECT @rank := 0) ra
					WHERE recs.`mapIndex` = {$map->index}
					ORDER BY recs.`time` ASC) records
				WHERE records.`playerIndex` = {$player->index};";
		$result = $mysqli->query($query);
		if ($mysqli->error) {
			trigger_error("Couldn't retrieve player record for '{$player->login}'." . $mysqli->error);
			return null;
		}
		$record = $result->fetch_object();
		$result->free();
		return $record;
	}

	/**
	 * Handle Player Connect Callback
	 */
	public function handlePlayerConnect() {
		$this->updateManialink = true;
	}

	/**
	 * Handle Begin Map Callback
	 */
	public function handleMapBegin() {
		$this->updateManialink = true;
	}

	/**
	 * Handle PlayerManialinkPageAnswer callback
	 *
	 * @param array $callback
	 */
	public function handleManialinkPageAnswer(array $callback) {
		$actionId = $callback[1][2];

		$login  = $callback[1][1];
		$player = $this->maniaControl->getPlayerManager()->getPlayer($login);

		if ($actionId === self::ACTION_SHOW_RECORDSLIST) {
			$this->showRecordsList(array(), $player);
		}
	}

	/**
	 * Shows a ManiaLink list with the local records.
	 *
	 * @param array  $chat
	 * @param Player $player
	 */
	public function showRecordsList(array $chat, Player $player) {
		$width  = $this->maniaControl->getManialinkManager()->getStyleManager()->getListWidgetsWidth();
		$height = $this->maniaControl->getManialinkManager()->getStyleManager()->getListWidgetsHeight();

		// get PlayerList
		$records = $this->getLocalRecords($this->maniaControl->getMapManager()->getCurrentMap());

		// create manialink
		$maniaLink = new ManiaLink(ManialinkManager::MAIN_MLID);
		$script    = $maniaLink->getScript();
		$paging    = new Paging();
		$script->addFeature($paging);

		// Main frame
		$frame = $this->maniaControl->getManialinkManager()->getStyleManager()->getDefaultListFrame($script, $paging);
		$maniaLink->addChild($frame);

		// Start offsets
		$posX = -$width / 2;
		$posY = $height / 2;

		// Predefine Description Label
		$descriptionLabel = $this->maniaControl->getManialinkManager()->getStyleManager()->getDefaultDescriptionLabel();
		$frame->addChild($descriptionLabel);

		// Headline
		$headFrame = new Frame();
		$frame->addChild($headFrame);
		$headFrame->setY($posY - 5);

		$labelLine = new LabelLine($headFrame);
		$labelLine->addLabelEntryText('Rank',$posX + 5);
		$labelLine->addLabelEntryText('Nickname',$posX + 18);
		$labelLine->addLabelEntryText('Login',$posX + 70);
		$labelLine->addLabelEntryText('Time', $posX + 101);
		$labelLine->render();

		$index     = 0;
		$posY      = $height / 2 - 10;
		$pageFrame = null;

		foreach ($records as $listRecord) {
			if ($index % 15 === 0) {
				$pageFrame = new Frame();
				$frame->addChild($pageFrame);
				$posY = $height / 2 - 10;
				$paging->addPageControl($pageFrame);
			}

			$recordFrame = new Frame();
			$pageFrame->addChild($recordFrame);

			if ($index % 2 === 0) {
				$lineQuad = new Quad_BgsPlayerCard();
				$recordFrame->addChild($lineQuad);
				$lineQuad->setSize($width, 4);
				$lineQuad->setSubStyle($lineQuad::SUBSTYLE_BgPlayerCardBig);
				$lineQuad->setZ(-0.001);
			}

			if (strlen($listRecord->nickname) < 2) {
				$listRecord->nickname = $listRecord->login;
			}

			$labelLine = new LabelLine($recordFrame);
			$labelLine->addLabelEntryText($listRecord->rank,$posX + 5, 13);
			$labelLine->addLabelEntryText('$fff' . $listRecord->nickname,$posX + 18, 52);
			$labelLine->addLabelEntryText($listRecord->login,$posX + 70,31);
			$labelLine->addLabelEntryText(Formatter::formatTime($listRecord->time),$posX + 101, $width / 2 - ($posX + 110));
			$labelLine->render();

			$recordFrame->setY($posY);

			$posY -= 4;
			$index++;
		}

		// Render and display xml
		$this->maniaControl->getManialinkManager()->displayWidget($maniaLink, $player, 'LocalRecords');
	}

	/**
	 * Delete a Player's record
	 *
	 * @param array  $chat
	 * @param Player $player
	 */
	public function deleteRecord(array $chat, Player $player) {
		if (!$this->maniaControl->getAuthenticationManager()->checkRight($player, AuthenticationManager::AUTH_LEVEL_MASTERADMIN)) {
			$this->maniaControl->getAuthenticationManager()->sendNotAllowed($player);
			return;
		}

		$commandParts = explode(' ', $chat[1][2]);
		if (count($commandParts) < 2) {
			$this->maniaControl->getChat()->sendUsageInfo('Missing Record ID! (Example: //delrec 3)', $player);
			return;
		}

		$recordId   = (int) $commandParts[1];
		$currentMap = $this->maniaControl->getMapManager()->getCurrentMap();
		$records    = $this->getLocalRecords($currentMap);
		if (count($records) < $recordId) {
			$this->maniaControl->getChat()->sendError('Cannot remove record $<$fff' . $recordId . '$>!', $player);
			return;
		}

		$mysqli = $this->maniaControl->getDatabase()->getMysqli();
		$query  = "DELETE FROM `" . self::TABLE_RECORDS . "`
				WHERE `mapIndex` = {$currentMap->index}
				AND `playerIndex` = {$player->index};";
		$mysqli->query($query);
		if ($mysqli->error) {
			trigger_error($mysqli->error);
			return;
		}

		$this->maniaControl->getCallbackManager()->triggerCallback(self::CB_LOCALRECORDS_CHANGED, null);
		$this->maniaControl->getChat()->sendInformation('Record no. $<$fff' . $recordId . '$> has been removed!');
	}
}
