<?php

namespace Drupal\fastly;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\fastly\Form\FastlySettingsForm;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Fastly API for Drupal.
 */
class Api {

  /**
   * The Fastly logger channel.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The purge method (instant / soft).
   *
   * @var string
   */
  private $purgeMethod;

  /**
   * @var \Drupal\fastly\State
   */
  protected $state;

  /**
   * Constructs a \Drupal\fastly\Api object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config.
   * @param string $host
   *   The host to use to talk to the Fastly API.
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client.
   * @param \Psr\Log\LoggerInterface $logger
   *   The Fastly logger channel.
   * @param \Drupal\fastly\State $state
   *   Fastly state service for Drupal.
   */
  public function __construct(ConfigFactoryInterface $config_factory, $host, ClientInterface $http_client, LoggerInterface $logger, State $state) {
    $config = $config_factory->get('fastly.settings');

    $this->apiKey = $config->get('api_key');
    $this->serviceId = $config->get('service_id');
    $this->purgeMethod = $config->get('purge_method');

    $this->host = $host;
    $this->httpClient = $http_client;
    $this->logger = $logger;
    $this->state = $state;
  }

  /**
   * Set API key.
   *
   * @paran string $api_key
   *  API key.
   */
  public function setApiKey($api_key) {
    $this->apiKey = $api_key;
  }

  /**
   * Used to validate API key.
   *
   * @return bool
   *   FALSE if any corrupt data is passed or token scope is inadequate.
   */
  public function validateApiKey() {
    try {
      $response = $this->query('/tokens/self');
      if ($response->getStatusCode() != 200) {
        return FALSE;
      }
      $json = $this->json($response);
      if (!empty($json->scopes)) {
        // GET /tokens/self will return scopes for the passed token, but that
        // alone is not enough to know if a token can perform purge actions.
        // Global scope tokens require the engineer or superuser role.
        $potentially_valid_purge_scopes = 'global';
        // Purge tokens require both purge_all and purge_select.
        $valid_purge_scopes = ['purge_all', 'purge_select'];

        if (array_intersect($valid_purge_scopes, $json->scopes) === $valid_purge_scopes) {
          return TRUE;
        }
        elseif (in_array($potentially_valid_purge_scopes, $json->scopes, TRUE)) {
          try {
            $response = $this->query('/current_user');
            if ($response->getStatusCode() != 200) {
              return FALSE;
            }
            $json = $this->json($response);
            if (!empty($json->role)) {
              if ($json->role === 'engineer' || $json->role === 'superuser') {
                return TRUE;
              }
              elseif ($json->role === 'billing' || $json->role === 'user') {
                return FALSE;
              }
              else {
                return FALSE;
              }
            }
          }
          catch (\Exception $e) {
            return FALSE;
          }
        }
        else {
          return FALSE;
        }
      }
    }
    catch (\Exception $e) {
      return FALSE;
    }
  }

  /**
   * Gets a list of services for the current customer.
   */
  public function getServices() {
    $response = $this->query('service');
    return $this->json($response);
  }

  /**
   * Purge whole service.
   *
   * @return bool
   *   FALSE if purge failed, TRUE is successful.
   *   */
  public function purgeAll() {
    if ($this->state->getPurgeCredentialsState()) {
      try {
        $response = $this->query('service/' . $this->serviceId . '/purge_all', [], 'POST');
        $result = $this->json($response);
        if ($result->status === 'ok') {
          $this->logger->info('Successfully purged all on Fastly.');
          return TRUE;
        }
        else {
          $this->logger->critical('Unable to purge all on Fastly. Response status: %status.', [
            '%status' => $result['status'],
          ]);
        }
      }
      catch (RequestException $e) {
        $this->logger->critical($e->getMessage());
      }
    }
    return FALSE;
  }

