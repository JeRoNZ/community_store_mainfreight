<?php

namespace Concrete\Package\CommunityStoreMainfreight\Src\CommunityStore\Shipping\Method\Types;

use Concrete\Core\Cache\Level\ExpensiveCache;
use Concrete\Core\Entity\Attribute\Value\Value\AddressValue;
use Concrete\Core\Logging\LoggerAwareInterface;
use Concrete\Core\Logging\LoggerAwareTrait;
use Concrete\Core\Logging\LoggerFactory;
use Concrete\Package\CommunityStore\Src\CommunityStore\Group\Group;
use Concrete\Package\CommunityStore\Src\CommunityStore\Product\ProductGroup;
use Doctrine\ORM\Mapping as ORM;
use Concrete\Core\Support\Facade\Application;
use Concrete\Core\Support\Facade\DatabaseORM as dbORM;
use Concrete\Package\CommunityStore\Src\CommunityStore\Cart\Cart;
use Concrete\Package\CommunityStore\Src\CommunityStore\Product\Product;
use Concrete\Package\CommunityStore\Src\CommunityStore\Customer\Customer;
use Concrete\Package\CommunityStore\Src\CommunityStore\Utilities\Calculator;
use Concrete\Package\CommunityStore\Src\CommunityStore\Shipping\Method\ShippingMethodTypeMethod;
use Concrete\Package\CommunityStore\Src\CommunityStore\Shipping\Method\ShippingMethodOffer;
use GuzzleHttp\Client;
use Concrete\Core\Support\Facade\Config;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;

/**
 * @ORM\Entity
 * @ORM\Table(name="CommunityStoreMainfreightRateMethods")
 */
class MainfreightShippingMethod extends ShippingMethodTypeMethod implements LoggerAwareInterface {
	use LoggerAwareTrait;

	/**
	 * @ORM\Column(type="string")
	 */
	protected $countries;
	/**
	 * @ORM\Column(type="text",nullable=true)
	 */
	protected $countriesSelected;

	/**
	 * @ORM\Column(type="boolean",nullable=true)
	 */
	protected $debugLogging;

	/**
	 * @ORM\Column(type="string")
	 */
	protected $serviceTypeDOM;

	/**
	 * @ORM\Column(type="string")
	 */
	protected $serviceTypeB2B;
	/**
	 * @ORM\Column(type="string")
	 */
	protected $packageType;

	private const timeOut = 30;
	private const connectTimeOut = 10;

	public function setCountries ($countries) {
		$this->countries = $countries;
	}

	public function setCountriesSelected ($countriesSelected) {
		$this->countriesSelected = $countriesSelected;
	}

	public function getDebugLogging () {
		return $this->debugLogging ? true : false;
	}

	public function setDebugLogging ($logging) {
		$this->debugLogging = $logging ? 1 : 0;
	}

	public function getServiceTypeDOM () {
		return $this->serviceTypeDOM;
	}

	public function setServiceTypeDOM ($serviceTypeDOM): void {
		$this->serviceTypeDOM = $serviceTypeDOM;
	}

	public function getServiceTypeB2B () {
		return $this->serviceTypeB2B;
	}

	public function setServiceTypeB2B ($serviceTypeB2B): void {
		$this->serviceTypeB2B = $serviceTypeB2B;
	}

	public function getPackageType () {
		return $this->packageType;
	}

	public function setPackageType ($packageType): void {
		$this->packageType = $packageType;
	}


	public function getLoggerChannel () {
		return 'mainfreight';
	}

	public function getCountries () {
		return $this->countries;
	}

	public function getCountriesSelected () {
		return $this->countriesSelected;
	}

	public function addMethodTypeMethod ($data) {
		return $this->addOrUpdate('add', $data);
	}

	public function update ($data) {
		return $this->addOrUpdate('update', $data);
	}

	private function addOrUpdate ($type, $data) {
		if ("update" == $type) {
			$sm = $this;
		} else {
			$sm = new self();
		}
		$sm->setCountries($data['countries']);
		$countriesSelected = '';
		if (isset($data['countriesSelected'])) {
			$countriesSelected = implode(',', $data['countriesSelected']);
		}
		$sm->setCountriesSelected($countriesSelected);
		$this->setDebugLogging($data['debugLogging'] ? 1 : 0);
		$sm->setPackageType($data['packageType']);
		$sm->setServiceTypeDOM($data['serviceTypeDOM']);
		$sm->setServiceTypeB2B($data['serviceTypeB2B']);
		$em = dbORM::entityManager();
		$em->persist($sm);
		$em->flush();

		return $sm;
	}

	public function dashboardForm ($shippingMethod = null) {
		$app = Application::getFacadeApplication();
		$this->set('form', $app->make("helper/form"));
		$this->set('smt', $this);
		$this->set('countryList', $app->make('helper/lists/countries')->getCountries());

		if (is_object($shippingMethod)) {
			$smtm = $shippingMethod->getShippingMethodTypeMethod();
		} else {
			$smtm = new self();
		}
		$this->set("smtm", $smtm);
	}

	public function validate ($args, $e) {
		return $e;
	}

	public function isEligible () {
		if ($this->isWithinSelectedCountries()) {
			if ($this->isWithinWeight()) {
				return true;
			}
		}

		return false;
	}

	public function isWithinSelectedCountries () {
		if ($this->getCountries() === 'all') {
			return true;
		}
		$customer = new Customer();
		$address = $customer->getValue('shipping_address');
		$custCountry = $address ? (string) $address->country : '';
		if ($custCountry === '') {
			return false;
		}
		$selectedCountries = explode(',', $this->getCountriesSelected());

		return in_array($custCountry, $selectedCountries, true);
	}

	public function getShippingMethodTypeName () {
		return t('Mainfreight');
	}

	public function getOffers () {
		$offers = [];

//		$rates = $this->getRates();
		$rates = false;
		if ($rates === false) {
			return [];
		}

		foreach ($rates as $rate) {
			$offer = new ShippingMethodOffer();
			$offer->setRate(round($rate['rate'] * 1.15, 2));
			$offer->setOfferLabel($rate['label']);
			$offer->setOfferDetails($rate['details']);
			$offers[] = $offer;
		}

		return $offers;
	}

	public function getOffer ($key) {
		$this->getOffers()[$key];
	}
}
