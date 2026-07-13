<?php

namespace CommunityStoreMainfreight;

use Concrete\Core\Logging\LoggerAwareInterface;
use Concrete\Core\Logging\LoggerAwareTrait;
use Concrete\Core\Logging\LoggerFactory;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;

/**
 * Client for the Freightways "Customer Integration" API (used by the Post Haste carrier).
 *
 * @see https://app.swaggerhub.com/apis/Freightways/published-customer-integration/1.33
 * @see https://freightways.atlassian.net/wiki/spaces/FCIP/pages/460423418/Technical+Documentation
 */
class Posthaste implements LoggerAwareInterface {
	use LoggerAwareTrait;

	private $baseUrl;
	private $accountID;
	private $apiID;
	private $secretKey;
	private $env;

	public function __construct () {
		$sandbox = (bool) \Config::get('posthaste.posthaste_sandbox');
		$this->env = $sandbox ? 'dev' : 'live';
		$env = \Config::get('posthaste.' . $this->env);
		$env = is_array($env) ? $env : [];

		$this->baseUrl = rtrim($env['baseUrl'] ?? '', '/');
		$this->accountID = $env['accountID'] ?? '';
		$this->apiID = $env['apiID'] ?? '';
		$this->secretKey = $env['secretKey'] ?? '';

		$this->logger = app(LoggerFactory::class)->createLogger($this->getLoggerChannel());
	}

	public function getLoggerChannel () {
		return 'posthaste';
	}

	/**
	 * Freightways hosts the OAuth2 token endpoint on a sibling "auth" subdomain to the
	 * configured API base URL (e.g. customer-integration.ep-sandbox.freightways.co.nz ->
	 * auth.ep-sandbox.freightways.co.nz), so we derive it rather than storing a second URL.
	 */
	private function getTokenUrl (): string {
		$scheme = parse_url($this->baseUrl, PHP_URL_SCHEME) ?: 'https';
		$host = parse_url($this->baseUrl, PHP_URL_HOST) ?: '';

		$parts = explode('.', $host);
		array_shift($parts);
		array_unshift($parts, 'auth');

		return $scheme . '://' . implode('.', $parts) . '/oauth2/token';
	}

	private function getAccessToken (): ?string {
		$configKey = 'posthaste.accessToken.' . $this->env;
		$stored = \Config::get($configKey);

		if (is_array($stored) && !empty($stored['token']) && (int) ($stored['expiry'] ?? 0) > time()) {
			return $stored['token'];
		}

		$guzza = new Client(['timeout' => 10, 'connect_timeout' => 10]);

		try {
			$response = $guzza->request('POST', $this->getTokenUrl(), [
				'auth' => [$this->apiID, $this->secretKey],
				'form_params' => ['grant_type' => 'client_credentials'],
				'headers' => ['Accept' => 'application/json'],
			]);

			$body = json_decode($response->getBody()->getContents(), true);
			$token = $body['access_token'] ?? null;

			if (!$token) {
				return null;
			}

			// Per the Freightways docs, expires_in is unreliable - read the JWT's own exp claim.
			$expiry = $this->getTokenExpiry($token) - 60;
			\Config::save($configKey, ['token' => $token, 'expiry' => $expiry]);

			return $token;
		} catch (ClientException $e) {
			$this->logger->emergency(t('Posthaste OAuth token request failed: %s', $e->getResponse()->getBody()));
		} catch (RequestException $e) {
			$this->logger->emergency(t('Posthaste OAuth token request failed: %s', $e->getResponse()->getBody()));
		} catch (\Exception $e) {
			$this->logger->emergency(t('Posthaste OAuth token request failed: %s', $e->getResponse()->getBody()));
		}

		return null;
	}

	private function getTokenExpiry (string $jwt): int {
		$segments = explode('.', $jwt);
		if (count($segments) !== 3) {
			return time() + 300;
		}

		$payloadB64 = strtr($segments[1], '-_', '+/');
		$payloadB64 .= str_repeat('=', (4 - strlen($payloadB64) % 4) % 4);
		$payload = json_decode(base64_decode($payloadB64), true);

		return (int) ($payload['exp'] ?? (time() + 300));
	}

	/**
	 * @see POST /v1/rates
	 */
	public function getRates ($payload) {
		$token = $this->getAccessToken();
		if (!$token) {
			return null;
		}

		$guzza = new Client(['timeout' => 10, 'connect_timeout' => 10]);
		$url = $this->baseUrl . '/v1/rates';

		$headers = [
			'Authorization' => 'Bearer ' . $token,
			'Accept' => 'application/json',
		];

		$params = [
			'headers' => $headers,
			'json' => $payload,
		];

		try {
			$response = $guzza->request('POST', $url, $params);

			return $response->getBody()->getContents();
		} catch (ClientException $e) {
			$error = $e->getResponse()->getBody();
			$this->logger->emergency(t('Client Exception: %s, Payload: %s', $error, json_encode($payload)));
		} catch (RequestException $e) {
			$error = $e->getResponse() ? $e->getResponse()->getBody() : $e->getMessage();
			$this->logger->emergency(t('Request Exception: %s, Payload: %s', $error, json_encode($payload)));
		} catch (\Exception $e) {
			$this->logger->emergency(t('General Exception: %s, Payload: %s', $e->getMessage(), json_encode($payload)));
		}

		return null;
	}
}
