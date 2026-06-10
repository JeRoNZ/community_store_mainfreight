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
use DVDoug\BoxPacker\Exception\NoBoxesAvailableException;
use DVDoug\BoxPacker\ItemList;
use DVDoug\BoxPacker\Packer;
use DVDoug\BoxPacker\Rotation;
use DVDoug\BoxPacker\VolumePacker;
use GuzzleHttp\Client;
use Concrete\Core\Support\Facade\Config;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;
use Monolog\Logger;
use CommunityStoreMainfreight\Mainfreight;
use CommunityStoreMainfreight\MainfreightBox;
use CommunityStoreMainfreight\MainfreightItem;

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

	private const APIBaseURL = 'https://api.mainfreight.com/transport/1.0/';

	protected $disableCaching;

	private $boxes = [
		[
			'l' => 1.2,
			'w' => 0.15,
			'h' => 0.13,
			'k' => 20
		],
		[
			'l' => 2.4,
			'w' => 0.15,
			'h' => 0.13,
			'k' => 30
		],

		[
			'l' => 1.2,
			'w' => 0.15,
			'h' => 0.19,
			'k' => 20
		],
		[
			'l' => 2.4,
			'w' => 0.15,
			'h' => 0.19,
			'k' => 30
		]
	];

	public function __construct () {
		// DEBUG make this pick up from a config variable
		$this->disableCaching = true;
	}


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
			return true;
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

	private function log ($level, $text) {
		if (!$this->debugLogging && $level < Logger::WARNING) {
			return;
		}

		$this->getLogger()->addRecord($level, $text);

	}

	private function getLogger () {
		if (!$this->logger) {
			$this->logger = app(LoggerFactory::class)->createLogger($this->getLoggerChannel());
		}

		return $this->logger;
	}

	public function getOffer ($key) {
		$this->getOffers()[$key];
	}


	public function getOffers () {
		$customer = new Customer();
		$address = $customer->getValue('shipping_address');
		/* @var $address AddressValue | \stdClass */

		if ($address === null) {
			return false;
		}

		if ($address instanceof \stdClass) {
			$add1 = $address->address1;
			$add2 = $address->address2;
			$city = $address->city;
			$postCode = $address->postal_code;
		} else {
			$add1 = $address->getAddress1();
			$add2 = $address->getAddress2();
			$city = $address->getCity();
			$postCode = $address->getPostalCode();
		}

		if (!$add1 || !$postCode || !$city) {
			return [];
		}

		$boxes = $this->bilboBaggage();
		if (!is_array($boxes) || count($boxes) == 0) {
			return [];
		}

		/*
		 * $boxes
		 * Array
(
    [0] => Array
        (
            [weight] => 10.25
            [width] => 0.15
            [height] => 0.13
            [length] => 1.2
        )

)
		 */

		$args['serviceLevel'] = ['code'=>'LCL'];  // TODO pick this up from the configured type
		$args['origin'] = [
			'freightRequiredDateTime' => '2026-06-30T12:00:00:00', // TODO set this up
			'freightRequiredDateTimeZone' => 'New Zealand Standard Time', // TODO query summer time
			'address' => [
				'suburb' => 'Grey Lynn', // TODO make this configurable
				'postCode' => '1011',
				'city' => 'Auckland',
				'countryCode' => 'NZ',
			]
		];

		$args['destination'] = [
			'address' => [
				'suburb' => $add2,
				'postCode' => $postCode,
				'city' => $city,
				'countryCode' => 'NZ', // TODO pick this up from the address
			]
		];

		foreach($boxes as $box) {
			$args['freightDetails'][] =
				[
					'units' => "1",
					'packTypeCode' => "PLT", // TODO pick this up from the configured type
					'height' => number_format($box['height'],2,null,''),
					'length' => number_format($box['length'],2,null,''),
					'width' => number_format($box['width'],2,null,''),
					'weight' => number_format($box['weight'],0,null,''),
//					'volume' => number_format($box['width']*$box['length']*$box['height'],2,null,'')
				];
		}






		$API = new Mainfreight();

		$rates = $API->getRates($args);

		return false;



		foreach ($boxes as $box) {
			/** @var ShippingMethodOffer $offer */
			$offer = $this->getBoxPrice($API, $box, $args);
			if (!$offer) {
				return [];
			}
			if (!isset($finalOffer)) {
				$finalOffer = new ShippingMethodOffer();
			}
			$finalOffer->setRate($finalOffer->getRate() + $offer->getRate());
			$finalOffer->setOfferDetails($offer->getOfferDetails());
			$finalOffer->setOfferLabel($offer->getOfferLabel());
			$finalOffer->setMethodLabel($offer->getMethodLabel());
		}

		if (isset($finalOffer)) {
			return [$finalOffer];
		}

		return [];
	}


	protected function bilboBaggage () {
		$shippableItems = Cart::getShippableItems();
		if (!is_array($shippableItems) || count($shippableItems) == 0) {
			$this->log(Logger::WARNING, t('No shipppable items found'));

			return false;
		}

		$weightUnit = \Config::get('community_store.weightUnit');
		$sizeUnit = \Config::get('community_store.sizeUnit');

		// Remove anything too big for the box sizes we have
		$biguns = [];
		foreach ($shippableItems as $key => $shippableItem) {
			// NZ Post limits: 25 kg 0.125m3 1.5 m
			/** @var Product $product */
			$product = $shippableItem['product']['object'];

			$weight = $product->getWeight();
			$h = $product->getHeight();
			$l = $product->getLength();
			$w = $product->getWidth();

			// Convert product weight to grams for the packer
			switch ($weightUnit) {
				case 'kg';
					$weight = $weight * 1000;
					break;
				case 'g':
					break;
				case 'lb':
				case 'oz':
					throw new \Exception('Please set store weight to kg or g');
			}

			/// convert product dimensions to mm for the packer
			switch ($sizeUnit) {
				case 'mm':
					break;
				case 'cm':
					$h = $h * 10;
					$l = $l * 10;
					$w = $w * 10;
					break;
				default :
					throw new \Exception('Please set store size unit to mm or cm');
			}

			$itFitz = false;
			foreach ($this->boxes as $ix => $carton) {
				$box = $this->henry($carton, $ix);
				$items = new ItemList();
				$items->insert(
					new MainfreightItem(
						description: 'test',
						width: $w,
						length: $l,
						depth: $h,
						weight: $weight,
						allowedRotation: Rotation::BestFit
					)
				);

				$volumePacker = new VolumePacker($box, $items);
				$volumePacker->setLogger($this->getLogger());
				$packedBox = $volumePacker->pack();
				$packedItems = $packedBox->items;
				if ($packedItems->getVolume() > 0) {
					$itFitz = true;
					break;
				}
			}

			if (!$itFitz) {
				$biguns[] = $shippableItem;
				unset($shippableItems[$key]);
			}
		}

		$packer = new Packer();
		$packer->setLogger($this->getLogger());
		foreach ($this->boxes as $carton) {
			$packer->addBox($this->henry($carton));
		}

		$shipment = [];
		if (count($shippableItems) > 0) {
			foreach ($shippableItems as $item) {
				/** @var Product $product */
				$product = $item['product']['object'];
				$qty = (int) $item['product']['qty'];

				$weight = $product->getWeight();
				$sku = $product->getSKU();
				$name = $product->getName();
				$h = $product->getHeight(); // Unleashed provides dimensions in metres - we have converted to cm in the loader
				$l = $product->getLength();
				$w = $product->getWidth();


				// convert product weight to grams for the packer
				switch ($weightUnit) {
					case 'kg';
						$weight = round($weight * 1000, 3);
						break;
					case 'g':
						break;
					case 'lb':
					case 'oz':
						throw new \Exception('Please set store weight to kg or g');
				}

				// convert product dimensions to mm for the packer
				switch ($sizeUnit) {
					case 'mm':
						break;
					case 'cm':
						$h = round($h * 10, 2);
						$l = round($l * 10, 2);
						$w = round($w * 10, 2);
						break;
					default :
						throw new \Exception('Please set store size unit to mm or cm');

				}

				if ($weight == 0 || $h == 0 || $l == 0 | $w == 0) {
					$this->log(Logger::WARNING, t('Product %s has width: %s, height: %s, length: %s, weight: %s, cannot ship', $name, $w, $h, $l, $weight));

					return false;
				}

				$packer->addItem(
					item: new MainfreightItem(
						description: $sku,
						width: $w,
						length: $l,
						depth: $h,
						weight: $weight,
						allowedRotation: Rotation::BestFit
					),
					qty: $qty
				);
			}
			try {
				$packedBoxes = $packer->pack();

				foreach ($packedBoxes as $packedBox) {
					// Convert size/weight to kg/metre for Mainfreight API
					$shipment[] = ['weight' => round($packedBox->getWeight() / 1000, 3),
						'width' => round($packedBox->box->getOuterWidth() / 1000, 3),
						'height' => round($packedBox->box->getOuterDepth() / 1000, 3),
						'length' => round($packedBox->box->getOuterLength() / 1000, 3),
					];
				}
			} catch (NoBoxesAvailableException $e) {
				$this->log(Logger::WARNING, t($e->getMessage()));

				return false;
			}
		}
		if (count($biguns)) {
			foreach ($biguns as $bigun) {
				$product = $bigun['product']['object'];
				$qty = (int) $bigun['product']['qty'];
				$weight = $product->getWeight();
				$h = $product->getHeight();
				$l = $product->getLength();
				$w = $product->getWidth();

				// Convert Weight to Kg for Mainfreight API
				switch ($weightUnit) {
					case 'g';
						$weight = round($weight / 1000, 3);
						break;
					case 'kg':
						break;
					case 'lb':
					case 'oz':
						throw new \Exception('Please set store weight to kg or g');
				}

				// Convert dimensions to Metres for Mainfreight's API
				switch ($sizeUnit) {
					case 'mm':
						$h = round($h / 1000, 3);
						$l = round($l / 1000, 3);
						$w = round($w / 1000, 3);
						break;
					case 'cm':
						$h = round($h / 100, 3);
						$l = round($l / 100, 3);
						$w = round($w / 100, 3);
						break;
					default :
						throw new \Exception('Please set store size unit to mm or cm');

				}

				for ($i = 1; $i <= $qty; $i++) {
					$shipment[] = ['weight' => $weight, 'height' => $h, 'length' => $l, 'width' => $w];
				}
			}
		}


		return $shipment;
	}


	private function henry ($carton, $ix = 0) {
		//cartons are specified in metres and kg. Packer requires integers. Scale to mm and g.
		return new MainfreightBox(
			reference: t('box %s', $ix),
			outerWidth: $carton['w'] * 1000,
			outerLength: $carton['l'] * 1000,
			outerDepth: $carton['h'] * 1000,
			emptyWeight: 250,
			innerWidth: $carton['w'] * 1000 - 5,
			innerLength: $carton['l'] * 1000 - 5,
			innerDepth: $carton['h'] * 1000 - 5,
			maxWeight: $carton['k'] * 1000
		);
	}


	protected function getBoxPrice (Mainfreight $API, $box, $address) {
		$args = array_merge($box, $address);
		$this->log(Logger::DEBUG, t('Pricing for %s %s', var_export($box, true), var_export($address, true)));
		$this->log(Logger::DEBUG, t('Merged %s', var_export($args, true)));
		/*		ksort($args);
				if (isset($args['prods'])) {
					unset($args['prods']);
				}*/

		$cache = $expensiveCache = null;
		if (!$this->disableCaching) {
			$expensiveCache = app('cache/expensive');
			$cacheName = 'MainfreightRates' . md5(json_encode($args));
			$cache = $expensiveCache->getItem($cacheName);
		}
		if ($cache && $cache->isHit()) {
			$rates = $cache->get();
			$this->log(Logger::DEBUG, t('Price cache hit %s %s', var_export($args, true), var_export($rates, true)));
		} else {
			$rates = $API->getRates($args);
			$this->log(Logger::DEBUG, t('Price cache miss %s %s', var_export($args, true), var_export($rates, true)));
			if ($rates === null) {
				return [];
			}
			if ($expensiveCache) {
				$expensiveCache->save($cache->set($rates)->expiresAfter(3600));
			}
		}

		$rates = json_decode($rates);
		$success = $rates->success ?? false;
		if (!$success) {
			return [];
		}

		// NZ POst uses Kg and cm

		$services = $rates->services ?? [];
		foreach ($services as $service) {
			// Ignore Pickup and Label - it's far too cheap.
			if ($service->description == 'Pickup And Label') {
				continue;
			}
			if (!property_exists($service, 'price_including_surcharge_and_gst')) {
				continue;
			}
			if ($service->price_including_surcharge_and_gst == 0) {
				continue;
			}

			// Find the Cheapest option
			/** @var $offer ShippingMethodOffer */
			if (isset($offer)) {
				if ($offer->getRate() < $service->price_including_surcharge_and_gst) {
					continue;
				}
			} else {
				$offer = new ShippingMethodOffer();
			}
			$offer->setOfferLabel(' ' . $service->description);
			// It makes me weep sometimes.
			$offer->setOfferDetails(str_replace("area's", 'areas', $service->service_standard));
			$offer->setRate($service->price_including_surcharge_and_gst);
		}
		if (isset ($offer)) {
			return $offer;
		}

		return null;
	}
}
