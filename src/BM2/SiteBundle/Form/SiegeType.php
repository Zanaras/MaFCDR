<?php

namespace BM2\SiteBundle\Form;

use BM2\SiteBundle\Entity\Character;
use BM2\SiteBundle\Entity\Settlement;
use BM2\SiteBundle\Entity\Siege;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Doctrine\ORM\EntityRepository;


class SiegeType extends AbstractType {

	public function __construct(Character $character, Settlement $settlement, Siege $siege, $action = null) {
		$this->character = $character;
		$this->settlement = $settlement;
		$this->siege = $siege;
		$this->action = $action;
	}

	public function configureOptions(OptionsResolver $resolver) {
		$resolver->setDefaults(array(
			'csrf_token_id'       => 'siege_97',
			'translation_domain' => 'actions',
		));
	}

	public function buildForm(FormBuilderInterface $builder, array $options) {
		$siege = $this->siege;
		$settlement = $this->settlement;
		$character = $this->character;
		$action = $this->action;
		$isLeader = FALSE;
		$isAttacker = FALSE;
		$isDefender = FALSE;
		$defLeader = FALSE;
		$attLeader = FALSE;
		$attCount = 0;
		$defCount = 0;
		$actionslist = array();
		#NOTE: $allactions = array('leadership', 'build', 'assault', 'disband', 'leave', 'join', 'assume');
		# Figure out if we're the group leader, and while we're at it, if both groups have leaders.
		if (!$action || $action == 'select') {
			foreach ($siege->getGroups() as $group) {
				if ($group->getCharacters()->contains($character) && $group->isAttacker()) {
					$isAttacker = TRUE;
				} elseif ($group->getCharacters()->contains($character) && $group->isDefender()) {
					$isDefender = TRUE;
				}
				if ($character == $group->getLeader()) {
					
					$isLeader = TRUE;
					if ($siege->getAttacker() == $group && $group->getLeader()) {
						$attLeader = TRUE;
						$attCount = $group->getCharacters()->count();
						# Not used now, but later we'll set this up so other people can assume leadership of attackers in certain instances.
					}
					if ($siege->getAttacker() != $group && $group->getLeader()) {
						$defLeader = TRUE;
						# If this isn't TRUE, the lord can assume leadership of the siege.
						$defCount = $group->getCharacters()->count();
					}
				}
			}
			/* TODO: Originally the plan was to allow suicide runs, but they make siege battles *messy* with how the groups are handled. For now, no suicide runs.
			if (!$character->isDoingAction('military.regroup')) {
				$actionslist = array('attack' => 'siege.action.attack');
			} else {
				$actionslist = array();
			}
			*/
			# Once we add siege equipment, we'll give everyone the option to build it, regrouping or not.
			# $actionslist = array('build' => 'siege.action.build', 'attack' => 'siege.action.attack');
			if ($isLeader) {
				# Leaders always have disband and transfer leadership actions.
				$actionslist = array_merge($actionslist, array('disband'=>'siege.action.disband'));
				if ($defCount > 1 || $attCount > 1) {
					$actionslist = array_merge($actionslist, array('leadership'=>'siege.action.leadership'));
				}
				if (!$character->isDoingAction('military.regroup')) {
					# Not regrouping? Then you can call an assault if you'r the leader.
					$actionslist = array_merge($actionslist, array('assault'=>'siege.action.assault'));
				}
			} else {
				# Anyone that isn't the leader can opt to just leave.
				$actionslist = array_merge($actionslist, array('leave' => 'siege.action.leave'));
			}
			if (
				(!$defLeader && $isDefender && $character->getInsideSettlement() == $settlement && $settlement->getOwner() == $character && !$defLeader) 
				|| (!$defLeader && !$settlement->getCharactersPresent()->contains($settlement->getOwner()) && $isDefender)
				|| (!$attLeader && $isDefender)
			) {
				# No leader of your group? Defending lord can assume if present, otherwise any defender can. Any attacker can take control of leaderless attackers.
				$actionslist = array_merge($actionslist, array('assume'=>'siege.action.assume'));
			}
			if (!$siege->getBattles()->isEmpty()) {
				# If there's a battle ongoing, anyone can opt to join it. If the leader does, they'll be able to call their entire force into action.
				$actionslist = array_merge($actionslist, array('join'=>'siege.action.join'));
			}
			ksort($actionslist, 2); #Sort array as strings.
			$builder->add('action', ChoiceType::class, array(
				'required'=>true,
				'choices' => $actionslist,
				'placeholder'=>'siege.action.none',
				'label'=> 'siege.actions',
			));
		} else {
			$builder->add('action', HiddenType::class, array(
				'data'=>'selected'
			));
			switch($action) {
				case 'leadership':
					$builder->add('subaction', HiddenType::class, array(
						'data'=>'leadership'
					));
					$builder->add('newleader', 'entity', array(
						'label'=>'siege.newleader',
						'required'=>true,
						'placeholder'=>'siege.character.none',
						'attr'=>array('title'=>'siege.help.newleader'),
						'class'=>'BM2SiteBundle:Character',
						'choice_label'=>'name',
						'query_builder'=>function(EntityRepository $er) use ($character, $siege) {
							return $er->createQueryBuilder('c')->leftjoin('c.battlegroups', 'bg')->where(':character = bg.leader')->andWhere('bg.siege = :siege')->andWhere(':character != c')->setParameters(array('character'=>$character, 'siege'=>$siege))->orderBy('c.name', 'ASC');
						}
					));
					break;
				case 'build':
					$builder->add('subaction', HiddenType::class, array(
						'data'=>'build'
					));
					$builder->add('quantity', 'integer', array(
						'attr'=>array('size'=>3)
					));
					/*
					$form->add('type', 'entity', array(
						'label'=>'siege.newequpment',
						'required'=>true,
						'placeholder'=>'equipment.none'
						'attr'=>array('title'=>'siege.help.equipmenttype'),
						'class'=>'BM2SiteBundle:SiegeEquipmentType',
						'choice_label'=>'nameTrans'
						'query_builder'=>function(EntityRepository $er){
							return $er->createQueryBuilder('e')->orderBy('e.name', 'ASC');
						}
					));
					*/
					break;
				case 'assault':
					$builder->add('subaction', HiddenType::class, array(
						'data'=>'assault'
					));
					$builder->add('assault', CheckboxType::class, array(
						'label' => 'siege.assault',
						'required' => true
					));
					break;
				case 'disband':
					$builder->add('subaction', HiddenType::class, array(
						'data'=>'disband'
					));
					$builder->add('disband', CheckboxType::class, array(
						'label' => 'siege.disband',
						'required' => true
					));
					break;
				case 'leave':
					$builder->add('subaction', HiddenType::class, array(
						'data'=>'leave'
					));
					$builder->add('leave', CheckboxType::class, array(
						'label' => 'siege.leave',
						'required' => true
					));
					break;
				case 'attack':
					$builder->add('subaction', HiddenType::class, array(
						'data'=>'attack'
					));
					$builder->add('attack', CheckboxType::class, array(
						'label' => 'siege.attack',
						'required' => true
					));
					break;
				/*case 'joinattack':
					$builder->add('subaction', HiddenType::class, array(
						'data'=>'joinattack'
					));
					$builder->add('join', CheckboxType::class, array(
						'label' => 'siege.join',
						'required' => true
					));
					if ($isLeader) {
						$builder->add('joinall', CheckboxType::class, array(
							'label' => 'siege.joinall',
							'required' => true
						));
					}
					break;*/
				case 'joinsiege':
					$builder->add('subaction', HiddenType::class, array(
						'data'=>'joinsiege'
					));
					# Later we'll extend this to include reinforcing parties, hence the arrays. Those looking to attack the attackers and those looking to attack the defenders but weren't part of the original siege (presumably because they showed up late).
					if ($character->getInsideSettlement() == $settlement) {
						$sides = array('defenders' => 'siege.side.defenders');
					} else {
						$sides = array('attackers' => 'siege.side.attackers');
					}
					$builder->add('side', ChoiceType::class, array(
						'required'=>true,
						'choices' => $sides,
						'placeholder'=>'siege.sides.none',
						'label'=> 'siege.joinside'
					));
					break;
				case 'assume':
					$builder->add('subaction', HiddenType::class, array(
						'data'=>'assume'
					));
					$builder->add('assume', CheckboxType::class, array(
						'label' => 'siege.assume',
						'required' => true
					));
					break;
			}
		};

		$builder->add('submit', SubmitType::class, array('label'=>'siege.submit'));

	}

	public function getName() {
		return 'siege';
	}
}
