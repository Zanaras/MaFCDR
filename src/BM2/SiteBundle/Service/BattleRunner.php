<?php

namespace BM2\SiteBundle\Service;

use BM2\SiteBundle\Entity\Battle;
use BM2\SiteBundle\Entity\BattleGroup;
use BM2\SiteBundle\Entity\BattleParticipant;
use BM2\SiteBundle\Entity\BattleReport;
use BM2\SiteBundle\Entity\BattleReportGroup;
use BM2\SiteBundle\Entity\BattleReportStage;
use BM2\SiteBundle\Entity\BattleReportCharacter;
use BM2\SiteBundle\Entity\Soldier;
use BM2\SiteBundle\Entity\Character;
use BM2\SiteBundle\Entity\Action;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManager;
use Monolog\Logger;


class BattleRunner {

	# Symfony Service variables.
	private $em;
	private $logger;
	private $history;
	private $geo;
	private $character_manager;
	private $npc_manager;
	private $interactions;
	private $warman;

	# The following variables are used all over this class, in multiple functions, sometimes as far as 4 or 5 functions deep.
	private $battle;
	private $regionType;
	private $xpMod;
	private $debug=0;

	private $siegeFinale;
	private $defMinContacts;
	private $defUsedContacts = 0;
	private $defCurrentContacts = 0;
	private $defSlain;
	private $attMinContacts;
	private $attUsedContacts = 0;
	private $attCurrentContacts = 0;
	private $attSlain;

	private $report;
	private $nobility;
	private $battlesize=1;
	private $defenseBonus=0;


	public function __construct(EntityManager $em, Logger $logger, History $history, Geography $geo, CharacterManager $character_manager, NpcManager $npc_manager, Interactions $interactions, WarManager $war_manager) {
		$this->em = $em;
		$this->logger = $logger;
		$this->history = $history;
		$this->geo = $geo;
		$this->character_manager = $character_manager;
		$this->npc_manager = $npc_manager;
		$this->interactions = $interactions;
		$this->war_manager = $war_manager;
	}

	public function enableLog($level=9999) {
		$this->debug=$level;
	}
	public function disableLog() {
		$this->debug=0;
	}

	public function getLastReport() {
		return $this->report;
	}

	public function run(Battle $battle, $cycle) {
		$this->battle = $battle;
		$this->log(1, "Battle ".$battle->getId()."\n");

		$siege = $battle->getIsSiege();
		$assault = false;
		$sortie = false;
		$some_inside = false;
		$some_outside = false;
		$char_count = 0;
		$slumberers = 0;

		foreach ($battle->getGroups() as $group) {
			foreach ($group->getCharacters() as $char) {
				if ($char->getSlumbering() == true) {
					$slumberers++;
				}
				$char_count++;
			}
		}
		$this->log(15, "Found ".$char_count." characters and ".$slumberers." slumberers\n");
		$xpRatio = $slumberers/$char_count;
		if ($xpRatio < 0.1) {
			$xpMod = 1;
		} else if ($xpRatio < 0.2) {
			$xpMod = 0.5;
		} else if ($xpRatio < 0.3) {
			$xpMod = 0.2;
		} else if ($xpRatio < 0.5) {
			$xpMod = 0.1;
		} else {
			$xpMod = 0;
		}
		$this->xpMod = $xpMod;
		$this->log(15, "XP modifier set to ".$xpMod." with ".$char_count." characters and ".$slumberers." slumberers\n");

		$this->report = new BattleReport;
		$assault = false;
		$this->report->setAssault(FALSE);
		$this->report->setSortie(FALSE);
		$this->report->setUrban(FALSE);
		$myStage = NULL;
		$maxStage = NULL;
		switch ($battle->getType()) {
			case 'siegesortie':
				$this->report->setSortie(TRUE);
				$myStage = $battle->getSiege()->getStage();
				$maxStage = $battle->getSiege()->getMaxStage();
				if ($myStage > 1) {
					$location = array('key'=>'battle.location.sortie', 'id'=>$battle->getSettlement()->getId(), 'name'=>$battle->getSettlement()->getName());
				} else {
					$location = array('key'=>'battle.location.of', 'id'=>$battle->getSettlement()->getId(), 'name'=>$battle->getSettlement()->getName());
				}
				$this->siegeFinale = FALSE;
				break;
			case 'siegeassault':
				$this->report->setAssault(TRUE);
				$myStage = $battle->getSiege()->getStage();
				$maxStage = $battle->getSiege()->getMaxStage();
				if ($myStage > 2 && $myStage == $maxStage) {
					$location = array('key'=>'battle.location.castle', 'id'=>$battle->getSettlement()->getId(), 'name'=>$battle->getSettlement()->getName());
					$this->siegeFinale = TRUE;
				} else {
					$location = array('key'=>'battle.location.assault', 'id'=>$battle->getSettlement()->getId(), 'name'=>$battle->getSettlement()->getName());
					$this->siegeFinale = FALSE;
				}
				$assault = true;
				# So, this looks a bit weird, but stone stuff counts during stages 1 and 2, while wood stuff and moats only count during stage 1. Stage 3 gives you the fortress, and stage 4 gives the citadel bonus.
				# If you're wondering why this looks different from how we figure out the max stage, that's because the final stage works differently.
				foreach ($battle->getDefenseBuildings() as $building) {
					switch (strtolower($building->getName())) {
						case 'stone wall': # 10 points
						case 'stone towers': # 5 points
						case 'stone castle': # 5 points
							if ($myStage < 3) {
								$this->report->addDefenseBuilding($building);
								$this->defenseBonus += $building->getDefenseScore();
							}
							break;
						case 'palisade': # 10 points
						case 'empty moat': # 5 points
						case 'filled moat': # 5 points
						case 'wood wall': # 10 points
						case 'wood towers': # 5 points
						case 'wood castle': # 5 points
							if ($myStage < 2) {
								$this->report->addDefenseBuilding($building);
								$this->defenseBonus += $building->getDefenseScore();
							}
							break;
						case 'fortress': # 50 points
							if ($myStage == 3) {
								$this->report->addDefenseBuilding($building);
								$this->defenseBonus += $building->getDefenseScore();
							}
							break;
						case 'citadel': # 70 points
							if ($myStage == 4) {
								$this->report->addDefenseBuilding($building);
								$this->defenseBonus += $building->getDefenseScore();
							}
							break;
						default:
							# Seats of power are all 5 pts each.
							# Apothercary and alchemist are also 5.
							# This grants up to 30 points.
							$this->report->addDefenseBuilding($building); #Yes, this means Alchemists, and Seats of Governance ALWAYS give their bonus, if they exist.
							$this->defenseBonus += $building->getDefenseScore();
							break;
					}
				}
				break;
			case 'urban':
				$this->report->setUrban(TRUE);
				$location = array('key'=>'battle.location.of', 'id'=>$battle->getSettlement()->getId(), 'name'=>$battle->getSettlement()->getName());
				$this->siegeFinale = FALSE;
				break;
			case 'field':
			default:
				$loc = $this->geo->locationName($battle->getLocation());
				$location = array('key'=>'battle.location.'.$loc['key'], 'id'=>$loc['entity']->getId(), 'name'=>$loc['entity']->getName());
				$this->siegeFinale = FALSE;
				break;
		}

		$this->report->setCycle($cycle);
		$this->report->setLocation($battle->getLocation());
		$this->report->setSettlement($battle->getSettlement());
		$this->report->setWar($battle->getWar());
		$this->report->setLocationName($location);

		$this->report->setCompleted(false);
		$this->report->setDebug("");
		$this->em->persist($this->report);
		$this->em->flush(); // because we need the report ID below to set associations

		$this->log(15, "populating characters and locking...\n");
		$characters = array();
		$this->regionType = false;
		foreach ($battle->getGroups() as $group) {
			foreach ($group->getCharacters() as $char) {
				$characters[] = $char->getId();
				$char->setBattling(true);
				if (!$this->regionType) {
					$this->regionType = $this->geo->findMyRegion($char)->getBiome()->getName(); #We're hijacking this loop to grab the region type for later calculations.
				}
			}

		}
		$this->em->flush(); #So we don't have doctrine entity lock failures, we need the above battling flag set. It also gives us an easy way to check which characters we need to check below.

		$this->log(15, "preparing...\n");

		$preparations = $this->prepare();
		if ($preparations === TRUE) {
			// the main call to actually run the battle:
			$this->log(15, "Resolving Battle...\n");
			$this->resolveBattle($myStage, $maxStage);
			$this->log(15, "Post Battle Cleanup...\n");
			$victor = $this->concludeBattle();
		} else {
			// if there are no soldiers in the battle
			$this->log(1, "failed battle\n");
			foreach ($battle->getGroups() as $group) {
				foreach ($group->getCharacters() as $char) {
					$this->history->logEvent(
						$char,
						'battle.failed',
						array(),
						History::MEDIUM, false, 20
					);
				}
			}
		}

		# Remove actions related to this battle.
		$this->log(15, "Removeing related actions...\n");
		foreach ($battle->getGroups() as $group) {
			foreach ($group->getRelatedActions() as $act) {
				$relevantActs = ['military.battle', 'siege.sortie', 'siege.assault'];
				if (in_array($act->getType(), $relevantActs)) {
					$this->em->remove($act);
				}
			}
		}

		// TODO: maybe here we could copy the soldier log to the character, so people get more detailed battle reports? could be with temporary events
		$this->log(15, "Removing temporary character associations...\n");
		foreach ($this->nobility as $noble) {
			$noble->getCharacter()->setActiveReport(null);
			$noble->getCharacter()->removeSoldiersOld($noble);
		}

		# TODO: Adapt this for when sieges have reached their conclusion, and pass which side was victorious to a different function to closeout the siege properly.
		if (!$battle->getSiege()) {
			$this->log(15, "Regular battle detected, Nulling primary battle groups...\n");
			if ($battle->getPrimaryDefender()) {
				$battle->setPrimaryDefender(NULL);
			}
			if ($battle->getPrimaryAttacker()) {
				$battle->setPrimaryAttacker(NULL);
			}
			$this->log(15, "Jittering characters and disbanding groups...\n");
			foreach ($battle->getGroups() as $group) {
				// to avoid people being trapped by overlapping battles - we move a tiny bit after a battle if travel is set
				// 0.05 is 5% of a day's journey, or about 25% of an hourly journey - or about 500m base speed, modified for character speed
				foreach ($group->getCharacters() as $char) {
					if ($char->getTravel()) {
						$char->setProgress(min(1.0, $char->getProgress() + $char->getSpeed() * 0.05));
					}
				}
				$this->war_manager->disbandGroup($group, $this->battlesize);
				# Battlesize is passed so we don't have to call addRegroupAction separately. Sieges don't have a regroup and are handled separately, so it doesn't matter for them.
			}
		} else {
			$this->log(15, "Siege battle detected, progressing siege...\n");
			# Pass the siege ID, which side won, and in the event of a battle failure, the preparation reesults (This lets us pass failures and prematurely end sieges.)
			$this->war_manager->progressSiege($battle->getSiege(), $victor, $preparations, $this->report);
		}
		$this->em->flush();
		$this->em->remove($battle);
	}

