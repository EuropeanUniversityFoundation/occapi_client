<?php

namespace Drupal\occapi_entities_bridge;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\occapi_entities\Entity\Course;
use Drupal\occapi_entities\Entity\Programme;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Handles OCCAPI metadata.
 */
class OccapiMetadata implements OccapiMetadataInterface {

  use StringTranslationTrait;

  const ENTITY_PROGRAMME = OccapiEntityManagerInterface::ENTITY_PROGRAMME;
  const ENTITY_COURSE = OccapiEntityManagerInterface::ENTITY_COURSE;
  const ENTITY_REF = OccapiEntityManagerInterface::ENTITY_REF;

  const UNIQUE_ID = OccapiEntityManagerInterface::UNIQUE_ID;

  const FIELD_REMOTE_ID = OccapiRemoteDataInterface::FIELD_REMOTE_ID;
  const FIELD_META = OccapiRemoteDataInterface::FIELD_META;

  /**
   * The OCCAPI Course entity.
   *
   * @var \Drupal\occapi_entities\Entity\Course
   */
  protected $course;

  /**
   * The OCCAPI Programme entity.
   *
   * @var \Drupal\occapi_entities\Entity\Programme
   */
  protected $programme;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The renderer service.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * Constructs an OccapiMetaManager object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer service.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The string translation service.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    RendererInterface $renderer,
    TranslationInterface $string_translation
  ) {
    $this->entityTypeManager  = $entity_type_manager;
    $this->renderer           = $renderer;
    $this->stringTranslation  = $string_translation;
  }

  /**
   * Gets all Courses that reference the Programme as related.
   *
   * @param \Drupal\occapi_entities\Entity\Programme $programme
   *   An OCCAPI Programme entity.
   *
   * @return \Drupal\occapi_entities\Entity\Course[] $courses
   *   An array of OCCAPI Course entities keyed by entity ID.
   */
  public function relatedCourses(Programme $programme): array {
    $entity_id = $programme->id();
    $remote_id = $programme->get(self::FIELD_REMOTE_ID)->value;

    $courses = $this->entityTypeManager
      ->getStorage(self::ENTITY_COURSE)
      ->loadByProperties([
        self::ENTITY_REF[self::ENTITY_PROGRAMME] => $entity_id
      ]);

    return $courses;
  }

  /**
   * Gets all Programmes referenced by the Course as related.
   *
   * @param \Drupal\occapi_entities\Entity\Course $course
   *   An OCCAPI Programme entity.
   *
   * @return \Drupal\occapi_entities\Entity\Programme[] $programmes
   *   An array of OCCAPI Course entities keyed by entity ID.
   */
  public function relatedProgrammes(Course $course): array {
    $this->course = $course;

    $programmes = [];

    $referenced = $this->course
      ->get(self::ENTITY_REF[self::ENTITY_PROGRAMME])
      ->referencedEntities();

    foreach ($referenced as $i => $programme) {
      $programmes[$programme->id()] = $programme;
    }

    return $programmes;
  }

  /**
   * Get the metadata for all Programmes related to a Course.
   *
   * @param \Drupal\occapi_entities\Entity\Course $course
   *   An OCCAPI Programme entity.
   * @param \Drupal\occapi_entities\Entity\Programme[] $programmes
   *   An array of OCCAPI Programme entities.
   *
   * @return $metadata
   *   An array of metadata keyed by programme ID.
   */
  public function getMetaByCourse(Course $course, array $programmes): array {
    $this->course = $course;

    $json = $this->course->get(self::FIELD_META)->value;
    $data = \json_decode($json, TRUE);

    $term = $this->course->get(self::FIELD_COURSE_TERM)->value;

    $metadata = [];

    foreach ($programmes as $id => $programme) {
      $metadata[$id] = [];

      if (!empty($data)) {
        if (\array_key_exists(self::SCOPE_PROGRAMME, $data)) {
          $remote_id = (string) $programme->get(self::FIELD_REMOTE_ID)->value;

          foreach ($data[self::SCOPE_PROGRAMME] as $array) {
            $meta_programme_id = (string) $array[self::META_PROGRAMME_ID];

            if ($meta_programme_id === $remote_id) {
              $metadata[$id] = [
                self::SCOPE => self::SCOPE_PROGRAMME,
                self::META_YEAR => $array[self::META_YEAR],
                self::FIELD_COURSE_TERM => $term,
                self::META_PROGRAMME_MC => $array[self::META_PROGRAMME_MC]
              ];
            }
          }
        }
        elseif (\array_key_exists(self::SCOPE_GLOBAL, $data)) {
          $eqf_level = (int) $programme->get(self::FIELD_PROGRAMME_EQF)->value;

          $meta_global = $data[self::SCOPE_GLOBAL];
          $meta_global_eqf = (int) $meta_global[self::META_GLOBAL_EQF];

          if ($meta_global_eqf === $eqf_level) {
            $metadata[$id] = [
              self::SCOPE => self::SCOPE_GLOBAL,
              self::META_YEAR => $data[self::META_YEAR],
              self::FIELD_COURSE_TERM => $term,
              self::META_PROGRAMME_MC => FALSE
            ];
          }
        }
      }
    }

    return $metadata;
  }

  /**
   * Get the metadata for all Courses related to a Programme.
   *
   * @param \Drupal\occapi_entities\Entity\Programme $programme
   *   An OCCAPI Programme entity.
   * @param \Drupal\occapi_entities\Entity\Course[] $courses
   *   An array of OCCAPI Course entities.
   *
   * @return $metadata
   *   An array of metadata keyed by course ID.
   */
  public function getMetaByProgramme(Programme $programme, array $courses): array {
    $this->programme = $programme;

    $programmes = [$this->programme->id() => $this->programme];

    $metadata = [];

    foreach ($courses as $id => $course) {
      $course_metadata = $this->getMetaByCourse($course, $programmes);

      $metadata[$id] = $course_metadata[$this->programme->id()];
    }

    return $metadata;
  }

  /**
   * Format metadata by entity type as HTML table.
   *
   * @param array $metadata
   *   An array containing a JSON:API resource collection.
   * @param string $entity_type_id
   *   The entity type ID to format the primary column.
   *
   * @return string
   *   Rendered table markup.
   */
  public function metaTable(array $metadata, string $entity_type_id): string {
    $header = [
      $this->entityTypeManager->getDefinition($entity_type_id)->getLabel(),
      $this->t('Year'),
      $this->t('Term'),
      $this->t('Mandatory'),
      $this->t('Scope'),
    ];

    $rows = [];

    foreach ($metadata as $key => $value) {
      $entity = $this->entityTypeManager
        ->getStorage($entity_type_id)
        ->loadByProperties(['id' => $key]);

      $mandatory = (\array_key_exists(self::META_PROGRAMME_MC, $value))
        ? $value[self::META_PROGRAMME_MC]
        : FALSE;

      $rows[] = [
        $entity[$key]->toLink(),
        (\array_key_exists(self::META_YEAR, $value)) ?
          $value[self::META_YEAR] : '',
        $value[self::FIELD_COURSE_TERM] ?? '',
        ($mandatory) ? $this->t('Yes') : '',
        (\array_key_exists(self::SCOPE, $value)) ?
          $value[self::SCOPE] : '',
      ];
    }

    array_multisort(
      array_column($rows, 1),
      array_column($rows, 2),
      array_column($rows, 3), SORT_DESC,
      $rows
    );

    $build['table'] = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
    ];

    return $this->renderer->render($build);
  }

  /**
   * Format metadata by entity type as HTML table.
   *
   * @param array $metadata
   *   An array containing a JSON:API resource metadata.
   *
   * @return array
   *   The matching programmes in the system indexed by Drupal ID.
   */
  public function getProgrammeId(array $metadata): array {
    $entity_ids = [];

    if (\array_key_exists(self::SCOPE_PROGRAMME, $metadata)) {
      foreach ($metadata[self::SCOPE_PROGRAMME] as $item) {
        $programme_ids[] = $item[self::META_PROGRAMME_ID];
      }
    }

    if (!empty($programme_ids)) {
      foreach ($programme_ids as $programme_id) {
        $exists = $this->entityTypeManager
          ->getStorage(self::ENTITY_PROGRAMME)
          ->loadByProperties([
            self::UNIQUE_ID[self::ENTITY_PROGRAMME] => $programme_id
          ]);
        foreach ($exists as $key => $value) {
          $entity_ids[$key] = $value;
        }
      }
    }

    return $entity_ids;
  }

}
