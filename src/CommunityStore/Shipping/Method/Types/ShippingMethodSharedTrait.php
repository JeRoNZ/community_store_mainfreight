<?php

namespace Concrete\Package\CommunityStoreMainfreight\Src\CommunityStore\Shipping\Method\Types;

use Concrete\Core\Logging\LoggerAwareTrait;
use Concrete\Core\Logging\LoggerFactory;
use Concrete\Core\Support\Facade\Application;
use Concrete\Core\Support\Facade\DatabaseORM as dbORM;
use Concrete\Package\CommunityStore\Src\CommunityStore\Cart\Cart;
use Concrete\Package\CommunityStore\Src\CommunityStore\Product\Product;
use Concrete\Package\CommunityStore\Src\CommunityStore\Customer\Customer;
use Doctrine\ORM\Mapping as ORM;
use DVDoug\BoxPacker\Exception\NoBoxesAvailableException;
use DVDoug\BoxPacker\ItemList;
use DVDoug\BoxPacker\Packer;
use DVDoug\BoxPacker\Rotation;
use DVDoug\BoxPacker\VolumePacker;
use Monolog\Logger;
use CommunityStoreMainfreight\ConditionalLogger;
use CommunityStoreMainfreight\MainfreightBox;
use CommunityStoreMainfreight\MainfreightItem;

/**
 * Shared behaviour for the carrier shipping methods in this package (Mainfreight, Post Haste):
 * country eligibility, debug logging, box-packing of the cart into cartons, and the
 * add/update/dashboard-form boilerplate. The classes using this trait differ only in how a
 * packed shipment is translated into a rate request/response for their carrier's API, which
 * stays in getOffers() on each class.
 */
trait ShippingMethodSharedTrait {
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

	protected $disableCaching;

	private $boxes;

	abstract public function getLoggerChannel();

	private static function defaultBoxes (): array {
		return [
			['l' => 1.2, 'w' => 0.15, 'h' => 0.13, 'k' => 20, 'ew' => 250, 't' => 5],
			['l' => 2.4, 'w' => 0.15, 'h' => 0.13, 'k' => 30, 'ew' => 250, 't' => 5],
			['l' => 1.2, 'w' => 0.15, 'h' => 0.19, 'k' => 20, 'ew' => 250, 't' => 5],
			['l' => 2.4, 'w' => 0.15, 'h' => 0.19, 'k' => 30, 'ew' => 250, 't' => 5],
			['l' => 0.35, 'w' => 0.35, 'h' => 0.2,  'k' => 20, 'ew' => 250, 't' => 5],
		];
	}

	private function setup () {
		// This function is necessary because the class is instantiated via the entity manager
		// which does not run the __construct or on_start methods, or otherwise managed to blat
		// non-orm field values.
		$this->debugLogging = \Config::get('mainfreight.debugLogging') ?? false;
		$this->disableCaching = \Config::get('mainfreight.disableCaching') ?? false;

		// Box sizes are physical warehouse facts shared across carriers.
		$configured = \Config::get('mainfreight.box_sizes');
		$this->boxes = (!empty($configured) && is_array($configured)) ? $configured : self::defaultBoxes();
	}

	public function setCountries ($countries) {
		$this->countries = $countries;
	}

	public function setCountriesSelected ($countriesSelected) {
		$this->countriesSelected = $countriesSelected;
	}

	public function getCountries () {
		return $this->countries;
	}

	public function getCountriesSelected () {
		return $this->countriesSelected;
	}

	public function getDebugLogging () {
		return $this->debugLogging ? true : false;
	}

	public function setDebugLogging ($logging) {
		$this->debugLogging = $logging ? 1 : 0;
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
		$sm->applyAdditionalFields($data);
		$em = dbORM::entityManager();
		$em->persist($sm);
		$em->flush();

		return $sm;
	}

	/**
	 * Hook for shipping methods with their own extra dashboard-form fields to persist.
	 * No-op by default; override where there are additional fields (e.g. Mainfreight's
	 * service types and collection times).
	 */
	protected function applyAdditionalFields ($data) {
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

	private function getConditionalLogger (): ConditionalLogger {
		return new ConditionalLogger($this->getLogger(), false);
	}

	public function getOffer ($key) {
		$this->getOffers()[$key];
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
				$volumePacker->setLogger($this->getConditionalLogger());
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
		$packer->setLogger($this->getConditionalLogger());
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
				$h = $product->getHeight();
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
					$this->log(Logger::WARNING, t('Product %s has width: %s, height: %s, length: %s, weight: %s, cannot ship', $sku, $w, $h, $l, $weight));

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
					// Convert size/weight to kg/metre for the carrier API
					$shipment[] = [
						'weight' => round($packedBox->getWeight() / 1000, 3),
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

				// Convert weight to kg for the carrier API
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

				// Convert dimensions to metres for the carrier API
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
		// cartons are specified in metres and kg. Packer requires integers. Scale to mm and g.
		$emptyWeight = (int) ($carton['ew'] ?? 250);
		$thickness   = (float) ($carton['t'] ?? 10);

		return new MainfreightBox(
			reference: t('box %s', $ix),
			outerWidth: $carton['w'] * 1000,
			outerLength: $carton['l'] * 1000,
			outerDepth: $carton['h'] * 1000,
			emptyWeight: $emptyWeight,
			innerWidth: $carton['w'] * 1000 - $thickness * 2,
			innerLength: $carton['l'] * 1000 - $thickness * 2,
			innerDepth: $carton['h'] * 1000 - $thickness * 2,
			maxWeight: $carton['k'] * 1000
		);
	}
}
