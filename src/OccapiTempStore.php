<?php

namespace Drupal\occapi_client;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\TempStore\SharedTempStoreFactory;

/**
 * Shared TempStore manager.
 */
class OccapiTempStore implements OccapiTempStoreInterface {

  use StringTranslationTrait;

  /**
   * An instance of the key/value store.
   *
   * @var \Drupal\Core\TempStore\SharedTempStore
   */
  protected $tempStore;

  /**
   * Constructs an OccapiTempStore object.
   *
   * @param \Drupal\Core\TempStore\SharedTempStoreFactory $temp_store_factory
   *   The factory for the temp store object.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The string translation service.
   */
  public function __construct(
    SharedTempStoreFactory $temp_store_factory,
    TranslationInterface $string_translation
  ) {
    $this->tempStore         = $temp_store_factory->get('occapi_client');
    $this->stringTranslation = $string_translation;
  }

  /**
   * Extract parameters from a TempStore key.
   *
   * @param string $temp_store_key
   *   The TempStore key.
   *
   * @return array
   *   The TempStore parameters.
   */
  public function paramsFromKey(string $temp_store_key): array {
    // Handle the Institution scenario first: ID main contain separator.
    $parts = \explode(self::TEMPSTORE_KEY_SEPARATOR, $temp_store_key, 3);

    if ($parts[1] === self::JSONAPI_TYPE_HEI) {
      $temp_store_params = [
        self::PARAM_PROVIDER => $parts[0],
        self::PARAM_FILTER_TYPE => NULL,
        self::PARAM_FILTER_ID => NULL,
        self::PARAM_RESOURCE_TYPE => $parts[1],
        self::PARAM_RESOURCE_ID => $parts[2] ?? NULL,
      ];

      return $temp_store_params;
    }

    // Handle the generic scenario, check for filters.
    $parts = \explode(self::TEMPSTORE_KEY_SEPARATOR, $temp_store_key);

    $is_filtered = (\count($parts) > 3);

    $temp_store_params = [
      self::PARAM_PROVIDER => $parts[0],
      self::PARAM_FILTER_TYPE => ($is_filtered) ? $parts[1] : NULL,
      self::PARAM_FILTER_ID => ($is_filtered) ? $parts[2] : NULL,
      self::PARAM_RESOURCE_TYPE => ($is_filtered) ? $parts[3] : $parts[1],
      self::PARAM_RESOURCE_ID => ($is_filtered)
        ? $parts[4] ?? NULL
        : $parts[2] ?? NULL,
    ];

    return $temp_store_params;
  }

  /**
   * Build a TempStore key from parameters.
   *
   * @param array $temp_store_params
   *   The TempStore parameters.
   *
   * @return string
   *   The TempStore key.
   */
  public function keyFromParams(array $temp_store_params): string {
    $parts = [$temp_store_params[self::PARAM_PROVIDER]];

    $has_filter_type = !empty($temp_store_params[self::PARAM_FILTER_TYPE]);
    $has_filter_id = !empty($temp_store_params[self::PARAM_FILTER_ID]);

    if ($has_filter_type && $has_filter_id) {
      $parts[] = $temp_store_params[self::PARAM_FILTER_TYPE];
      $parts[] = $temp_store_params[self::PARAM_FILTER_ID];
    }

    $parts[] = $temp_store_params[self::PARAM_RESOURCE_TYPE];

    if (!empty($temp_store_params[self::PARAM_RESOURCE_ID])) {
      $parts[] = $temp_store_params[self::PARAM_RESOURCE_ID];
    }

    $temp_store_key = \implode(self::TEMPSTORE_KEY_SEPARATOR, $parts);

    return $temp_store_key;
  }

  /**
   * Validate a TempStore key by parameters.
   *
   * @param string $temp_store_key
   *   The TempStore key.
   * @param boolean $single
   *   Whether the key refers to a single resource (defaults to FALSE).
   *
   * @return string|null
   *   The error message if any error is detected.
   */
  public function validateTempstoreKey(string $temp_store_key, bool $single = FALSE): ?string {
    $temp_store_params = $this->paramsFromKey($temp_store_key);

    if (empty($temp_store_params[self::PARAM_PROVIDER])) {
      return $this->t('Empty parameter: %param', [
        '%param' => self::PARAM_PROVIDER
      ]);
    }

    if (empty($temp_store_params[self::PARAM_RESOURCE_TYPE])) {
      return $this->t('Empty parameter: %param', [
        '%param' => self::PARAM_RESOURCE_TYPE
      ]);
    }

    if ($single && empty($temp_store_params[self::PARAM_RESOURCE_ID])) {
      return $this->t('Missing resource ID for single resource.');
    }

    if (!$single && !empty($temp_store_params[self::PARAM_RESOURCE_ID])) {
      return $this->t('Unexpected resource ID for resource collection.');
    }

    $no_filter_type = (empty($temp_store_params[self::PARAM_FILTER_TYPE]));
    $no_filter_id = (empty($temp_store_params[self::PARAM_FILTER_ID]));

    if ($no_filter_type && !$no_filter_id) {
      return $this->t('Filter type provided, missing filter ID.');
    }

    if (!$no_filter_type && $no_filter_id) {
      return $this->t('Filter ID provided, missing filter type.');
    }

    return NULL;
  }