	private function prepare() {
		$battle = $this->battle;
		$combatworthygroups = 0;
		$enemy=null;
		$this->nobility = new ArrayCollection;

		if ($battle->getSiege()) {
			$siege = $battle->getSiege();
			$attGroup = $siege->getAttacker();
			$defGroup = NULL;
			$haveAttacker = FALSE;
			$haveDefender = FALSE;
		} else {
			$siege = FALSE;
			$attGroup = $battle->getPrimaryAttacker();
			$defGroup = $battle->getPrimaryDefender();
		}
		foreach ($battle->getGroups() as $group) {
			if ($siege && $defGroup == NULL) {
				if ($group != $attGroup && !$group->getReinforcing()) {
					$defGroup = $group;
				}
			}

			$groupReport = new BattleReportGroup();
			$this->em->persist($groupReport);
			$this->report->addGroup($groupReport); # attach group report to main report
			$groupReport->setBattleReport($this->report); # attach main report to this group report
			$group->setActiveReport($groupReport); # attach the group report to the battle group

			$group->setupSoldiers();
			$this->addNobility($group);

			$types=array();
			foreach ($group->getSoldiers() as $soldier) {
				if ($soldier->getExperience()<=5) {
					$soldier->addXP(2);
				} else {
					$soldier->addXP(1);
				}
				$type = $soldier->getType();
				if (isset($types[$type])) {
					$types[$type]++;
				} else {
					$types[$type] = 1;
				}
			}
			$combatworthy=false;
			$troops = array();
			$this->log(3, "Totals in this group:\n");
			foreach ($types as $type=>$number) {
				$this->log(3, $type.": $number \n");
				$troops[$type] = $number;
				$combatworthy=true;
			}
			if ($combatworthy && !$group->getReinforcing()) {
				# Groups that are reinforcing don't represent a primary combatant, and if we don't have atleast 2 primary combatants, there's no point.
				# TODO: Add a check to make sure we don't have groups reinforcing another group that's no longer in the battle.
				$combatworthygroups++;
				if ($battle->getSiege()) {
					if ($siege->getAttacker() == $group) {
						$haveAttacekr = TRUE;
					} else if ($siege->getDefender() == $group) {
						$haveDefender = TRUE;
					}
				}
			}
			$groupReport->setStart($troops);
		}
		$this->em->flush();

		// FIXME: in huge battles, this can potentially take, like, FOREVER :-(
		if ($combatworthygroups>1) {

			# Only siege assaults get defense bonuses.
			if ($this->defenseBonus) {
				$this->log(10, "Defense Bonus / Fortification: ".$this->defenseBonus."\n");
			}

			foreach ($battle->getGroups() as $group) {
				$mysize = $group->getVisualSize();
				if ($group->getReinforcedBy()) {
					foreach ($group->getReinforcedBy() as $reinforcement) {
						$mysize += $reinforcement->getVisualSize();
					}
				}

				if ($battle->getSiege() && !$this->siegeFinale && $group == $attGroup) {
					$totalAttackers = $group->getActiveMeleeSoldiers()->count();
					if ($group->getReinforcedBy()) {
						foreach ($group->getReinforcedBy() as $reinforcers) {
							$totalAttackers += $reinforcers->getActiveMeleeSoldiers()->count();
						}
					}
					$this->attMinContacts = floor($totalAttackers/4);
					$this->defMinContacts = floor(($totalAttackers/4*1.2));
				}
				if ($battle->getSiege() && ($battle->getSiege()->getAttacker() != $group && !$battle->getSiege()->getAttacker()->getReinforcedBy()->contains($group))) {
					// if we're on defense, we feel like we're more
					$mysize *= 1 + ($this->defenseBonus/200);
				}

				$enemies = $group->getEnemies();
				$enemysize = 0;
				foreach ($enemies as $enemy) {
					$enemysize += $enemy->getVisualSize();
				}
				$mod = sqrt($mysize / $enemysize);

				$this->log(3, "Group #".$group->getActiveReport()->getId().", visual size $mysize.\n");

				$this->battlesize = min($mysize, $enemysize);

				foreach ($group->getCharacters() as $char) {
					$this->character_manager->addAchievement($char, 'battlesize', $this->battlesize);
					$charReport = new BattleReportCharacter();
					$this->em->persist($charReport);
					$charReport->setGroupReport($group->getActiveReport());
					$charReport->setStanding(true)->setWounded(false)->setKilled(false)->setAttacks(0)->setKills(0)->setHitsTaken(0)->setHitsMade(0);
					$this->em->flush();
					$charReport->setCharacter($char);
					$char->setActiveReport($charReport);
					$group->getActiveReport()->addCharacter($charReport);
				}

				$base_morale = 50;
				// defense bonuses:
				if ($group == $battle->getPrimaryDefender() or $battle->getPrimaryDefender()->getReinforcedBy()->contains($group)) {
					if ($battle->getType = 'siegeassault') {
						$base_morale += $this->defenseBonus/2;
						$base_morale += 10;
					}
				}
				$this->log(10, "Base morale: $base_morale, mod = $mod\n");

				foreach ($group->getSoldiers() as $soldier) {
					// starting morale: my power, defenses and relative sizes
					$power = $soldier->RangedPower() + $soldier->MeleePower() + $soldier->DefensePower();

					if ($battle->getSiege() && ($battle->getSiege()->getAttacker() != $group && !$battle->getSiege()->getAttacker()->getReinforcedBy()->contains($group))) {
						$soldier->setFortified(true);
					}
					if ($soldier->isNoble()) {
						$this->character_manager->addAchievement($soldier->getCharacter(), 'battles');
						$morale = $base_morale * 1.5;
					} else {
						$this->history->addToSoldierLog($soldier, 'battle', array("%link-battle%"=>$this->report->getId()));
						$morale = $base_morale;
					}
					if ($soldier->getDistanceHome() > 10000) {
						// 50km = -10 / 100 km = -14 / 200 km = -20 / 500 km = -32
						$distance_mod = sqrt(($soldier->getDistanceHome()-10000)/500);
					} else {
						$distance_mod = 0;
					}
					$soldier->setMorale(($morale + $power) * $mod - $distance_mod);

					$soldier->resetCasualties();
				}
			}
			$this->em->flush(); # Save all active reports for characters, and all character reports to their group reports.
			return true;
		} else {
			if ($battle->getSiege()) {
				if ($haveAttacker) {
					return 'haveAttacker';
				} else if ($haveDefender) {
					return 'haveDefender';
				}
			}
			return false;
		}
	}

