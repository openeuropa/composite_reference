<?php

declare(strict_types = 1);

namespace Drupal\composite_reference;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Field\FieldConfigInterface;
use Drupal\Core\Field\FieldDefinitionInterface;

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
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * CompositeReferenceFieldManager constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The entity field manager.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, EntityFieldManagerInterface $entity_field_manager) {
    $this->entityTypeManager = $entity_type_manager;
    $this->entityFieldManager = $entity_field_manager;
  }

  /**
   * {@inheritdoc}
   */
  public function getReferencingEntities(EntityInterface $entity): array {
    $referencing_entities = [];

    $reference_fields = $this->getReferenceFields($entity->getEntityTypeId());

    // Load all entities that have a reference to the given entity.
    foreach ($reference_fields as $entity_type => $field_names) {
      $entity_type_storage = $this->entityTypeManager->getStorage($entity_type);
      $query = $entity_type_storage->getQuery('OR');
      foreach ($field_names as $field_name) {
        $query->condition("$field_name.target_id", $entity->id());
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
   *
   * @see composite_reference_entity_delete()
   */
  public function entityDelete(EntityInterface $entity, FieldDefinitionInterface $field_definition): void {
    if (!$this->isCompositeField($field_definition)) {
      return;
    }
    $referenced_entities = $entity->get($field_definition->getName())->referencedEntities();
    /** @var \Drupal\Core\Entity\EntityInterface $referenced_entity */
    foreach ($referenced_entities as $referenced_entity) {
      if ($referenced_entity->id() !== $entity->id() && empty($this->getReferencingEntities($referenced_entity))) {
        $referenced_entity->delete();
      }
    }
  }

  /**
   * Get the fields that reference entities of this type.
   *
   * @param string $entity_type
   *   The entity type.
   *
   * @return array
   *   An array of field names, keyed by the entity type they reference from.
   */
  protected function getReferenceFields(string $entity_type): array {
    $entity_reference_map = $this->entityFieldManager->getFieldMapByFieldType('entity_reference');
    $entity_reference_revisions_map = $this->entityFieldManager->getFieldMapByFieldType('entity_reference_revisions');
    $map = NestedArray::mergeDeep($entity_reference_map, $entity_reference_revisions_map);
    $reference_fields = [];
    foreach ($map as $entity_type => $fields) {
      $definitions = array_filter($this->entityFieldManager->getFieldStorageDefinitions($entity_type), function ($definition, $field_name) use ($fields, $entity_type) {
        // Include only the fields types from our map and that reference the
        // entity type of the passed entity.
        return in_array($field_name, array_keys($fields)) && $definition->getSetting('target_type') === $entity_type;
      }, ARRAY_FILTER_USE_BOTH);

      $reference_fields[$entity_type] = array_keys($definitions);
    }

    return array_filter($reference_fields);
  }

  /**
   * Asserts that the field definition is a composite reference.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   The field definition.
   *
   * @return bool
   *   TRUE if it's composite, FALSE otherwise.
   */
  protected function isCompositeField(FieldDefinitionInterface $field_definition): bool {
    if ($field_definition instanceof FieldConfigInterface) {
      // This works for both configurable fields, as well as base field
      // overrides.
      return $field_definition->getThirdPartySetting('composite_reference', 'composite', FALSE);
    }

    if ($field_definition instanceof BaseFieldDefinition) {
      return (bool) $field_definition->getSetting('composite_reference');
    }

    return FALSE;
  }

}