  /**
   * Performs an actual purge request for the given URL.
   *
   * @param string $url
   *   The full, valid URL to purge.
   *
   * @return bool
   *   FALSE if purge failed or URL is invalid, TRUE is successful.
   */
  public function purgeUrl($url = '') {

    // Validate URL -- this could be improved.
    // $url needs to be URL encoded. Need to make sure we can avoid double encoding.
    if ((strpos($url, 'http') === FALSE) && (strpos($url, 'https') === FALSE)) {
      return FALSE;
    }
    if (!UrlHelper::isValid($url, TRUE)) {
      return FALSE;
    }
    if (strpos($url, ' ') !== FALSE) {
      return FALSE;
    }

    if ($this->state->getPurgeCredentialsState()) {
      try {
        // Use POST to purge/* to handle requests with http scheme securely.
        // See: https://docs.fastly.com/guides/purging/authenticating-api-purge-requests#purging-urls-with-an-api-token
        $response = $this->query('purge/' . $url, [], 'POST');
        $result = $this->json($response);
        if ($result->status === 'ok') {
          $this->logger->info('Successfully purged URL %url. Purge Method: %purge_method.', [
            '%url' => $url,
            '%purge_method' => $this->purgeMethod,
          ]);
          return TRUE;
        }
        else {
          $this->logger->critical('Unable to purge URL %url from Fastly. Purge Method: %purge_method.', [
            '%url' => $url,
            '%purge_method' => $this->purgeMethod,
          ]);
        }
      }
      catch (RequestException $e) {
        $this->logger->critical($e->getMessage());
      }
    }
    return FALSE;
  }

  /**
   * Purge cache by key.
   *
   * @param array $keys
   *   A list of Surrogate Key values; in the case of Drupal: cache tags.
   *
   * @return bool
   *   FALSE if purge failed, TRUE is successful.
   */
  public function purgeKeys(array $keys = []) {
    if ($this->state->getPurgeCredentialsState()) {
      try {
        $response = $this->query('service/' . $this->serviceId . '/purge', [], 'POST', ["Surrogate-Key" => join(" ", $keys)]);
        $result = $this->json($response);
        if (count($result) > 0) {
          $this->logger->info('Successfully purged key(s) %key. Purge Method: %purge_method.', [
            '%key' => join(" ", $keys),
            '%purge_method' => $this->purgeMethod,
          ]);
          return TRUE;
        }
        else {
          $this->logger->critical('Unable to purge key(s) %key from Fastly. Purge Method: %purge_method.', [
            '%key' => join(" ", $keys),
            '%purge_method' => $this->purgeMethod,
          ]);
        }
      }
      catch (RequestException $e) {
        $this->logger->critical($e->getMessage());
      }
    }
    return FALSE;
  }

  /**
   * Performs http queries to Fastly API server.
   *
   * @param string $uri
   *   The uri to use for the request, appended to the host.
   * @param array $data
   *   (optional) Data to send with the request.
   * @param string $method
   *   (optional) The method to use for the request, defaults to GET.
   * @param array $headers
   *   (optional) An array of headers to send with the request.
   *
   * @return \Psr\Http\Message\ResponseInterface
   *   Response.
   *
   * @throws \GuzzleHttp\Exception\RequestException
   *   RequestException.
   */
  protected function query($uri, array $data = [], $method = 'GET', array $headers = []) {
    try {
      if (empty($data['headers'])) {
        $data['headers'] = $headers;
        $data['headers']['Accept'] = 'application/json';
        $data['headers']['Fastly-Key'] = $this->apiKey;

        // If the module is configured to use soft purging, we need to add
        // the appropriate header.
        if ($this->purgeMethod == FastlySettingsForm::FASTLY_SOFT_PURGE) {
          $data['headers']['Fastly-Soft-Purge'] = 1;
        }
      }
      switch (strtoupper($method)) {
        case 'GET':
          return $this->httpClient->request($method, $this->host . $uri, $data);

        case 'POST':
          return $this->httpClient->post($this->host . $uri, $data);

        case 'PURGE':
          return $this->httpClient->request($method, $uri, $data);

        default:
          throw new \Exception('Method :method is not valid for Fastly service.', [
            ':method' => $method,
          ]);
      }
    }
    catch (\Exception $e) {
      $this->logger->critical($e->getMessage());
    }
    return new Response();
  }

  /**
   * Get JSON from response.
   *
   * @param \Psr\Http\Message\ResponseInterface $response
   *   Response.
   *
   * @return \stdClass
   *   JSON object.
   */
  public function json(ResponseInterface $response) {
    return json_decode($response->getBody());
  }

  /**
   * Used to validate API token for purge related scope.
   *
   * @return bool
   *   TRUE if API token is capable of necessary purge actions, FALSE otherwise.
   */
  public function validatePurgeCredentials($apiKey = '') {
    if (empty($apiKey)) {
      return FALSE;
    }
    $this->setApiKey($apiKey);
    return $this->validateApiKey();
  }

}