	private function addNobility(BattleGroup $group) {
		foreach ($group->getCharacters() as $char) {
			// TODO: might make this actual buy options, instead of hardcoded
			$weapon = $char->getWeapon();
			if (!$weapon) {
				$weapon = $this->em->getRepository('BM2SiteBundle:EquipmentType')->findOneByName('sword');
			}
			$armour = $char->getArmour();
			if (!$armour) {
				$armour = $this->em->getRepository('BM2SiteBundle:EquipmentType')->findOneByName('plate armour');
			}
			$equipment = $char->getEquipment();
			if (!$equipment) {
				$equipemnt = $this->em->getRepository('BM2SiteBundle:EquipmentType')->findOneByName('war horse');
			}

			$noble = new Soldier();
			$noble->setWeapon($weapon)->setArmour($armour)->setEquipment($equipment);
			$noble->setNoble(true);
			$noble->setName($char->getName());
			$noble->setLocked(false)->setRouted(false)->setAlive(true);
			$noble->setHungry(0)->setWounded(0); // FIXME: this is not actually correct, but if we start them with the wound level of the noble, they will dodge combat by being considered inactive right away!
			$noble->setExperience(1000)->setTraining(0);

			$noble->setCharacter($char);
			$group->getSoldiers()->add($noble);
			$this->nobility->add($noble);
		}
	}

	private function resolveBattle($myStage, $maxStage) {
		$battle = $this->battle;
		$phase = 1; # Initial value.
		$combat = true; # Initial value.
		$this->log(20, "Calculating ranged penalties...\n");
		$rangedPenalty = 1; # Default of no penalty. Yes, 1 is no penalty. It's a multiplier.
		switch ($this->regionType) {
			case 'scrub':
				$rangedPenalty *=0.8;
				break;
			case 'thin scrub':
				$rangedPenalty *=0.9;
				break;
			case 'marsh':
				$rangedPenalty *=0.8;
				break;
			case 'forest':
				$rangedPenalty *=0.7;
				break;
			case 'dense forest':
				$rangedPenalty *=0.5;
				break;
			case 'rock':
				$rangedPenalty *=0.9;
				break;
			case 'snow':
				$rangedPenalty *=0.6;
				break;
		}
		if ($battle->getType() == 'urban') {
			$rangedPenalty = 0.3;
		}
		$doRanged = TRUE;
		if ($myStage > 1 && $myStage == $maxStage) {
			$doRanged = FALSE; #Final siege battle, no ranged phase!
			$this->log(20, "...final siege battle detected, skipping ranged phase...\n\n");
		} else {
			$this->log(20, "Ranged Penalty: ".$rangedPenalty."\n\n");
		}
		$this->log(20, "...starting phases...\n");
		while ($combat) {
			$this->prepareRound();
			# Main combat loop, go!
			# TODO: Expand this for multiple ranged phases.
			if ($phase === 1 && $doRanged) {
				$this->log(20, "...ranged phase...\n");
				$combat = $this->runStage('ranged', $rangedPenalty, $phase, $doRanged);
				$phase++;
			} else {
				$this->log(20, "...melee phase...\n");
				$combat = $this->runStage('normal', $rangedPenalty, $phase, $doRanged);
				$phase++;
			}
		}
		$this->log(20, "...hunt phase...\n");
		$hunt = $this->runStage('hunt', $rangedPenalty, $phase, $doRanged);
	}

	private function prepareRound() {
		// store who is active, because this changes with hits and would give the first group to resolve the initiative while we want things to be resolved simultaneously
		foreach ($this->battle->getGroups() as $group) {
			foreach ($group->getSoldiers() as $soldier) {
				$soldier->setFighting($soldier->isActive());
				$soldier->resetAttacks();
			}
		}
		// Updated siege assault contact scores. When we have siege engines, this will get ridiculously simpler to calculate. Defenders always get it slightly easier.
		/* Or it would've been if this wasn't garbage.
		if ($this->battle->getType() == 'siegeassault') {
			$newAttContacts = $this->attCurrentContacts - $this->attSlain;
			$newDefContacts = $this->defCurrentContacts - $this->defSlain;
 			if ($newAttContacts < $this->attMinContacts) {
				$this->attCurrentContacts = $this->attMinContacts;
			} else {
				$this->attCurrentContacts = $newAttContacts;
			}
			if ($newDefContacts < $this->defMinContacts) {
				if ($newDefContacts < $this->attCurrentContacts) {
					$this->defCurrentContacts = $this->attCurrentContacts*1.3;
				} else {
					$this->defCurrentContacts = $newDefContacts;
				}
			}
			$this->defUsedContacts = 0;
			$this->attusedContacts = 0;
		}
		*/
		$this->em->flush();

	}

