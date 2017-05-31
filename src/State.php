<?php

namespace Drupal\fastly;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\State\StateInterface;

/**
 * Tracks validity of credentials associated with Fastly Api.
 */
class State {

  const VALID_PURGE_CREDENTIALS = 'fastly.state.valid_purge_credentials';

  /**
   * @var \Drupal\Core\Config\Config
   */
  protected $config;

  /**
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * @var \Drupal\fastly\Api
   */
  protected $fastlyApi;

  /**
   * @var bool
   */
  protected $validPurgeCredentials;

  /**
   * ValidateCredentials constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config
   *   Fastly config object.
   * @param \Drupal\Core\State\StateInterface $state
   *   The drupal state service.
   * @param \Drupal\fastly\Api $fastlyApi
   *   Fastly API for Drupal.
   */
  public function __construct(ConfigFactoryInterface $config, StateInterface $state, Api $fastlyApi) {
    $this->config = $config->get('fastly.settings');
    $this->state = $state;
    $this->fastlyApi = $fastlyApi;
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
    $this->fastlyApi->setApiKey($apiKey);
    return $this->fastlyApi->validateApiKey();
  }

  /**
   * Get the Drupal state representing whether or not the configured Fastly Api
   * credentials are sufficient to perform all supported types of purge requests.
   *
   * @return mixed
   */
  public function getPurgeCredentialsState() {
    $state = $this->state->get(self::VALID_PURGE_CREDENTIALS);
    return $state;
  }

  /**
   * Get the Drupal state representing whether or not the configured Fastly Api
   * credentials are sufficient to perform all supported types of purge requests.
   */
  public function setPurgeCredentialsState($state = FALSE) {
    $this->state->set(self::VALID_PURGE_CREDENTIALS, $state);
  }

}
