<?php

namespace BM2\SiteBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;


/**
 * @Route("/")
 */
class DefaultController extends Controller {

	/**
	  * @Route("/", name="bm2_homepage")
	  */
	public function indexAction() {
		return $this->render('Default/index.html.twig', [
			"simple"=>true
		]);
	}

	/**
	  * @Route("/about", name="bm2_about")
	  */
	public function aboutAction() {
		$pr = $this->get('pagereader');
		$locale = $this->getRequest()->getLocale();

		$intro = $pr->getPage('about', 'introduction', $locale);
		$concept = $pr->getPage('about', 'concept', $locale);
		$gameplay = $pr->getPage('about', 'gameplay', $locale);
		$tech = $pr->getPage('about', 'technology', $locale);

		return $this->render('Default/about.html.twig', [
			"simple"=>true,
			'intro' => $intro,
			'concept' => $concept,
			'gameplay' => $gameplay,
			'tech' => $tech,
			'levels' => $this->get('payment_manager')->getPaymentLevels(),
			'concepturl' => $this->generateUrl('bm2_site_default_paymentconcept'),
		]);
	}

	/**
	  * @Route("/manual/{page}", name="bm2_manual", defaults={"page"="intro"})
	  */
	public function manualAction($page) {
		$toc = $this->get('pagereader')->getPage('manual', 'toc', $this->getRequest()->getLocale());
		$pagecontent = $this->get('pagereader')->getPage('manual', $page, $this->getRequest()->getLocale());

		return $this->render('Default/manual.html.twig', [
			"page" => $page,
			"toc" => $toc,
			"content" => $pagecontent
		]);
	}

	/**
	  * @Route("/vips", name="bm2_vips")
	  */
	public function vipsAction() {
		$em = $this->getDoctrine()->getManager();
		$query = $em->createQuery('SELECT u.display_name, u.vip_status FROM BM2SiteBundle:User u WHERE u.vip_status > 0 ORDER BY u.vip_status DESC, u.display_name');
		$vips = $query->getResult();

		return $this->render('Default/vips.html.twig', [
			"simple"=>true, "vips"=>$vips
		]);
	}


	/**
     * @Route("/contact", name="bm2_contact")
     */
	public function contactAction() {

		return $this->render('Default/contact.html.twig', [
			"simple"=>true
		]);
	}

	/**
     * @Route("/credits", name="bm2_credits")
     */
	public function creditsAction() {

		return $this->render('Default/credits.html.twig', [
			"simple"=>true
		]);
	}

	/**
     * @Route("/terms", name="bm2_terms")
     */
	public function termsAction() {

		return $this->render('Default/terms.html.twig', [
			"simple"=>true, "locale"=>$this->getRequest()->getLocale()
		]);
	}

	/**
     * @Route("/paymentconcept")
     */
	public function paymentConceptAction() {
		$pagecontent = $this->get('pagereader')->getPage('about', 'payment', $this->getRequest()->getLocale());

		return $this->render('Default/terms.html.twig', [
			"simple"=>true,
			"content"=>$pagecontent,
			"paylevels"=>$this->get('payment_manager')->getPaymentLevels()
		]);
	}


	public function localeRedirectAction($url) {
		if ($url=="-") $url="";
		if (preg_match('/^[a-z]{2}\//', $url)===1) {
			if (substr($url, 0, 2)=='en') {
        		throw $this->createNotFoundException('error.notfound.page');
        	}
			// unsupported locale - default to english - en
			$locale = 'en';
			$url = substr($url,3);
		} else {
			// no locale parameter - use the user's setting, defaulting to browser settings
			if ($user = $this->getUser()) {
				$locale = $user->getLanguage();
			}
			if (!isset($locale) || !$locale) {
				$locale = substr($this->getRequest()->getPreferredLanguage(),0,2);
			}
			if ($locale) {
				$languages = $this->get('appstate')->availableTranslations();
				if (!isset($languages[$locale])) {
					$locale='en';
				}
			} else {
				$locale='en';
			}
		}
		return $this->redirect('/'.$locale.'/'.$url);
	}

}