	private function runStage($type, $rangedPenaltyStart, $phase, $doRanged) {
		$groups = $this->battle->getGroups();
		$battle = $this->battle;
		foreach ($groups as $group) {
			$shots = 0; # Ranged attack attempts
			$strikes = 0; # Melee attack attempts
			$rangedHits = 0;
			$routed = 0;
			$capture = 0;
			$chargeCapture = 0;
			$wound = 0;
			$chargeWound = 0;
			$kill = 0;
			$chargeKill = 0;
			$fail = 0;
			$chargeFail =0;
			$missed = 0;
			$crowded = 0;
			#$attSlain = $this->attSlain; # For Sieges.
			#$defSlain = $this->defSlain; # For Sieges.
			$extras = array();
			$rangedPenalty = $rangedPenaltyStart; #We need each group to reset their rangedPenalty and defenseBonus.
			$defBonus = $this->defenseBonus;
			# The below is partially commented out until we fully add in the battle contact and siege weapon systems.
			if ($battle->getType() == 'siegeassault') {
				if ($battle->getPrimaryAttacker() == $group OR $group->getReinforcing() == $battle->getPrimaryAttacker()) {
					$rangedPenalty = 1; # TODO: Make this dynamic. Right now this can lead to weird scenarios in regions with higher penalties where the defenders are actually easier to hit.
					$siegeAttacker = TRUE;
					#$usedContacts = 0;
					#$currentContacts = $this->attCurrentContacts;
				} else {
					$defBonus = 0; # Siege defenders use pre-determined rangedPenalty.
					$siegeAttacker = FALSE;
					#$usedContacts = 0;
					#$currentContacts = $this->defCurrentContacts;
				}
			}
			if ($type != 'hunt') {
				$stageResult=array(); # Initialize this for later use. At the end of this loop, we commit this data to $stageReport->setData($stageResult);
				$stageReport = new BattleReportStage; # Generate new stage report.
				$this->em->persist($stageReport);
				$stageReport->setRound($phase);
				$stageReport->setGroupReport($group->getActiveReport());
				$this->em->flush();
				$group->getActiveReport()->addCombatStage($stageReport);

				$enemyCollection = new ArrayCollection;
				foreach ($group->getEnemies() as $enemygroup) {
					foreach ($enemygroup->getActiveSoldiers() as $soldier) {
						$enemyCollection->add($soldier);
					}
				}
				$enemies = $enemyCollection->count();
				$attackers = $group->getFightingSoldiers()->count();

				if (($battle->getPrimaryDefender() == $group) OR ($battle->getPrimaryAttacker() == $group)) {
					$this->log(5, "group ".$group->getActiveReport()->getId()." (".($group->getAttacker()?"attacker":"defender").") - ".$attackers." left, $enemies targets\n");
				} else {
					$this->log(5, "group ".$group->getActiveReport()->getId()." (Reinforcing group ".$group->getReinforcing()->getActiveReport()->getId().") - ".$attackers." left, $enemies targets\n");
				}

				$results = array();
			}

			/*

			Ranged Phase Combat Handling Code

			*/
			if ($type == 'ranged') {
				$bonus = sqrt($enemies); // easier to hit if there are many enemies
				foreach ($group->getFightingSoldiers() as $soldier) {
					if ($soldier->RangedPower() > 0) {
						// ranged soldier - fire!
						$result=false;
						$this->log(10, $soldier->getName()." (".$soldier->getType().") fires - ");
						$target = $this->getRandomSoldier($enemyCollection);
						if ($target) {
							$shots++;
							if (rand(0,100+$defBonus)<min(95*$rangedPenalty,($soldier->RangedPower()+$bonus)*$rangedPenalty)) {
								// target hit
								$rangedHits++;
								$result = $this->RangedHit($soldier, $target);
								if ($result=='fail') {
									$fail++;
								} elseif ($result=='wound') {
									$wound++;
								} elseif ($result=='capture') {
									$capture++;
								} elseif ($result=='kill') {
									$kill++;
								}
								if ($result=='kill'||$result=='capture') {
									$enemies--;
									$enemyCollection->removeElement($target);
								}
								// special results for nobles
								if ($target->isNoble() && in_array($result, array('kill','capture'))) {
									if ($result=='capture') {
										$extra = array(
											'what' => 'ranged.'.$result,
											'by' => $soldier->getCharacter()->getId()
										);
									} else {
										$extra = array('what'=>'ranged.'.$result);
									}
									$extra['who'] = $target->getCharacter()->getId();
									$extras[] = $extra;
								}

							} else {
								// missed
								$this->log(10, "missed\n");
								$missed++;
							}
							if ($soldier->getEquipment() && $soldier->getEquipment()->getName() == 'javelin') {
								if ($soldier->getWeapon() && !$soldier->getWeapon()->getName() == 'longbow') {
									// one-shot weapon, that only longbowmen will use by default in this phase
									// TODO: Better logic that determines this, for when we add new weapons.
									$soldier->dropEquipment();
								}
							}
						} else {
							$this->log(10, "no more targets\n");
						}
					}
				}
				if ($enemies > 0 && $rangedHits > 0) {
					// morale damage - a function of how much fire we are taking
					// yes, this makes hits count several times - morale reduction above and twice here (since they're also always a shot)
					// we also double the effective morale of a soldier (after damage), because even a single hit triggers this test
					// and we don't want it to be overwhelming
					$moraledamage = ($shots+$rangedHits*2) / $enemies;
					$this->log(10, "morale damage: $moraledamage\n");
					$total = 0; $count = 0;
					foreach ($group->getEnemies() as $enemygroup) {
						foreach ($enemygroup->getActiveSoldiers() as $soldier) {
							if ($soldier->isFortified()) {
								$soldier->reduceMorale($moraledamage/2);
							} else {
								$soldier->reduceMorale($moraledamage);
							}
							$total += $soldier->getMorale();
							$count++;
							$this->log(50, $soldier->getName()." (".$soldier->getType()."): morale ".round($soldier->getMorale()));
							if ($soldier->getMorale()*2 < rand(0,100)) {
								$this->log(50, " - panics");
								$soldier->setRouted(true);
								$this->history->addToSoldierLog($soldier, 'routed.ranged');
								$routed++;
							}
							$this->log(50, "\n");
						}
					}
					$this->log(10, "==> avg. morale: ".round($total/max(1,$count))."\n");
				}

				$stageResult = array('shots'=>$shots, 'rangedHits'=>$rangedHits, 'fail'=>$fail, 'wound'=>$wound, 'capture'=>$capture, 'kill'=>$kill, 'routed'=>$routed);
			}
			/*

			End of Ranged Phase Combat Handling Code

			*/
			/*

			Melee Phase Combat Handling Code

			*/
			if ($type == 'normal') {
				$bonus = sqrt($enemies);
				foreach ($group->getFightingSoldiers() as $soldier) {
					$result = false;
					$target = false;
					if ($doRanged && $phase == 2 && $soldier->isLancer() && $this->battle->getType() == 'field') {
						// Lancers will always perform a cavalry charge in the opening melee phase!
						// A cavalry charge can only happen if there is a ranged phase (meaning, there is ground to fire/charge across)
						$this->log(10, $soldier->getName()." (Lancer) attacks ");
						$target = $this->getRandomSoldier($enemyCollection);
						if ($target) {
							$strikes++;
							$result = $this->ChargeAttack($soldier, $target);
						} else {
							// no more targets
							$this->log(10, "but finds no target\n");
						}
					} else if ($soldier->isRanged() && $doRanged) {
						// Continure firing with a reduced hit chance in regular battle. If we skipped the ranged phase due to this being the last battle in a siege, we forego ranged combat to pure melee instead.
						// TODO: friendly fire !
						$this->log(10, $soldier->getName()." (".$soldier->getType().") fires - ");

						$target = $this->getRandomSoldier($enemyCollection);
						if ($target) {
							$shots++;
							if (rand(0,100+$defBonus)<min(75*$rangedPenalty,($soldier->RangedPower()+$bonus)*$rangedPenalty)) {
								$rangedHits++;
								$result = $this->RangedHit($soldier, $target);
							} else {
								$missed++;
								$this->log(10, "missed\n");
							}
						} else {
							// no more targets
							$this->log(10, "no more targets\n");
						}
					} else if ($soldier->MeleePower() > 0) {
						// We are either in a siege assault and we have contact points left, OR we are not in a siege assault. We are a melee unit or ranged unit with melee capabilities in final siege battle.
						$this->log(10, $soldier->getName()." (".$soldier->getType().") attacks ");
						$target = $this->getRandomSoldier($enemyCollection);
						if ($target) {
							$strikes++;
							$result = $this->MeleeAttack($soldier, $target, $phase);
							/*
							if ($battle->getType() == 'siegeassault') {
								$usedContacts++;
								if ($result=='kill'||$result=='capture') {
									if (!$siegeAttacker) {
										$attSlain++;
									} else {
										$defSlain++;
									}
								}
							}
							*/
						} else {
							// no more targets
							$this->log(10, "but finds no target\n");
						}
					} else {
						$this->log(10, $soldier->getName()." (".$soldier->getType().") is unable to attack\n");
						/*
						if ($battle->getType() == 'siegeassault') {
							$this->log(10, $soldier->getName()." (".$soldier->getType().") is unable to attack, contacts at ".$usedContacts." of ".$currentContacts."\n");
						} else {
							$this->log(10, $soldier->getName()." (".$soldier->getType().") is unable to attack\n");
						}
						*/
					}
					if (strpos($result, ' ') !== false) {
						$results = explode(' ', $result);
						$result = $results[0];
						$result2 = 'charge' . $results[1];
					} else {
						$result2 = false;
					}
					if ($result) {
						if ($result=='kill'||$result=='capture') {
							$enemies--;
							$enemyCollection->removeElement($target);
						}
						if ($result=='fail') {
							$fail++;
						} elseif ($result=='wound') {
							$wound++;
						} elseif ($result=='capture') {
							$capture++;
						} elseif ($result=='kill') {
							$kill++;
						}

						// special results for nobles
						if ($target->isNoble() && in_array($result, array('kill','capture'))) {
							if ($result=='capture' || $soldier->isNoble()) {
								$extra = array(
									'what' => 'noble.'.$result,
									'by' => $soldier->getCharacter()->getId()
								);
							} else {
								$extra = array('what'=>'mortal.'.$result);
							}

							$extra['who'] = $target->getCharacter()->getId();
							$extras[] = $extra;
						}
					} else {
						$notarget++;
						/*
						if ($battle->getType() == 'siegeassault' && $usedContacts >= $currentContacts) {
							$crowded++; #Frontline is too crowded in the siege.
						} else {
							$notarget++; #Just couldn't hit the target :(
						}
						*/
					}
					if ($result2) {
						if ($result2=='chargewound') {
							$chargeWound++;
						} elseif ($result2=='chargecapture') {
							$chargeCapture++;
						} elseif ($result2=='chargekill') {
							$chargeKill++;
						}
					}
				}
				$stageResult = array('alive'=>$attackers, 'shots'=>$shots, 'rangedHits'=>$rangedHits, 'strikes'=>$strikes, 'misses'=>$missed, 'notarget'=>$notarget, 'crowded'=>$crowded, 'fail'=>$fail, 'wound'=>$wound, 'capture'=>$capture, 'kill'=>$kill, 'chargefail' => $chargeFail, 'chargewound'=>$chargeWound, 'chargecapture'=>$chargeCapture, 'chargekill'=>$chargeKill);
			}
			if ($type != 'hunt') { # Check that we're in either Ranged or Melee Phase
				$stageReport->setData($stageResult); # Commit this stage's results to the combat report.
				$stageReport->setExtra($extras); # Commit this foolery because storing it in data is going to be chaos incarnate.
			}
			/*
			$this->defSlain += $defSlain;
			$this->attSlain += $attSlain;
			if ($battle->getType() == 'siegeassault') {
				if ($siegeAttacker) {
					$this->log(10, "Used ".$usedContacts." contacts.\n");
					$this->attUsedContacts += $usedContacts;
				} else {
					$this->log(10, "Used ".$usedContacts." contacts.\n");
					$this->defUsedContacts += $usedContacts;
				}
			}
			*/
		}
		/*

		Ranged & Melee Phase Morale Handling Code

		*/
		# In order to support legacy melee morale handling, we need to break this apart. First, refactor it. Second, rework the ranged morale into it and give them both a distinct area.
		if ($type == 'normal') {
			foreach ($groups as $group) {
				$this->log(10, "morale checks:\n");
				$stageResult = $group->getActiveReport()->getCombatStages()->last(); #getCombatStages always returns these in round ascending order. Thus, the latest one will be last. :)
				$routed = 0;

				$countUs = $group->getActiveSoldiers()->count();
				foreach ($group->getReinforcedBy() as $reinforcement) {
					$countUs += $reinforcement->getActiveSoldiers()->count();
				}
				$countEnemy = 0;
				$enemies = $group->getEnemies();
				foreach ($enemies as $enemygroup) {
					$countEnemy += $enemygroup->getActiveSoldiers()->count();
				}
				foreach ($group->getActiveSoldiers() as $soldier) {
					// still alive? check for panic
					if ($countEnemy > 0) {
						$ratio = $countUs / $countEnemy;
						if ($ratio > 10) {
							$mod = 0.95;
						} elseif ($ratio > 5) {
							$mod = 0.9;
						} elseif ($ratio > 2) {
							$mod = 0.8;
						} elseif ($ratio > 0.5) {
							$mod = 0.75;
						} elseif ($ratio > 0.25) {
							$mod = 0.65;
						} elseif ($ratio > 0.15) {
							$mod = 0.6;
						} elseif ($ratio > 0.1) {
							$mod = 0.5;
						} else {
							$mod = 0.4;
						}
					} else {
						// no enemies left
						$mod = 0.99;
					}

					if ($soldier->getAttacks()==0) {
						// we did not get attacked this round
						$mod = min(0.99, $mod+0.1);
					}
					$soldier->setMorale($soldier->getMorale() * $mod);
					if ($soldier->getMorale() < rand(0,100)) {
						$routed++;
						$this->log(10, $soldier->getName()." (".$soldier->getType()."): ($mod) morale ".round($soldier->getMorale())." - panics\n");
						$soldier->setRouted(true);
						$countUs--;
						$this->history->addToSoldierLog($soldier, 'routed.melee');
					} else {
						$this->log(20, $soldier->getName()." (".$soldier->getType()."): ($mod) morale ".round($soldier->getMorale())."\n");
					}
				}
				$combatResults = $stageResult->getData(); # CFetch original array.
				$combatResults['routed'] = $routed; # Append routed info.
				$stageResult->setData($combatResults); # Add routed to array and save.
			}
		}

		if ($type != 'hunt') {
			# Check if we're still fighting.
			$firstOrderCount = 0; # Count of active enemy soldiers
			$secondOrderCount = 0; # Count of acitve soldiers of enemy's enemies.
			foreach ($groups as $group) {
				$reverseCheck = false;
				foreach ($group->getEnemies() as $enemyGroup) {
					$firstOrderCount += $enemyGroup->getActiveSoldiers()->count();
					if (!$reverseCheck) {
						foreach ($enemyGroup->getEnemies() as $secondOrder) {
							$secondOrderCount += $secondOrder->getActiveSoldiers()->count();
						}
						$reverseCheck = true;
					}
				}
				break; # We only actually need any one group to start from.
			}

			if ($firstOrderCount == 0 OR $secondOrderCount == 0) {
				return false; # Fighting has ended.
			} else {
				return true; # Fighting continues.
			}
		} else {
			# Hunt down remaining enemies. Hunt comes after all other phases.

			$fleeing_entourage = array();
			$countEntourage = 0; #All fleeing entourage.
			$countSoldiers = 0; #All fleeing soldiers.
			$shield = $this->em->getRepository('BM2SiteBundle:EquipmentType')->findOneByName('shield');
			foreach ($groups as $group) {
				$huntResult = array();
				$groupReport = $group->getActiveReport(); # After it's built, the $huntResult array is saved via $groupReport->setHunt($huntResult);
				if ($group->getFightingSoldiers()->count()==0) {
					$this->log(10, "group is retreating:\n");
					$countGroup=0;
					foreach ($group->getCharacters() as $char) {
						$this->log(10, "character ".$char->getName());
						$count=0; #Entourage per character.
						foreach ($char->getLivingEntourage() as $e) {
							$fleeing_entourage[] = $e;
							$count++;
							$countGroup++;
							$countEntourage++;
						}
						$this->log(10, " $count entourage\n");
					}
					$groupReport->setHunt(array('entourage'=>$countGroup));
				}
			}
			$this->em->flush();
			$this->log(10, count($fleeing_entourage)." entourage are on the run.\n");

			foreach ($groups as $group) {
				$groupReport = $group->getActiveReport();
				# For the life of me, I don't remember why I added this next bit.
				if($groupReport->getHunt()) {
					$huntReport = $groupReport->getHunt();
				} else {
					$huntReport = array('killed'=>0, 'entkilled'=>0, 'dropped'=>0);
				}
				$this->prepareRound(); // called again each group to update the fighting status of all enemies

				$enemyCollection = new ArrayCollection;
				foreach ($group->getEnemies() as $enemygroup) {
					foreach ($enemygroup->getRoutedSoldiers() as $soldier) {
						$enemyCollection->add($soldier);
						$countSoldiers++;
					}
				}

				foreach ($group->getFightingSoldiers() as $soldier) {
					$target = $this->getRandomSoldier($enemyCollection);
					$hitchance = 0; // safety-catch, it should be set in all cases further down
					if ($target) {
						if ($soldier->RangedPower() > $soldier->MeleePower()) {
							$hitchance = 10+round($soldier->RangedPower()/2);
							$power = $soldier->RangedPower()*0.75;
						} else {
							// chance of catching up with a fleeing enemy
							if ($soldier->getEquipment() && in_array($soldier->getEquipment()->getName(), array('horse', 'war horse'))) {
								$hitchance = 50;
							} else {
								$hitchance = 30;
							}
							$hitchance = max(5, $hitchance - $soldier->DefensePower()/5); // heavy armour cannot hunt so well
							$power = $soldier->MeleePower()*0.75;
						}
						if ($target->getEquipment() && in_array($target->getEquipment()->getName(), array('horse', 'war horse'))) {
							$hitmod = 0.5;
						} else {
							$hitmod = 1.0;
						}

						$evade = min(75, round($target->getExperience()/10 + 5*sqrt($target->getExperience())) ); // 5 = 12% / 20 = 24% / 50 = 40% / 100 = 60%

						# Ranged penalty is used here to simulate the terrain advantages that retreating soldiers get to evasion. :)
						if (rand(0,100) < $hitchance * $hitmod && rand(0,100) > $evade/$rangedPenalty) {
							// hit someone!
							$this->log(10, $soldier->getName()." (".$soldier->getType().") caught up with ".$target->getName()." (".$target->getType().") - ");
							if (rand(0,$power) > rand(0,$target->DefensePower())) {
								$result = $this->resolveDamage($soldier, $target, $power, 'escape');
								if ($result) {
									$huntReport['killed']++;
									if ($result == 'killed') {
										// FIXME: This apparently doesn't work? At least once I saw a killed noble being attacked again
										$enemyCollection->removeElement($target);
									}
								} else {
									$target->addAttack(4);
								}
							} else {
								// no damage, check for dropping gear
								$this->log(10, "no damage\n");
								if ($soldier->isNoble()) continue; // noble characters don't throw away anything
								// throw away your shield - very likely
								if ($soldier->getEquipment() && $soldier->getEquipment() == $shield) {
									if (rand(0,100)<80) {
										$soldier->dropEquipment();
										$this->history->addToSoldierLog($soldier, 'dropped.shield');
										$this->log(10, $soldier->getName()." (".$soldier->getType()."): drops shield\n");
										$huntReport['dropped']++;
									}
								}
								// throw away your weapon - depends on weapon
								if ($soldier->getWeapon()) {
									switch ($soldier->getWeapon()->getName()) {
										case 'spear':		$chance = 40; break;
										case 'pike':		$chance = 50; break;
										case 'longbow':	$chance = 30; break;
										default:				$chance = 20;
									}
									if (rand(0,100)<$chance) {
										$soldier->dropWeapon();
										$this->history->addToSoldierLog($soldier, 'dropped.weapon');
										$this->log(10, $soldier->getName()." (".$soldier->getType()."): drops weapon\n");
										$huntReport['dropped']++;
									}
								}
							}
						}
					} else if (!empty($fleeing_entourage)) {
						# No routed soldiers? Try for an entourage.
						$this->log(10, "... now attacking entourage - ");
						if (rand(0,100) < $hitchance) {
							// yepp, we got one
							$i = rand(0,count($fleeing_entourage)-1);
							$target = $fleeing_entourage[$i];
							$this->log(10, "slaughters ".$target->getName()." (".$target->getType()->getName().")\n");
							// TODO: log this!
							$target->kill();
							$huntReport['entkilled']++;
							array_splice($fleeing_entourage, $i, 1);
						} else {
							$this->log(10, "didn't hit (chance was $hitchance)\n");
						}
					}
				}
				$groupReport->setHunt($huntReport);
			}
			$this->em->flush();
			return true;
		}
	}

