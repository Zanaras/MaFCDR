<?php 

namespace BM2\SiteBundle\Entity;

class PoI {


	public function startPoIConstruction($workers) {
		$this->setActive(false);
		$this->setWorkers($workers);
		$this->setCondition(-$this->getType()->getBuildHours()); // negative value - if we reach 0 the construction is complete
		return $this;
	}

	public function isActive() {
		return $this->getActive();
	}
	
	public function abandon($damage = 1) {
		if ($this->isActive()) {
			$this->setActive(false);
			$this->setCondition(-$damage);
		}
		$this->setWorkers(0);
		return $this;
	}
	
	public function getNametrans() {
		return 'PoI.'.$this->getName();
	}
	
	public function getInEstate() {
	  if ($this->isInside() == true) {
	  return true
	  } else {
	  return false
	  }
	}

}
