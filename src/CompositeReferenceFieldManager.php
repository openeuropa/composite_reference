<?php

declare(strict_types = 1);

namespace Drupal\composite_reference;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Database\Connection;
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
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * CompositeReferenceFieldManager constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The entity field manager.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, EntityFieldManagerInterface $entity_field_manager, Connection $database) {
    $this->entityTypeManager = $entity_type_manager;
    $this->entityFieldManager = $entity_field_manager;
    $this->database = $database;
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
        $referencing_entities = $entity_type_storage->loadMultiple($ids) + $referencing_entities;
      }
    }

    return $referencing_entities;
  }

  /**
   * {@inheritdoc}
   *
   * @see composite_reference_entity_predelete()
   */
  public function entityDelete(EntityInterface $entity, FieldDefinitionInterface $field_definition): void {
    if (!$this->isCompositeField($field_definition)) {
      return;
    }

    // When composite revisions config is set, we need to query all past
    // revisions for entities that were referenced.
    if ($this->isCompositeRevisionsField($field_definition)) {
      // Get the revision table name dedicated for the given field definition
      // for the query.
      $field_storage_definition = $field_definition->getFieldStorageDefinition();
      $entity_storage = $this->entityTypeManager->getStorage($entity->getEntityTypeId());
      $table_mapping = $entity_storage->getTableMapping();
      $table_name = $table_mapping->getDedicatedRevisionTableName($field_storage_definition);
      // Get the reference field column name in the table.
      $target_id_field = $table_mapping->getFieldColumnName($field_storage_definition, $field_storage_definition->getMainPropertyName());

      // Check if the field has a dedicated revision table otherwise we query
      // the revision data table of the entity. For example for nodes:
      // node_revision__field_name.
      if ($this->database->schema()->tableExists($table_name)) {
        $query = $this->database->select($table_name, 'r')
          ->fields('r', [$target_id_field])
          ->condition('entity_id', $entity->id())
          ->groupBy($target_id_field);
      }
      else {
        // Get the entity revision data table name from the entity type
        // definition for the query. For example for nodes: node_field_revision.
        $entity_type_definition = $this->entityTypeManager->getDefinition($entity->getEntityTypeId());
        $table_name = $this->entityTypeManager->getDefinition($entity->getEntityTypeId())->getRevisionDataTable();

        $query = $this->database->select($table_name, 'r')
          ->fields('r', [$target_id_field])
          ->condition($entity_type_definition->getKey('id'), $entity->id())
          ->isNotNull($target_id_field)
          ->groupBy($target_id_field);
      }
      $results = $query->execute()->fetchAllKeyed(0, 0);
      $referenced_entities = $this->entityTypeManager->getStorage($field_storage_definition->getSetting('target_type'))->loadMultiple($results);
    }
    else {
      // Gather the referenced entities from the actual revision revision.
      $referenced_entities = $entity->get($field_definition->getName())->referencedEntities();
    }

    /** @var \Drupal\Core\Entity\EntityInterface $referenced_entity */
    foreach ($referenced_entities as $referenced_entity) {
      $referencing_entities = $this->getReferencingEntities($referenced_entity);
      // Remove the host entity from the results. This has to be done because at
      // this moment the host entity is not yet deleted.
      if (array_key_exists($entity->id(), $referencing_entities)) {
        unset($referencing_entities[$entity->id()]);
      }

      if ($referenced_entity->uuid() !== $entity->uuid() && empty($referencing_entities)) {
        $referenced_entity->delete();
      }
    }
  }

  /**
   * Get the fields that reference entities of this type.
   *
   * @param string $referenced_entity_type
   *   The type of the referenced entity.
   *
   * @return array
   *   An array of field names, keyed by the entity type they reference from.
   */
  protected function getReferenceFields(string $referenced_entity_type): array {
    $entity_reference_map = $this->entityFieldManager->getFieldMapByFieldType('entity_reference');
    $entity_reference_revisions_map = $this->entityFieldManager->getFieldMapByFieldType('entity_reference_revisions');
    $map = NestedArray::mergeDeep($entity_reference_map, $entity_reference_revisions_map);
    $reference_fields = [];
    foreach ($map as $entity_type => $fields) {
      $definitions = array_filter($this->entityFieldManager->getFieldStorageDefinitions($entity_type), function ($definition, $field_name) use ($fields, $referenced_entity_type) {
        // Include only the fields types from our map and that reference the
        // entity type of the passed entity.
        return in_array($field_name, array_keys($fields)) && $definition->getSetting('target_type') === $referenced_entity_type;
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
      return (bool) $field_definition->getSetting('composite_reference')['composite'];
    }

    return FALSE;
  }

  /**
   * Asserts that the field definition is a composite revisions reference.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   The field definition.
   *
   * @return bool
   *   TRUE if it's composite revisions field, FALSE otherwise.
   */
  protected function isCompositeRevisionsField(FieldDefinitionInterface $field_definition): bool {
    if ($field_definition instanceof FieldConfigInterface) {
      // This works for both configurable fields, as well as base field
      // overrides.
      return $field_definition->getThirdPartySetting('composite_reference', 'composite_revisions', FALSE);
    }

    if ($field_definition instanceof BaseFieldDefinition) {
      return (bool) $field_definition->getSetting('composite_reference')['composite_revisions'];
    }

    return FALSE;
  }

}