	private function concludeBattle() {
		$battle = $this->battle;
		$this->log(3, "survivors:\n");
		$this->prepareRound(); // to update the isFighting setting correctly
		$survivors=array();
		foreach ($battle->getGroups() as $group) {
			foreach ($group->getSoldiers() as $soldier) {
				if ($soldier->getCasualties() > 0) {
					$this->history->addToSoldierLog($soldier, 'casualties', array("%nr%"=>$soldier->getCasualties()));
				}
			}

			$types=array();
			foreach ($group->getActiveSoldiers() as $soldier) {
				$soldier->gainExperience(2*$this->xpMod);

				$type = $soldier->getType();
				if (isset($types[$type])) {
					$types[$type]++;
				} else {
					$types[$type]=1;
				}
			}

			$troops = array();
			$this->log(3, "Total survivors in this group:\n");
			foreach ($types as $type=>$number) {
				$this->log(3, "$type: $number \n");
				$troops[$type] = $number;
			}
			$group->getActiveReport()->setFinish($troops);
		}

		$allNobles=array();

		$allGroups = $this->battle->getGroups();
		$this->log(2, "Fate of First Ones:\n");
		$primaryVictor = null;
		foreach ($allGroups as $group) {
			$nobleGroup=array();
			$my_survivors = $group->getActiveSoldiers()->filter(
				function($entry) {
					return (!$entry->isNoble());
				}
			)->count();
			if ($my_survivors > 0) {
				$victory = true;
				if (!$primaryVictor) {
					# Because it's handy to know who won, primarily for sieges.
					# TODO: Rework for more than 2 sides. This should be really easy. Just checking to see if we have soldiers and finding our top-level group.
					if ($battle->getPrimaryAttacker() == $group) {
						$primaryVictor = $group;
					} elseif ($battle->getPrimaryDefender() == $group) {
						$primaryVictor = $group;
					} elseif ($battle->getPrimaryAttacker()->getReinforcedBy->contains($group) || $battle->getPrimaryDefender()->getReinforcedBy->contains($group)) {
						$primaryVictor = $group->getReinforcing();
					} else {
						# I have so many questions about how you ended up here...
					}
				}
			} else {
				$victory = false;
			}
			foreach ($group->getSoldiers() as $soldier) {
				if ($soldier->isNoble()) {
					$id = $soldier->getCharacter()->getId();
					$allNobles[] = $soldier->getCharacter(); // store these here, because in some cases below they get removed from battlegroups
					if (!$soldier->isAlive()) {
						$nobleGroup[$id]='killed';
						// remove from BG or the kill() could trigger false "battle failed" messages
						$group->removeCharacter($soldier->getCharacter());
						$soldier->getCharacter()->removeBattlegroup($group);
						// FIXME: how do we get the killer ?
						$this->character_manager->kill($soldier->getCharacter(), null, false, 'death2');
					} elseif ($soldier->getCharacter()->isPrisoner()) {
						$nobleGroup[$id]='captured';
						// remove from BG or the imprison_complete() could trigger false "battle failed" messages
						$group->removeCharacter($soldier->getCharacter());
						$soldier->getCharacter()->removeBattlegroup($group);
						$this->character_manager->imprison_complete($soldier->getCharacter());
					} elseif ($soldier->isWounded()) {
						$nobleGroup[$id]='wounded';
					} elseif ($soldier->isActive()) {
						if ($victory) {
							$nobleGroup[$id]='victory';
						} else {
							$nobleGroup[$id]='retreat';
						}
					} else {
						$nobleGroup[$id]='retreat';
					}
					// defeated losers could be forced out
					if ($nobleGroup[$id]!='victory') {
						if ($this->battle->getType()=='urban' && $soldier->getCharacter()->getInsideSettlement()) {
							$this->interactions->characterLeaveSettlement($soldier->getCharacter(), true);
						}
					}
					$this->log(2, $soldier->getCharacter()->getName().': '.$nobleGroup[$id]." (".$soldier->getWounded()."/".$soldier->getCharacter()->getWounded()." wounds)\n");
				}
			}
			$group->getActiveReport()->setFates($nobleGroup);
		}

		$this->log(1, "Battle finished, report #".$this->report->getId()."\n");

		foreach ($allNobles as $char) {
			$this->history->logEvent(
				$char,
				'battle.participated',
				array('%link-battle%'=>$this->report->getId()),
				History::HIGH
			);
		}

		if ($this->battle->getSettlement()) {
			$this->history->logEvent(
				$this->battle->getSettlement(),
				'event.settlement.battle',
				array('%link-battle%'=>$this->report->getId()),
				History::MEDIUM
			);
		}

		$this->report->setCompleted(true);
		$this->em->flush();
		$this->log(1, "unlocking characters...\n");
		foreach ($allNobles as $noble) {
			$noble->setActiveReport(null); #Unset active report.
			$noble->setBattling(false);
		}
		foreach ($allGroups as $group) {
			$group->setActiveReport(null); #Unset active report.
		}
		$this->em->flush();
		$this->log(1, "unlocked...\n");
		unset($allNobles);
		return $primaryVictor;
	}

