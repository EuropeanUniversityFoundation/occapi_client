<?php

namespace Drupal\occapi_entities_bridge\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\occapi_entities\Entity\Programme;
use Drupal\occapi_entities_bridge\OccapiImportManager;
use Drupal\occapi_entities_bridge\OccapiMetaManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Returns responses for OCCAPI entities bridge routes.
 */
class OccapiProgrammeMetaController extends ControllerBase {

  /**
   * The OCCAPI Programme entity.
   *
   * @var \Drupal\occapi_entities\Entity\Programme
   */
  protected $entity;

  /**
   * OCCAPI metadata manager service.
   *
   * @var \Drupal\occapi_entities_bridge\OccapiMetaManager
   */
  protected $metaManager;

  /**
   * Constructs an OccapiProgrammeImportController object.
   *
   * @param \Drupal\occapi_entities_bridge\OccapiMetaManager $meta_manager
   *   The OCCAPI entity import manager service.
   */
  public function __construct(
    OccapiMetaManager $meta_manager
  ) {
    $this->metaManager = $meta_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('occapi_entities_bridge.meta')
    );
  }

  /**
   * Provides a title callback for related Courses.
   *
   * @return string
   *   The title for the entity controller.
   */
  public function relatedCoursesTitle() {
    return $this->t('Related courses');
  }

  /**
   * Builds the response for related Courses.
   */
  public function relatedCourses(Programme $programme) {
    $this->entity = $programme;

    $courses = $this->metaManager
      ->relatedCourses($this->entity);

    $metadata = $this->metaManager
      ->getMetaByProgramme($this->entity, $courses);

    $markup = $this->metaManager
      ->metaTable($metadata, OccapiImportManager::COURSE_ENTITY);

    $build['content'] = [
      '#type' => 'item',
      '#markup' => $markup,
    ];

    return $build;
  }

}
