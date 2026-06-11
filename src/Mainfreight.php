<?php

namespace CommunityStoreMainfreight;


use Concrete\Core\Http\Request;
use Concrete\Core\Http\Response;
use Concrete\Core\Logging\LoggerAwareInterface;
use Concrete\Core\Logging\LoggerAwareTrait;
use Concrete\Core\Logging\LoggerFactory;
use Concrete\Core\Routing\RedirectResponse;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

class Mainfreight implements LoggerAwareInterface {
	private $APIURL = 'https://api.mainfreight.com/transport/1.0/';
	// 'https://api.mainfreight.com/transport/1.0/customer/rate?region=NZhttps://api.uat.nzpost.co.nz/';
	private $APIKey;
	private $accountID;




	public function __construct () {
		$config = \Config::get('mainfreight');
		$this->APIKey = $config['APIKey'];
		$this->accountID = $config['accountID'];
		$this->logger = app(LoggerFactory::class)->createLogger($this->getLoggerChannel());
	}


	public function getRates ($payload) {
		$payload['account'] = ['code' => $this->accountID];

		$guzza = new Client(['timeout' => 10, 'connect_timeout' => 10]);

		$url = $this->APIURL . 'customer/rate';

		$headers = [
			'Authorization' => 'Secret ' . $this->APIKey,
			'Accept' => 'application/json'
		];

		$params = [
			'headers' => $headers,
			'query' => ['region' => 'NZ'],
			'json' => $payload
		];

		try {
			$response = $guzza->request('POST', $url, $params);

			$result = $response->getBody()->getContents();

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

			return $result;

		} catch (ClientException $e) {
			$this->logger->emergency(t('Client Exception: %s, Payload: %s', $e->getMessage(), json_encode($payload)));
		} catch (RequestException $e) {
			$this->logger->emergency(t('Request Exception: %s, Payload: %s', $e->getMessage(), json_encode($payload)));
		} catch (\Exception $e) {
			$this->logger->emergency(t('General Exception: %s, Payload: %s', $e->getMessage(), json_encode($payload)));
		}

		return null;
	}


	use LoggerAwareTrait;

	public function getLoggerChannel () {
		return 'mainfreight';
	}
}