	private function MeleeAttack(Soldier $soldier, Soldier $target) {
		$xpMod = $this->xpMod;
		if ($soldier->isNoble()) {
			$soldier->getCharacter()->addSkill($soldier->getWeapon(), $xpMod);
		} else {
			$soldier->gainExperience(1*$xpMod);
		}
		$result='miss';

		$defense = $target->DefensePower();
		if ($target->isFortified()) {
			$defense += $this->defenseBonus;
		}

		$attack = $soldier->MeleePower();
		if ($soldier->isFortified()) {
			$attack += ($this->defenseBonus/2);
		}

		$this->log(10, $target->getName()." (".$target->getType().") - ");
		$this->log(15, (round($attack*10)/10)." vs. ".(round($defense*10)/10)." - ");
		if (rand(0,$attack) > rand(0,$defense)) {
			// defense penetrated
			$result = $this->resolveDamage($soldier, $target, $attack, 'melee');
			if ($soldier->isNoble()) {
				$soldier->getCharacter()->addSkill($soldier->getWeapon(), $xpMod);
			} else {
				$soldier->gainExperience(($result=='kill'?2:1)*$xpMod);
			}
		} else {
			// armour saved our target
			$this->log(10, "no damage\n");
			$result='fail';
		}
		$target->addAttack(5);
		$this->equipmentDamage($soldier, $target);

		return $result;
	}

