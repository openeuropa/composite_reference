<?php

declare(strict_types=1);

namespace Drupal\Tests\composite_reference\Kernel;

use Drupal\Core\Field\Entity\BaseFieldOverride;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\KernelTests\Core\Entity\EntityKernelTestBase;
use Drupal\Tests\composite_reference\Traits\CompositeReferenceTestTrait;

/**
 * Tests composite reference fields.
 */
class CompositeFieldsTest extends EntityKernelTestBase {

  use CompositeReferenceTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'field',
    'entity_test',
    'link',
    'node',
    'system',
    'text',
    'user',
    'entity_reference_revisions',
    'composite_reference',
    'composite_reference_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installSchema('node', 'node_access');
    $this->installEntitySchema('entity_test');
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installConfig(['field', 'node']);
  }

  /**
   * Test the composite option of entity reference fields.
   *
   * @SuppressWarnings(PHPMD.CyclomaticComplexity)
   * @SuppressWarnings(PHPMD.NPathComplexity)
   */
  public function testCompositeOption(): void {
    // Create a test content type.
    $type = $this->entityTypeManager->getStorage('node_type')
      ->create(['name' => 'Test content type', 'type' => 'test_ct']);
    $type->save();

    $reference_field_definitions = [
      [
        'field_name' => 'entity_reference_field',
        'field_label' => 'Entity reference field',
        'revisions' => FALSE,
        'field_type' => 'field config',
        'target_type' => 'entity_test',
      ],
      [
        'field_name' => 'entity_reference_revisions_field',
        'field_label' => 'Entity reference revisions field',
        'revisions' => TRUE,
        'field_type' => 'field config',
        'target_type' => 'node',
      ],
      [
        'field_name' => 'entity_reference',
        'field_label' => 'Entity reference field',
        'revisions' => FALSE,
        'field_type' => 'base field',
        'target_type' => 'node',
      ],
      [
        'field_name' => 'entity_reference_revisions',
        'field_label' => 'Entity reference revisions field',
        'revisions' => TRUE,
        'field_type' => 'base field',
        'target_type' => 'node',
      ],
      [
        'field_name' => 'entity_reference_override',
        'field_label' => 'Entity reference override field',
        'revisions' => FALSE,
        'field_type' => 'base field override',
        'target_type' => 'node',
      ],
      [
        'field_name' => 'entity_reference_revisions_override',
        'field_label' => 'Entity reference revisions override field',
        'revisions' => TRUE,
        'field_type' => 'base field override',
        'target_type' => 'node',
      ],
    ];

    foreach ($reference_field_definitions as $field_definition) {
      // For field configs, we need to create the field definitions.
      if ($field_definition['field_type'] === 'field config') {
        // Create an entity reference field for the test content type.
        $entity_reference_field = $this->createEntityReferenceField('node', $type->id(), $field_definition['field_name'], $field_definition['field_label'], $field_definition['target_type'], 'default', [
          'target_bundles' => [
            $type->id() => $type->id(),
          ],
        ], 1, $field_definition['revisions']);
        // Configure the entity reference field to not be composite.
        $entity_reference_field->setThirdPartySetting('composite_reference', 'composite', FALSE);
        $entity_reference_field->save();
      }

      // For base field overrides, we need to create them based on a base field.
      if ($field_definition['field_type'] === 'base field override') {
        $base_field_definitions = $this->container->get('entity_field.manager')->getBaseFieldDefinitions('node');
        $field_definition['field_name'] = str_replace('_override', '', $field_definition['field_name']);
        $base_field_definition = $base_field_definitions[$field_definition['field_name']];
        $override = BaseFieldOverride::createFromBaseFieldDefinition($base_field_definition, 'test_ct');
        $override->save();
      }

      // Create an entity that will be referenced by other nodes.
      $referenced_entity_storage = $this->entityTypeManager->getStorage($field_definition['target_type']);
      if ($field_definition['target_type'] === 'entity_test') {
        $values = [
          'name' => 'Referenced entity',
        ];
      }
      else {
        $values = [
          'type' => $type->id(),
          'title' => 'Referenced node',
        ];
      }
      $referenced_entity = $referenced_entity_storage->create($values);
      $referenced_entity->save();

      if ($field_definition['field_type'] === 'field config') {
        // Assert that while an entity reference field is not composite,
        // deleting a node will not delete any entities that it may be
        // referencing. We only test this for field configs as the base fields
        // are already configured to be composite.
        // Create a node that references the first entity
        // and delete it right after.
        $values = [
          'type' => $type->id(),
          'title' => 'Referencing node',
          $field_definition['field_name'] => [
            'target_id' => $referenced_entity->id(),
          ],
        ];
        if ($field_definition['revisions']) {
          $values[$field_definition['field_name']]['target_revision_id'] = $referenced_entity->getLoadedRevisionId();
        }
        $referencing_node = $this->entityTypeManager->getStorage('node')->create($values);
        $referencing_node->save();
        $referencing_node->delete();

        // Reload the referenced entity and assert it was not deleted because
        // the entity reference field is not composite yet.
        $referenced_entity_storage->resetCache();
        $referenced_entity = $referenced_entity_storage->load($referenced_entity->id());
        $this->assertNotEmpty($referenced_entity);

        // Update the entity reference field configuration to be composite.
        $entity_reference_field->setThirdPartySetting('composite_reference', 'composite', TRUE);
        $entity_reference_field->save();
      }

      // Assert that while an entity reference field is composite,
      // deleting a node will not delete an entity it is referencing
      // if another entity also references the same entity.
      // Create a node that references the first entity.
      $values = [
        'type' => $type->id(),
        'title' => 'Referencing node one',
        $field_definition['field_name'] => [
          'target_id' => $referenced_entity->id(),
        ],
      ];
      if ($field_definition['revisions']) {
        $values[$field_definition['field_name']]['target_revision_id'] = $referenced_entity->getLoadedRevisionId();
      }
      $referencing_node_one = $this->entityTypeManager->getStorage('node')->create($values);
      $referencing_node_one->save();

      // Create a second node that also references the first entity
      // and delete it right after.
      $values = [
        'type' => $type->id(),
        'title' => 'Referencing node two',
        $field_definition['field_name'] => [
          'target_id' => $referenced_entity->id(),
        ],
      ];
      if ($field_definition['revisions']) {
        $values[$field_definition['field_name']]['target_revision_id'] = $referenced_entity->getLoadedRevisionId();
      }
      $referencing_node_two = $this->entityTypeManager->getStorage('node')->create($values);
      $referencing_node_two->save();
      $referencing_node_two->delete();

      // Reload the referenced entity and assert it was not deleted because
      // it is still being referenced by the first referencing node.
      $referenced_entity_storage->resetCache();
      $referenced_entity = $referenced_entity_storage->load($referenced_entity->id());
      $this->assertNotEmpty($referenced_entity);

      // Assert that while an entity reference field is composite,
      // deleting a node will delete an entity it is referencing
      // if it is not referenced by any other entity.
      // Update the first referencing node to stop referencing the first node.
      $referencing_node_one->{$field_definition['field_name']}->target_id = '';
      if ($field_definition['revisions']) {
        $referencing_node_one->{$field_definition['field_name']}->target_revision_id = '';
      }
      $referencing_node_one->save();

      // Create a third referencing node that references the first entity
      // and delete it right after.
      $values = [
        'type' => $type->id(),
        'title' => 'Referencing node three',
        $field_definition['field_name'] => [
          'target_id' => $referenced_entity->id(),
        ],
      ];
      if ($field_definition['revisions']) {
        $values[$field_definition['field_name']]['target_revision_id'] = $referenced_entity->getLoadedRevisionId();
      }
      $referencing_node_two_three = $this->entityTypeManager->getStorage('node')->create($values);
      $referencing_node_two_three->save();
      $referencing_node_two_three->delete();

      // Reload the referenced entity and assert it has been deleted because
      // there are no more nodes referencing it.
      $referenced_entity_storage->resetCache();
      $referenced_entity = $referenced_entity_storage->load($referenced_entity->id());
      $this->assertEmpty($referenced_entity);
    }
  }

  /**
   * Tests that base field overrides get the third party settings.
   */
  public function testBaseFieldOverride(): void {
    // Create a test node bundle.
    $type = $this->entityTypeManager->getStorage('node_type')
      ->create(['name' => 'Test content type', 'type' => 'test_ct']);
    $type->save();

    $base_field_definitions = $this->container->get('entity_field.manager')->getBaseFieldDefinitions('node');
    $fields = [
      'entity_reference',
      'entity_reference_revisions',
      'entity_reference_composite_revisions',
    ];
    foreach ($fields as $field_name) {
      $base_field_definition = $base_field_definitions[$field_name];
      $override = BaseFieldOverride::createFromBaseFieldDefinition($base_field_definition, 'test_ct');
      $override->save();

      $override = BaseFieldOverride::loadByName('node', 'test_ct', $field_name);
      $expected = [
        'composite' => TRUE,
        'composite_revisions' => $field_name === 'entity_reference_composite_revisions',
      ];

      $this->assertEquals($expected, $override->getThirdPartySettings('composite_reference'));
    }
  }

  /**
   * Tests the composite reference fields with revision deletion.
   */
  public function testCompositeRevisionField(): void {
    $user = $this->drupalCreateUser();
    $this->drupalSetCurrentUser($user);
    $type = $this->entityTypeManager->getStorage('node_type')->create([
      'name' => 'Test content type',
      'type' => 'test_ct',
    ]);
    $type->save();

    $reference_field_definitions = [
      [
        'field_name' => 'entity_reference_revisions_field',
        'field_label' => 'Entity reference revisions field',
        'field_type' => 'field config',
        'cardinality' => FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED,
      ],
      [
        'field_name' => 'entity_reference_composite_revisions',
        'field_label' => 'Entity reference revisions field',
        'field_type' => 'base field',
        'cardinality' => 1,
      ],
      [
        'field_name' => 'entity_reference_composite_revisions_override',
        'field_label' => 'Entity reference revisions override field',
        'field_type' => 'base field override',
        'cardinality' => FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED,
      ],
      // Add a case in which the field is not configured to include revisions
      // in the composite config.
      [
        'field_name' => 'entity_reference_rev_no_comp_rev',
        'field_label' => 'Entity reference revisions field (no composite revisions)',
        'field_type' => 'field config',
        'cardinality' => FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED,
        'no composite revisions' => TRUE,
      ],
    ];

    foreach ($reference_field_definitions as $field_definition) {
      // For field configs, we need to create the field definitions.
      if ($field_definition['field_type'] === 'field config') {
        // Create an entity reference field for the test content type.
        $entity_reference_field = $this->createEntityReferenceField('node', $type->id(), $field_definition['field_name'], $field_definition['field_label'], 'node', 'default', [
          'target_bundles' => [
            $type->id() => $type->id(),
          ],
        ], $field_definition['cardinality'], TRUE);
        $entity_reference_field->setThirdPartySetting('composite_reference', 'composite', TRUE);
        if (!isset($field_definition['no composite revisions'])) {
          $entity_reference_field->setThirdPartySetting('composite_reference', 'composite_revisions', TRUE);
        }
        $entity_reference_field->save();
      }

      // For base field overrides, we need to create them based on a base field.
      if ($field_definition['field_type'] === 'base field override') {
        $base_field_definitions = $this->container->get('entity_field.manager')->getBaseFieldDefinitions('node');
        $field_definition['field_name'] = str_replace('_override', '', $field_definition['field_name']);
        $base_field_definition = $base_field_definitions[$field_definition['field_name']];
        $base_field_definition->setCardinality($field_definition['cardinality']);
        $override = BaseFieldOverride::createFromBaseFieldDefinition($base_field_definition, 'test_ct');
        $override->save();
      }

      $node_storage = $this->entityTypeManager->getStorage('node');

      // Create an entity that will be referenced.
      $values = [
        'type' => $type->id(),
        'title' => 'Referenced node',
      ];
      $referenced_entity = $node_storage->create($values);
      $referenced_entity->save();

      // Create two entities that reference the referenced entity in their
      // first revision, but not the second one.
      $values = [
        'type' => $type->id(),
        'title' => 'Referencing node one',
        $field_definition['field_name'] => [
          'target_id' => $referenced_entity->id(),
          'target_revision_id' => $referenced_entity->getLoadedRevisionId(),
        ],
      ];
      /** @var \Drupal\node\NodeInterface $referencing_node_one */
      $referencing_node_one = $this->entityTypeManager->getStorage('node')->create($values);
      $referencing_node_one->save();
      $referencing_node_one->setNewRevision(TRUE);
      $referencing_node_one->set($field_definition['field_name'], NULL);
      $referencing_node_one->save();
      $this->assertCount(2, $node_storage->getQuery()->condition('nid', $referencing_node_one->id())->allRevisions()->accessCheck(FALSE)->execute());

      $values = [
        'type' => $type->id(),
        'title' => 'Referencing node two',
        $field_definition['field_name'] => [
          'target_id' => $referenced_entity->id(),
          'target_revision_id' => $referenced_entity->getLoadedRevisionId(),
        ],
      ];
      /** @var \Drupal\node\NodeInterface $referencing_node_two */
      $referencing_node_two = $this->entityTypeManager->getStorage('node')->create($values);
      $referencing_node_two->save();
      $referencing_node_two->setNewRevision(TRUE);
      $referencing_node_two->set($field_definition['field_name'], NULL);
      $referencing_node_two->save();
      $this->assertCount(2, $node_storage->getQuery()->condition('nid', $referencing_node_two->id())->allRevisions()->accessCheck(FALSE)->execute());

      // Now delete the first referencing node and assert the referenced node
      // was not deleted because it was still being referenced by the second
      // referencing node.
      $referencing_node_one->delete();
      $this->assertEquals($referenced_entity->id(), $node_storage->load($referenced_entity->id())->id());

      // Now delete the second referencing entity as well.
      $referencing_node_two->delete();
      if (!isset($field_definition['no composite revisions'])) {
        // This time assert that the referenced entity is deleted because
        // there is nothing else to reference it.
        $this->assertNull($node_storage->load($referenced_entity->id()));
      }
      else {
        // If the field is not marked to delete past revisions, the node should
        // not be deleted.
        $this->assertEquals($referenced_entity->id(), $node_storage->load($referenced_entity->id())->id());
      }
    }
  }

}
