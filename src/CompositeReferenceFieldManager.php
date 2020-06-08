<?php

declare(strict_types = 1);

namespace Drupal\composite_reference;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\field\FieldConfigInterface;

/**
 * Manager class for composite reference fields.
 */
class CompositeReferenceFieldManager implements CompositeReferenceFieldManagerInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * CompositeReferenceFieldManager constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public function getReferencingEntities(EntityInterface $entity): array {
    $referencing_entities = [];
    // Get all entity reference fields.
    $field_config_storage = $this->entityTypeManager->getStorage('field_config');
    $entity_reference_field_ids = $field_config_storage->getQuery()
      ->condition('field_type', ['entity_reference', 'entity_reference_revisions'], 'IN')
      ->execute();
    $entity_reference_fields = $field_config_storage->loadMultiple($entity_reference_field_ids);

    // Only use fields that reference the given entity's type.
    $fields_referencing_entity_type = [];
    /** @var \Drupal\field\FieldConfigInterface $field */
    foreach ($entity_reference_fields as $field) {
      $field_settings = $field->getSettings();
      if ($field_settings['handler'] === 'default:' . $entity->getEntityTypeId()) {
        $fields_referencing_entity_type[$field->getTargetEntityTypeId()][] = $field->getName();
      }
    }

    // Load all entities that have a reference to the given entity.
    foreach ($fields_referencing_entity_type as $field_entity_type_id => $field_names) {
      $entity_type_storage = $this->entityTypeManager->getStorage($field_entity_type_id);
      $query = $entity_type_storage->getQuery('OR');
      foreach ($field_names as $field_name) {
        $query->condition($field_name, $entity->id());
      }
      $ids = $query->execute();
      if ($ids) {
        $referencing_entities = array_merge($referencing_entities, $entity_type_storage->loadMultiple($ids));
      }
    }
    return $referencing_entities;
  }

  /**
   * {@inheritdoc}
   */
  public function onDelete(EntityInterface $entity, FieldDefinitionInterface $field_definition): void {
    if ($field_definition instanceof FieldConfigInterface && $field_definition->getThirdPartySetting('composite_reference', 'composite', FALSE)) {
      $referenced_entities = $entity->get($field_definition->getName())->referencedEntities();
      /** @var \Drupal\Core\Entity\EntityInterface $referenced_entity */
      foreach ($referenced_entities as $referenced_entity) {
        if ($referenced_entity->id() !== $entity->id() && empty($this->getReferencingEntities($referenced_entity))) {
          $referenced_entity->delete();
        }
      }
    }
  }

}