	private function ChargeAttack(Soldier $soldier, Soldier $target) {
		$xpMod = $this->xpMod;
		if ($soldier->isNoble()) {
			$soldier->getCharacter()->addSkill($soldier->getWeapon(), $xpMod);
		} else {
			$soldier->gainExperience(1*$xpMod);
		}
		$result='miss';

		$attack = $soldier->ChargePower();
		$defense = $target->DefensePower(false)*0.75;

		$eWep = $target->getWeapon();
		if ($eWep->getType()->getSkill()->getCategory()->getName() == 'polearms') {
			$antiCav = True;
		} else {
			$antiCav = False;
		}


		$this->log(10, $target->getName()." (".$target->getType().") - ");
		$this->log(15, (round($attack*10)/10)." vs. ".(round($defense*10)/10)." - ");
		if (rand(0,$attack) > rand(0,$defense)) {
			// defense penetrated
			$result = $this->resolveDamage($soldier, $target, $attack, 'charge', $antiCav);
			if ($soldier->isNoble()) {
				$soldier->getCharacter()->addSkill($soldier->getWeapon(), $xpMod);
			} else {
				$soldier->gainExperience(($result=='kill'?2:1)*$xpMod);
			}
		} else {
			// armour saved our target
			$this->log(10, "no damage\n");
			$result='fail';
		}
		$target->addAttack(5);
		$this->equipmentDamage($soldier, $target, 'charge', $antiCav);

		return $result;
	}

	private function RangedHit(Soldier $soldier, Soldier $target) {
		$xpMod = $this->xpMod;
		if ($soldier->isNoble()) {
			$soldier->getCharacter()->addSkill($soldier->getWeapon(), $xpMod);
		} else {
			$soldier->gainExperience(1*$xpMod);
		}
		$result='miss';

		$defense = $target->DefensePower(false);
		if ($target->isFortified()) {
			$defense += $this->defenseBonus;
		}

		$attack = $soldier->RangedPower();
		if ($soldier->isFortified()) {
			// small bonus to attack to simulate towers height advantage, etc.
			$attack += $this->defenseBonus/5;
		}

		$this->log(10, "hits ".$target->getName()." (".$target->getType().") - (".round($attack)." vs. ".round($defense).") = ");
		if (rand(0,$attack) > rand(0,$defense)) {
			// defense penetrated
			$result = $this->resolveDamage($soldier, $target, $attack, 'ranged');
		} else {
			// armour saved our target
			$this->log(10, "no damage\n");
			$result='fail';
		}

		$target->addAttack(2);
		$this->equipmentDamage($soldier, $target);

		return $result;
	}

