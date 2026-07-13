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
use CommunityStoreMainfreight\Posthaste;

/**
 * Shipping method for the Post Haste carrier, via the Freightways "Customer Integration" API.
 *
 * @see https://app.swaggerhub.com/apis/Freightways/published-customer-integration/1.33
 * @see https://freightways.atlassian.net/wiki/spaces/FCIP/pages/460423418/Technical+Documentation
 *
 * @ORM\Entity
 * @ORM\Table(name="CommunityStorePosthasteRateMethods")
 */
class PosthasteShippingMethod extends ShippingMethodTypeMethod implements LoggerAwareInterface {
	use ShippingMethodSharedTrait;

	public function getLoggerChannel () {
		return 'posthaste';
	}

	public function getShippingMethodTypeName () {
		return t('Post Haste');
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

		$args = [
			'consignmentDirection' => 'Standard',
			'pickupAddress' => [
				'street' => $pickup['street'] ?? '',
				'suburb' => $pickup['suburb'] ?? '',
				'town' => $pickup['city'] ?? '',
				'postCode' => $pickup['postcode'] ?? '',
				'country' => 'New Zealand',
			],
			'deliveryAddress' => [
				'street' => $add1,
				'suburb' => $add2 ?: '',
				'town' => $city,
				'postCode' => $postCode,
				'country' => 'New Zealand',
			],
		];

		foreach ($boxes as $box) {
			$args['standardItems'][] = [
				'volume' => round($box['width'] * $box['length'] * $box['height'], 3),
				'weight' => $box['weight'],
				'dangerousGoods' => false,
			];
		}

		$taxRates = Tax::getTaxRates(true);

		// Do NOT use Tax::getTaxes(), Calculator::getTaxTotals(), Calculator::getTotals() etc, since this results in a recursion,
		// because it needs to know the shipping amount to calculate tax if the tax rate is set to tax on grandtotal
		$addGST = true;
		if (count($taxRates) > 0 && $taxRates[0]->getTaxBasedOn() === 'grandtotal') {
			// grandtotal = products+shipping, so pass the ex-GST rate through and let
			// Community Store apply GST to products+shipping together
			$addGST = false;
		}

		$cache = $expensiveCache = null;
		if (!$this->disableCaching) {
			$expensiveCache = app('cache/expensive');
			$cacheName = 'Posthaste' . md5(json_encode($args));
			$cache = $expensiveCache->getItem($cacheName);
		}

		if ($cache && $cache->isHit()) {
			$rates = $cache->get();
			$this->log(Logger::DEBUG, t('Price cache hit %s %s', var_export($args, true), var_export($rates, true)));
		} else {
			$API = new Posthaste();
			$API->setLogger($this->getConditionalLogger());

			if ($this->debugLogging) {
				$this->log(Logger::DEBUG, var_export(json_encode($args), true));
			}

			$result = $API->getRates($args);

			$this->log(Logger::DEBUG, t('Price cache miss %s %s', var_export($args, true), var_export($result, true)));

			if (!$result) {
				return [];
			}

			$rates = json_decode($result);
			if (!$rates) {
				return [];
			}

			if ($expensiveCache) {
				$expensiveCache->save($cache->set($rates)->expiresAfter(3600));
			}
		}

		if (!is_array($rates)) {
			return [];
		}

		$showBoxSizes = \Config::get('mainfreight.showBoxSizes') ?? false;
		$offers = [];

		foreach ($rates as $rate) {
			if (!isset($rate->totalRateExcludingGst)) {
				continue;
			}

			$rateAmount = (float) $rate->totalRateExcludingGst;
			if ($addGST && count($taxRates) > 0) {
				$rateAmount = round($rateAmount * (1 + ($taxRates[0]->getTaxRate() / 100)), 2);
			}

			$offer = new ShippingMethodOffer();
			$offer->setRate($rateAmount);
			$offer->setOfferLabel(isset($rate->serviceStandard) ? t('Post Haste: %s', $rate->serviceStandard) : t('Post Haste'));
			if ($showBoxSizes) {
				$boxText = '';
				foreach ($boxes as $k => $box) {
					$boxText .= t('Box %s, %s x %s x %s, %sKg<br>', $k + 1, $box['length'], $box['width'], $box['height'], $box['weight']);
				}
				$offer->setOfferDetails($boxText);
			}
			$offers[] = $offer;
		}

		return $offers;
	}
}
