<?php

namespace BM2\SiteBundle\Service;

use BM2\SiteBundle\Entity\Code;
use BM2\SiteBundle\Entity\CreditHistory;
use BM2\SiteBundle\Entity\User;
use BM2\SiteBundle\Entity\UserPayment;
use Doctrine\ORM\EntityManager;
use Monolog\Logger;
use Symfony\Component\Translation\TranslatorInterface;

class PaymentManager {

	protected $em;
	protected $usermanager;
	protected $mailer;
	protected $translator;
	protected $logger;


	// FIXME: type hinting for $translator removed because the addition of LoggingTranslator is breaking it
	public function __construct(EntityManager $em, UserManager $usermanager, \Swift_Mailer $mailer, TranslatorInterface $translator, Logger $logger) {
		$this->em = $em;
		$this->usermanager = $usermanager;
		$this->mailer = $mailer;
		$this->translator = $translator;
		$this->logger = $logger;
	}

	public function getPaymentLevels() {
		return array(
			 0 =>	array('name' => 'storage',	'characters' =>    0, 'fee' =>   0, 'selectable' => false),
			10 =>	array('name' => 'trial',	'characters' =>    4, 'fee' =>   0, 'selectable' => true),
			20 =>	array('name' => 'basic',	'characters' =>   10, 'fee' => 200, 'selectable' => true),
			21 =>	array('name' => 'volunteer',	'characters' =>   10, 'fee' =>   0, 'selectable' => false),
			22 =>   array('name' => 'patron',	'characters' =>   10, 'fee' =>   0, 'selectable' => false),
			40 =>	array('name' => 'intense',	'characters' =>   25, 'fee' => 300, 'selectable' => true),
			41 =>	array('name' => 'developer',	'characters' =>   25, 'fee' =>   0, 'selectable' => false),
			50 =>	array('name' => 'ultimate',	'characters' =>   50, 'fee' => 400, 'selectable' => true),
		);
	}

	public function calculateUserFee(User $user) {
		$days = 0;
		if ($user->getLastLogin()) {
			$days = (int)$user->getLastLogin()->diff(new \DateTime("now"))->format("%r%a");
		}

		$fees = $this->getPaymentLevels();
		if ($days>60) {
			$fee = $fees[0]['fee'];
		} else {
			$fee = $fees[$user->getAccountLevel()]['fee'];
			if ($user->getVipStatus() >= 20) {
				// legend or immortal = free basic account
				$fee = max(0, $fee - $fees[20]['fee']);
			}
		}
		return $fee;
	}

	public function getCostOfHeraldry() {
		return 500;
	}


	public function calculateRefund(User $user) {
		$today = new \DateTime("now");
		$days = $today->diff($user->getPaidUntil())->format("%a");
		$today->modify('+1 month -1 day');
		$month = $today->diff(new \DateTime("now"))->format("%a");

		$refund = ceil( ($days/$month) * $this->calculateUserFee($user) );
		return $refund;
	}

	public function calculateUserMaxCharacters(User $user) {
		$fees = $this->getPaymentLevels();
		return $fees[$user->getAccountLevel()]['characters'];
	}

	public function paymentCycle() {
		$free = 0;
		$active = 0;
		$expired = 0;
		$storage = 0;
		$credits = 0;
		$bannedcount = 0;
		$bannedquery = $this->em->createQuery("SELECT u FROM BM2SiteBundle:User u WHERE u.account_level > 0 AND u.roles LIKE '%ROLE_BANNED%'");
		foreach ($bannedquery->getResult() as $banned) {
			$bannedcount++;
			$this->changeSubscription($banned, 0);
			$banned->setNotifications(FALSE);
			$banned->setNewsletter(FALSE);
			$bannedusername = $banned->getUsername();
			$this->logger->info("$bannedusername has been banned, and email notifications have been disabled.");
			$this->em->flush();
		}
		$query = $this->em->createQuery('SELECT u FROM BM2SiteBundle:User u WHERE u.account_level > 0 AND u.paid_until < :now');
		$query->setParameters(array('now'=>new \DateTime("now")));
		foreach ($query->getResult() as $user) {
			$myfee = $this->calculateUserFee($user);
			if ($myfee > 0) {
				if ($this->spend($user, 'subscription', $myfee, true)) {
					$active++;
					$credits += $myfee;
				} else {
					// not enough credits left! - change to trial
					$user->setAccountLevel(10);
					$this->ChangeNotification($user, 'expired', 'expired2');
					// TODO: check that this recalculates correctly if someone is far beyond the due date and then renews subscription
					$expired++;
				}
			} else {
				if ($user->getLastLogin()) {
					$inactive_days = $user->getLastLogin()->diff(new \DateTime("now"), true)->days;
				} else {
					$inactive_days = $user->getCreated()->diff(new \DateTime("now"), true)->days;
				}
				if ($inactive_days > 60) {
					// after 2 months, we put you into storage
					$user->setAccountLevel(0);
					$storage++;
				} else {
					$free++;
				}
			}
		}
		return array($free, $active, $credits, $expired, $storage, $bannedcount);
	}

