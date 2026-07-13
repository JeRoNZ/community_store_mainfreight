<?php

namespace Concrete\Package\CommunityStoreMainfreight\Src\CommunityStore\Shipping\Method\Types;

use Concrete\Core\Entity\Attribute\Value\Value\AddressValue;
use Concrete\Core\Logging\LoggerAwareInterface;
use Concrete\Package\CommunityStore\Src\CommunityStore\Tax\Tax;
use Doctrine\ORM\Mapping as ORM;
use Concrete\Package\CommunityStore\Src\CommunityStore\Customer\Customer;
use Concrete\Package\CommunityStore\Src\CommunityStore\Shipping\Method\ShippingMethodTypeMethod;
use Concrete\Package\CommunityStore\Src\CommunityStore\Shipping\Method\ShippingMethodOffer;
use Concrete\Core\Support\Facade\Config;
use Monolog\Logger;
use CommunityStoreMainfreight\Mainfreight;

/**
 * @ORM\Entity
 * @ORM\Table(name="CommunityStoreMainfreightRateMethods")
 */
class MainfreightShippingMethod extends ShippingMethodTypeMethod implements LoggerAwareInterface {
	use ShippingMethodSharedTrait;

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

	/**
	 * @ORM\Column(type="string",nullable=true)
	 */
	protected $orderCutoffTime;

	/**
	 * @ORM\Column(type="string",nullable=true)
	 */
	protected $collectionTime;

	/**
	 * @ORM\Column(type="boolean",nullable=true)
	 */
	protected $saturdayEnabled;

	/**
	 * @ORM\Column(type="boolean",nullable=true)
	 */
	protected $sundayEnabled;

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

	public function getOrderCutoffTime () {
		return $this->orderCutoffTime;
	}

	public function setOrderCutoffTime ($orderCutoffTime): void {
		$this->orderCutoffTime = $orderCutoffTime;
	}

	public function getCollectionTime () {
		return $this->collectionTime;
	}

	public function setCollectionTime ($collectionTime): void {
		$this->collectionTime = $collectionTime;
	}

	public function getSaturdayEnabled () {
		return $this->saturdayEnabled ? true : false;
	}

	public function setSaturdayEnabled ($saturdayEnabled): void {
		$this->saturdayEnabled = $saturdayEnabled ? 1 : 0;
	}

	public function getSundayEnabled () {
		return $this->sundayEnabled ? true : false;
	}

	public function setSundayEnabled ($sundayEnabled): void {
		$this->sundayEnabled = $sundayEnabled ? 1 : 0;
	}

	protected function applyAdditionalFields ($data) {
		$this->setPackageType($data['packageType']);
		$this->setServiceTypeDOM($data['serviceTypeDOM']);
		$this->setServiceTypeB2B($data['serviceTypeB2B']);
		$this->setOrderCutoffTime($data['orderCutoffTime']);
		$this->setCollectionTime($data['collectionTime']);
		$this->setSaturdayEnabled(!empty($data['saturdayEnabled']));
		$this->setSundayEnabled(!empty($data['sundayEnabled']));
	}

	public function getLoggerChannel () {
		return 'mainfreight';
	}

	public function getShippingMethodTypeName () {
		return t('Mainfreight');
	}

	private function getFreightRequiredDateTime (): \DateTime {
		$timezone = new \DateTimeZone(Config::get('app.server_timezone') ?: 'Pacific/Auckland');
		$now = new \DateTime('now', $timezone);

		$cutoffTime = $this->getOrderCutoffTime() ?: '00:00';
		$collectionTime = $this->getCollectionTime() ?: $cutoffTime;

		$cutoffToday = clone $now;
		[$cutoffHour, $cutoffMinute] = explode(':', $cutoffTime);
		$cutoffToday->setTime((int) $cutoffHour, (int) $cutoffMinute, 0);

		$date = clone $now;
		if ($now > $cutoffToday) {
			$date->modify('+1 day');
		}

		while (!$this->isDayAvailable($date)) {
			$date->modify('+1 day');
		}

		[$collectionHour, $collectionMinute] = explode(':', $collectionTime);
		$date->setTime((int) $collectionHour, (int) $collectionMinute, 0);

		return $date;
	}

	private function isDayAvailable (\DateTime $date): bool {
		switch ((int) $date->format('N')) {
			case 6:
				return $this->getSaturdayEnabled();
			case 7:
				return $this->getSundayEnabled();
			default:
				return true;
		}
	}