	private function equipmentDamage(Soldier $attacker, Soldier $target) {
		// small chance of armour or item damage - 10-30% per hit and then also depending on the item - 3%-14% - for total chances of ca. 1%-5% per hit
		if (rand(0,100)<15) {
			if ($attacker->getWeapon()) {
				$resilience = 30 - 3*sqrt($attacker->getWeapon()->getMelee() + $attacker->getWeapon()->getRanged());
				if (rand(0,100)<$resilience) {
					$attacker->dropWeapon();
					$this->log(10, "attacker weapon damaged\n");
				}
			}
		}
		if (rand(0,100)<10) {
			if ($target->getWeapon()) {
				$resilience = 30 - 3*sqrt($target->getWeapon()->getMelee() + $target->getWeapon()->getRanged());
				if (rand(0,100)<$resilience) {
					$target->dropWeapon();
					$this->log(10, "weapon damaged\n");
				}
			}
		}
		if (rand(0,100)<30) {
			if ($target->getArmour()) {
				$resilience = 30 - 3*sqrt($target->getArmour()->getDefense());
				if (rand(0,100)<$resilience) {
					$target->dropArmour();
					$this->log(10, "armour damaged\n");
				}
			}
		}
		if (rand(0,100)<25) {
			if ($target->getEquipment() && $target->getEquipment()->getDefense()>0) {
				$resilience = sqrt($target->getEquipment()->getDefense());
				if (rand(0,100)<$resilience) {
					$target->dropEquipment();
					$this->log(10, "equipment damaged\n");
				}
			}
		}
	}

	private function resolveDamage(Soldier $soldier, Soldier $target, $power, $phase, $antiCav = false) {
		// this checks for penetration again AND low-damage weapons have lower lethality AND wounded targets die more easily
		// TODO: attacks on mounted soldiers could kill the horse instead
		if (rand(0,$power) > rand(0,max(1,$target->DefensePower() - $target->getWounded(true)))) {
			// penetrated again = kill
			switch ($phase) {
				case 'charge':  $surrender = 90; break;
				case 'ranged':	$surrender = 60; break;
				case 'hunt':	$surrender = 85; break;
				case 'melee':
				default:	$surrender = 75; break;
			}
			// nobles can surrender and be captured instead of dying - if their attacker belongs to a noble
			if (($soldier->getMount() && $target->getMount() && rand(0,100) < 50) || $soldier->getMount() && !$target->getMount() && rand(0,100) < 70) {
				$this->log(10, "killed mount & wounded\n");
				$target->wound(rand(max(1, round($power/10)), $power));
				$target->dropMount();
				$this->history->addToSoldierLog($target, 'wounded.'.$phase);
				$result='wound';
			} else if ($target->isNoble() && !$target->getCharacter()->isNPC() && rand(0,100) < $surrender && $soldier->getCharacter()) {
				$this->log(10, "captured\n");
				$this->character_manager->imprison_prepare($target->getCharacter(), $soldier->getCharacter());
				$this->history->logEvent($target->getCharacter(), 'event.character.capture', array('%link-character%'=>$soldier->getCharacter()->getId()), History::HIGH, true);
				$result='capture';
				$this->character_manager->addAchievement($soldier->getCharacter(), 'captures');
			} else {
				if ($soldier->isNoble()) {
					if ($target->isNoble()) {
						$this->character_manager->addAchievement($soldier->getCharacter(), 'kills.nobles');
					} else {
						$this->character_manager->addAchievement($soldier->getCharacter(), 'kills.soldiers');
					}
				}
				$this->log(10, "killed\n");
				$target->kill();
				$this->history->addToSoldierLog($target, 'killed');
				$result='kill';
			}
		} else {
			$this->log(10, "wounded\n");
			$target->wound(rand(max(1, round($power/10)), $power));
			$this->history->addToSoldierLog($target, 'wounded.'.$phase);
			$result='wound';
			$target->gainExperience(1*$this->xpMod); // it hurts, but it is a teaching experience...
		}
		if ($antiCav) {
			$innerResult = $this->meleeAttack($target, $soldier); // Basically, an attack of opportunity.
			$result = $result . " " . $innerResult;
		} else {
			$innerResult = null;
		}

		$soldier->addCasualty();

		// FIXME: these need to take unit sizes into account!
		// FIXME: maybe we can optimize this by counting morale damage per unit and looping over all soldiers only once?!?!
		// every casualty reduces the morale of other soldiers in the same unit
		foreach ($target->getAllInUnit() as $s) { $s->reduceMorale(1); }
		// enemy casualties make us happy - +5 for the killer, +1 for everyone in his unit
		foreach ($soldier->getAllInUnit() as $s) { $s->gainMorale(1); }
		$soldier->gainMorale(4); // this is +5 because the above includes myself

		// FIXME: since nobles can be wounded more than once, this can/will count them multiple times
		return $result;
	}

	public function addLootToken() {
		// TODO: dead and retreat-with-drop should add stuff to a loot pile that those left standing can plunder or something
	}

	public function log($level, $text) {
		if ($this->report) {
			$this->report->setDebug($this->report->getDebug().$text);
		}
		if ($level <= $this->debug) {
			$this->logger->info($text);
		}
	}

	private function getRandomSoldier($group, $retry = 0) {
		$max = $group->count();
		$index = rand(1, $max);
		$target = $group->first();
		for ($i=1;$i<$index-2;$i++) {
			$target = $group->next();
		}
		if ($target && rand(10,25) <= $target->getAttacks()) {
			// too crowded around the target, can't attack it
			if ($retry<3) {
				// retry to find another target
				return $this->getRandomSoldier($group, $retry+1);
			} else {
				$target->setMorale($target->getMorale()-1); // overkill morale effect
				return null;
			}
		}
		return $target;
	}

	private function addNobleResult($noble, $result, $enemy) {
		# TODO: This is primarily for later, when we have time to implement this.
		$report = $noble->getActiveReport();
		if ($result == 'fail' || $result == 'wound' || $result == 'capture' || $result =='kill') {
			if ($report->getAttacks()) {
				$report->setAttacks($report->getAttacks()+1);
			} else {
				$report->setAttacks(1);
			}
			if ($result == 'wound' || $result == 'capture') {
				if ($report->getHitsMade()) {
					$report->setHitsMade($report->getHitsMade()+1);
				} else {
					$report->setHitsMade(1);
				}
			}
			if ($result == 'kill') {
				if ($report->getKills()) {
					$report->setKills($report->getKills()+1);
				} else {
					$report->setKills(1);
				}
			}
		} else {
			if ($report->getHitsTaken()) {
				$report->setHitsTaken($report->getHitsTaken()+1);
			} else {
				$report->setHitsTaken(1);
			}
			if ($result == 'captured') {
				$report->setCaptured(true);
				$report->setCapturedBy($enemy);
			}
			if ($result == 'killed') {
				$report->setKilled(true);
				$repot->setKilledBy($enemy);
			}
		}
	}

}