  /**
   * Validate a collection TempStore key.
   *
   * @param string $temp_store_key
   *   TempStore key to validate.
   * @param string $resource_type|null
   *   OCCAPI entity type key to validate.
   * @param string $filter_type|null
   *   OCCAPI entity type key used as filter.
   *
   * @return string|null
   *   The error message if any error is detected.
   */
  public function validateCollectionTempstore(string $temp_store_key, ?string $resource_type = NULL, ?string $filter_type = NULL): ?string {
    $error = $this->validateTempstoreKey($temp_store_key);

    if (!empty($error)) { return $error; }

    $temp_store_params = $this->paramsFromKey($temp_store_key);

    // Validate the provider.

    // Validate the resource type.
    if (!empty($resource_type)) {
      $allowed_types = [
        self::JSONAPI_TYPE_PROGRAMME,
        self::JSONAPI_TYPE_COURSE,
      ];

      $error = $this->validateTempstoreType($resource_type, $allowed_types);

      if (!empty($error)) { return $error; }

      $param_resource_type = $temp_store_params[self::PARAM_RESOURCE_TYPE];

      if ($resource_type !== $param_resource_type) {
        return $this->t('Data contains %param instead of %type.', [
          '%param' => $param_resource_type,
          '%type' => $resource_type,
        ]);
      }
    }

    // Validate the filter type.
    if (!empty($filter_type)) {
      $allowed_types = [
        self::JSONAPI_TYPE_OUNIT,
        self::JSONAPI_TYPE_PROGRAMME
      ];

      $error = $this->validateResourceType($filter_type, $allowed_types);

      if (!empty($error)) { return $error; }

      $param_filter_type = $temp_store_params[self::PARAM_FILTER_TYPE];

      if (!empty($filter_type) && ($filter_type !== $param_filter_type)) {
        return $this->t('Data is filtered by %param instead of %type.', [
          '%param' => $param_filter_type,
          '%type' => $filter_type,
        ]);
      }
    }

    // No errors found.
    return NULL;
  }

  /**
   * Validate a resource TempStore key.
   *
   * @param string $temp_store_key
   *   TempStore key to validate.
   * @param string $resource_type|null
   *   OCCAPI entity type key to validate.
   *
   * @return string|null
   *   The error message if any error is detected.
   */
  public function validateResourceTempstore(string $temp_store_key, string $resource_type): ?string {
    $error = $this->validateTempstoreKey($temp_store_key, TRUE);

    if (!empty($error)) { return $error; }

    $temp_store_params = $this->paramsFromKey($temp_store_key);

    // Validate the resource type.
    if (!empty($resource_type)) {
      $allowed_types = [
        self::JSONAPI_TYPE_PROGRAMME,
        self::JSONAPI_TYPE_COURSE,
      ];

      $error = $this->validateTempstoreType($resource_type, $allowed_types);

      if (!empty($error)) { return $error; }

      $param_resource_type = $temp_store_params[self::PARAM_RESOURCE_TYPE];

      if ($resource_type !== $param_resource_type) {
        return $this->t('Data contains %param instead of %type.', [
          '%param' => $param_resource_type,
          '%type' => $resource_type,
        ]);
      }
    }

    // No errors found.
    return NULL;
  }

  /**
   * Validate a TempStore key resource type.
   *
   * @param string $resource_type
   *   Resource type to validate.
   * @param array $allowed_types
   *   Allowed resource types.
   *
   * @return string|null
   *   The error message if any error is detected.
   */
  public function validateResourceType(string $resource_type, array $allowed_types): ?string {
    if (!\in_array($resource_type, $allowed_types)) {
      return $this->t('Resource type must be one of %allowed, %type given.', [
        '%allowed' => \implode(', ', $allowed_types),
        '%type' => $resource_type,
      ]);
    }

    // No errors found.
    return NULL;
  }

  /**
   * Check the TempStore for the updated date.
   *
   * @param string $temp_store_key
   *   The TempStore key.
   *
   * @return int|null
   *   A UNIX timestamp or NULL.
   */
  public function checkUpdated(string $temp_store_key): ?int {
    if (!empty($this->tempStore->get($temp_store_key))) {
      return $this->tempStore->getMetadata($temp_store_key)->getUpdated();
    } else {
      return NULL;
    }
  }

}