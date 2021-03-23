<?php

namespace BM2\SiteBundle\DataFixtures\ORM;

use Doctrine\Common\DataFixtures\AbstractFixture;
use Doctrine\Common\DataFixtures\OrderedFixtureInterface;
use Doctrine\Common\Persistence\ObjectManager;

use BM2\SiteBundle\Entity\Biome;


class LoadBiomeData extends AbstractFixture implements OrderedFixtureInterface {

	private $types = array(
		'duel'			=> array('enabled' => True),
		'tournament'		=> array('enabled' => False, 'buildings' => ['arena']),
		'joust'			=> array('enabled' => False, 'buildings' => 0.95, 'roads' => 1.00, 'features' => 1.00),
		'race'			=> array('enabled' => False, 'buildings' => ['race track']),
		'hunt'			=> array('enabled' => False),
		'ball'			=> array('enabled' => False),
	);

	/**
	 * {@inheritDoc}
	 */
	public function getOrder() {
		return 1; // or anywhere, really
	}

	/**
	 * {@inheritDoc}
	 */
	public function load(ObjectManager $manager) {
		foreach ($this->types as $name=>$data) {
			$type = new Biome;
			$type->setName($name);
			$type->setSpot($data['spot']);
			$type->setTravel($data['travel']);
			$type->setRoadConstruction($data['roads']);
			$type->setFeatureConstruction($data['features']);
			$manager->persist($type);
		}
		$manager->flush();
	}
}