	private function ChangeNotification(User $user, $subject, $text) {
		$subject = $this->translator->trans("account.payment.mail.".$subject, array());
		$content = $this->translator->trans("account.payment.mail.".$text, array());

		$message = \Swift_Message::newInstance()
			->setSubject($subject)
			->setFrom('mafserver@lemuriacommunity.org')
			->setReplyTo('mafteam@lemuriacommunity.org')
			->setTo($user->getEmail())
			->setBody(strip_tags($content))
			->addPart($content, 'text/html');
		$this->mailer->send($message);
	}

	public function changeSubscription(User $user, $newlevel) {
		if (!array_key_exists($newlevel, $this->getPaymentLevels())) {
			return false;
		}
		$oldlevel = $user->getAccountLevel();
		$oldpaid = $user->getPaidUntil();

		$refund = $this->calculateRefund($user);
		$user->setAccountLevel($newlevel);
		$fee = $this->calculateUserFee($user);

		if ($fee > $user->getCredits()+$refund) {
			return false;
		} else {
			if ($refund>0) {
				$this->spend($user, 'refund', -$refund, false);
			}
			$user->setPaidUntil(new \DateTime("now"));
			$check = $this->spend($user, 'subscription', $fee, true);
			if ($check) {
				// reset account restriction, so it is recalculated
				if ($user->getRestricted()) {
					$user->setRestricted(false);
					$this->em->flush();
				}
				return true;
			} else {
				// this should never happen - alert me
				$this->logger->alert('error in change subscription for user '.$user->getId().", change from $oldlevel to $newlevel");
				return false;
			}
		}
	}


	public function account(User $user, $type, $currency, $amount, $transaction=null) {
		$credits = $amount*100; // if this ever changes, make sure to update texts mentioning it (e.g. description3)
		switch ($currency) {
			case 'EUR':		$credits *= 1.0; break;
//			case 'USD':		$credits *= 0.7648; break; // FIXME: this shouldn't be hardcoded, I think...
			default:
				$this->logger->alert("unknown currency $currency in accounting for user #{$user->getId()}, transaction type $type, please add $amount manually.");
				return false;
		}
		$credits = ceil($credits);

		if ($user->getPayments()->isEmpty()) {
			$first = true;
		} else {
			$first = false;
		}

		$payment = new UserPayment;
		$payment->setTs(new \DateTime("now"));
		$payment->setCurrency($currency);
		$payment->setAmount($amount);
		$payment->setType($type);
		$payment->setUser($user);
		$payment->setCredits($credits);
		$payment->setTransactionCode($transaction);
		$this->em->persist($payment);
		$user->addPayment($payment);

		$history = new CreditHistory;
		$history->setTs(new \DateTime("now"));
		$history->setCredits($credits);
		$history->setUser($user);
		$history->setType($type);
		$history->setPayment($payment);
		$this->em->persist($history);
		$user->addCreditHistory($history);

		$user->setCredits($user->getCredits()+$credits);

		if ($first) {
			// give us our free vanity item
			$user->setArtifactsLimit(max(1, $user->getArtifactsLimit()));

			// check if we had a friend code
			$query = $this->em->createQuery('SELECT c FROM BM2SiteBundle:Code c WHERE c.used_by = :me AND c.sender IS NOT NULL AND c.sender != :me ORDER BY c.used_on ASC');
			$query->setParameter('me', $user);
			$query->setMaxResults(1);
			$code = $query->getOneOrNullResult();
			if ($code) {
				$sender = $code->getSender();
				$value = round(min($credits, $code->getCredits()) / 2);

				$h = new CreditHistory;
				$h->setTs(new \DateTime("now"));
				$h->setCredits($value);
				$h->setUser($sender);
				$h->setType('friendinvite');
				$this->em->persist($h);
				$user->addCreditHistory($h);

				$sender->setCredits($sender->getCredits()+$value);
				$this->usermanager->updateUser($sender, false);

				$text = $this->translator->trans('account.invite.mail2.body', array("%mail%"=>$user->getEmail(), "%credits%"=>$value));
				$message = \Swift_Message::newInstance()
					->setSubject($this->translator->trans('account.invite.mail2.subject', array()))
					->setFrom(array('mafserver@lemuriacommunity.org' => $this->translator->trans('mail.sender', array(), "communication")))
					->setReplyTo('mafteam@lemuriacommunity.org')
					->setTo($sender->getEmail())
					->setBody(strip_tags($text))
					->addPart($text, 'text/html')
				;
				$numSent = $this->mailer->send($message);
				$this->logger->info('sent friend subscriber email: ('.$numSent.') - '.$text);
			}
		}

		// TODO: this is not quite complete, what about people going into negative credits?
		$this->usermanager->updateUser($user, false);
		$this->em->flush();
		return true;
	}