	public function getOffers () {
		/***************/
		$this->setup();
		/***************/

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

		$pickup = Config::get('mainfreight.pickup_address') ?: [];

		$args = [];
		$args['origin'] = [
			'freightRequiredDateTime' => $this->getFreightRequiredDateTime()->format('Y-m-d\TH:i:s') . ':00',
			'freightRequiredDateTimeZone' => 'New Zealand Standard Time', // TODO query summer time
			'address' => [
				'suburb' => $pickup['suburb'],
				'postCode' => $pickup['postcode'],
				'city' => $pickup['city'],
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

		foreach ($boxes as $box) {
			$args['freightDetails'][] =
				[
					'units' => "1",
					'packTypeCode' => $this->getPackageType(),
					'height' => number_format($box['height'], 2, null, ''),
					'length' => number_format($box['length'], 2, null, ''),
					'width' => number_format($box['width'], 2, null, ''),
					'weight' => number_format($box['weight'], 0, null, ''),
//					'volume' => number_format($box['width']*$box['length']*$box['height'],2,null,'')
			];
		}

		$taxRates = Tax::getTaxRates(true);

		// Do NOT use Tax::getTaxes(), Calculator::getTaxTotals(), Calculator::getTotals() etc, since this results in a recursion,
		// because it needs to know the shipping amount to calculate tax if the tax rate is set to tax on grandtotal

		$rateKey = 'TotalIncludingGSTAmount';
		if (count($taxRates) > 0) {
			// this is a bit of a cludge
			if ($taxRates[0]->getTaxBasedOn() === 'grandtotal') {
//				 subtotal = products only
//				 grandtotal = products+shippping
				$rateKey = 'TotalExcludingGSTAmount';
			}
		}

		$offers = [];

		//		$args['serviceLevel'] = ['code' => $this->serviceTypeB2B];
		$args['serviceLevel'] = ['code' => $this->serviceTypeDOM];

		$services = [$this->serviceTypeDOM,$this->serviceTypeB2B];

		foreach($services as $service) {
			if (! $service) {
				continue;
			}

			$args['serviceLevel'] = ['code' => $service];
			$cache = $expensiveCache = null;
			if (!$this->disableCaching) {
				$expensiveCache = app('cache/expensive');
				$cacheName = 'Mainfreight' . md5(json_encode($args));
				$cache = $expensiveCache->getItem($cacheName);
			}
			if ($cache && $cache->isHit()) {
				$rate = $cache->get();
				$this->log(Logger::DEBUG, t('Price cache hit %s %s', var_export($args, true), var_export($rate, true)));
			} else {
				$API = new Mainfreight();
				$API->setLogger($this->getConditionalLogger());

				if ($this->debugLogging) {
					$this->log(Logger::DEBUG, var_export(json_encode($args),true));
				}

				$rates = $API->getRates($args);
				/*
				 * {
				  "charges" : [ {
					"name" : "FreightAmount",
					"value" : 569.49
				  }, {
					"name" : "FuelAmount",
					"value" : 188.79
				  }, {
					"name" : "FuelPercentage",
					"value" : 33.15
				  }, {
					"name" : "OtherFeeAmount",
					"value" : 0.0
				  }, {
					"name" : "TotalExcludingGSTAmount",
					"value" : 758.28
				  }, {
					"name" : "TotalIncludingGSTAmount",
					"value" : 872.02
				  } ]
				}
				 */

				$this->log(Logger::DEBUG, t('Price cache miss %s %s', var_export($args, true), var_export($rates, true)));

				if (!$rates) {
					continue;
				}

				$rate = json_decode($rates);
				if (!$rate) {
					continue;
				}

				if ($expensiveCache) {
					$expensiveCache->save($cache->set($rate)->expiresAfter(3600));
				}
			}

			$showBoxSizes = \Config::get('mainfreight.showBoxSizes') ?? false;
			foreach ($rate->charges as $charge) {
				if ($charge->name == $rateKey) {
					$offer = new ShippingMethodOffer();
					$offer->setRate($charge->value);
					$offer->setOfferLabel(t('Mainfreight: %s', $service));
					if ($showBoxSizes) {
						$boxText = '';
						foreach ($boxes as $k => $box) {
							$boxText .= t('Box %s, %s x %s x %s, %sKg<br>', $k+1, $box['length'], $box['width'], $box['height'], $box['weight']);
						}
						$offer->setOfferDetails($boxText);
					}
					$offers[] = $offer;
				}
			}
		}

		return $offers;
	}
}