	public function redeemHash(User $user, $hash) {
		$code = $this->em->getRepository('BM2SiteBundle:Code')->findOneByCode($hash);
		if ($code) {
			return array($code, $this->redeemCode($user, $code));
		} else {
			return array(null, "error.payment.nosuchcode");
		}
	}

	public function redeemCode(User $user, Code $code) {
		if ($code->getUsed()) {
			$this->logger->alert("user #{$user->getId()} tried to redeem already-used code {$code->getId()}");
			return "error.payment.already";
		}

		if ($code->getSentToEmail() && $code->getLimitToEmail() && $code->getSentToEmail() != $user->getEmail()) {
			$this->logger->alert("user #{$user->getId()} tried to redeem code not for him - code #{$code->getId()}");
			return "error.payment.notforyou";
		}

		if ($code->getCredits() > 0) {
			$history = new CreditHistory;
			$history->setTs(new \DateTime("now"));
			$history->setCredits($code->getCredits());
			$history->setUser($user);
			$history->setType("code");
			$this->em->persist($history);
			$user->addCreditHistory($history);

			$user->setCredits($user->getCredits()+$code->getCredits());
		}

		if ($code->getVipStatus() && $code->getVipStatus() > $user->getVipStatus()) {
			// TODO: report back if this doesn't change our status
			$user->setVipStatus($code->getVipStatus());
		}
		// TODO: unlock characters, also check if we were due a payment - how ?
		$this->usermanager->updateUser($user, false);

		$code->setUsed(true);
		$code->setUsedOn(new \DateTime("now"));
		$code->setUsedBy($user);

		$this->em->flush();

		return true;
	}

	public function createCode($credits, $vip_status=0, $sent_to=null, User $sent_from=null, $limit=false) {
		$code = new Code;
		$code->setCode(sha1(time()."mafcode".mt_rand(0,1000000)));
		$code->setCredits($credits);
		$code->setVipStatus($vip_status);
		$code->setUsed(false);
		$code->setSentOn(new \DateTime("now"));
		if ($sent_from) {
			$code->setSender($sent_from);
		}
		if ($sent_to) {
			$code->setSentToEmail($sent_to);
		} else {
			$code->setSentToEmail("");
		}
		$code->setLimitToEmail($limit);
		$this->em->persist($code);
		$this->em->flush();
		return $code;
	}


	public function spend(User $user, $type, $credits, $renew_subscription=false) {
		if ($credits>0 && $user->getCredits()<$credits) {
			return false;
		}
		$history = new CreditHistory;
		$history->setTs(new \DateTime("now"));
		$history->setCredits(-$credits);
		$history->setUser($user);
		$history->setType($type);
		$this->em->persist($history);
		$user->addCreditHistory($history);

		$user->setCredits($user->getCredits()-$credits);
		if ($renew_subscription) {
			// NOTICE: This will add +1 to the month value and can skip into the following month
			// example: January 31st + 1 month == March 3rd (because there is no February 31st)
			$paid = clone $user->getPaidUntil();
			$paid->modify('+1 month');
			$user->setPaidUntil($paid);
		}
		$this->usermanager->updateUser($user, false);
		$this->em->flush();
		$this->logger->info("Payment: User ".$user->getId().", $type, $credits credits");
		return true;
	}


	public function log_info($text) {
		$this->logger->info($text);
	}

	public function log_error($text) {
		$this->logger->error($text);
	}

}
