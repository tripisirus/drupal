<?php

namespace Drupal\Tests\media\FunctionalJavascript;

use Drupal\Core\Config\FileStorage;
use Drupal\Core\Config\InstallStorage;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\media\Entity\Media;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;

/**
 * Basic display tests for Media.
 *
 * @group media
 */
class MediaDisplayTest extends MediaJavascriptTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();

    // Install the optional configs from the standard profile.
    $extension_path = drupal_get_path('profile', 'standard');
    $optional_install_path = $extension_path . '/' . InstallStorage::CONFIG_OPTIONAL_DIRECTORY;
    $storage = new FileStorage($optional_install_path);
    $this->container->get('config.installer')->installOptionalConfig($storage, '');
    // Reset all the static caches and list caches.
    $this->container->get('config.factory')->reset();
  }

  /**
   * Test basic media display.
   */
  public function testMediaDisplay() {
    $assert_session = $this->assertSession();
    $page = $this->getSession()->getPage();

    $media_type = $this->createMediaType();

    // Create a media item.
    $media = Media::create([
      'bundle' => $media_type->id(),
      'name' => 'Fantastic!',
    ]);
    $media->save();

    $this->drupalGet('media/' . $media->id());
    // Verify the "name" field is really not present.
    $assert_session->elementNotExists('css', '.field--name-name');

    // Enable the field on the display and verify it becomes visible on the UI.
    $this->drupalGet("/admin/structure/media/manage/{$media_type->id()}/display");
    $page->selectFieldOption('fields[name][region]', 'content');
    $assert_session->waitForElementVisible('css', '#edit-fields-name-settings-edit');
    $page->pressButton('Save');
    $this->drupalGet('media/' . $media->id());
    // Verify the name is present, and its text matches what is expected.
    $assert_session->elementExists('css', '.field--name-name');
    $name_field = $page->find('css', '.field--name-name .field__item');
    $this->assertEquals($media->label(), $name_field->getText());

    // In the standard profile, there are some pre-cooked types. Make sure the
    // elements configured on their displays are the expected ones.
    $this->drupalGet('media/add/image');
    $image_media_name = 'Fantastic image asset!';
    $page->fillField('name[0][value]', $image_media_name);
    $page->attachFileToField('files[field_media_image_0]', \Drupal::root() . '/core/modules/media/tests/fixtures/example_1.jpeg');
    $result = $assert_session->waitForButton('Remove');
    $this->assertNotEmpty($result);
    $page->fillField('field_media_image[0][alt]', 'Image Alt Text 1');
    $page->pressButton('Save');
    $image_media_id = $this->container->get('entity.query')->get('media')
      ->sort('mid', 'DESC')
      ->execute();
    $image_media_id = reset($image_media_id);

    // Here we expect to see only the image, nothing else.
    // Assert only one element in the content region.
    $this->assertEquals(1, count($page->findAll('css', '.media--type-image > div')));
    // Assert the image is present inside the media element, with "medium"
    // image style.
    $media_item = $assert_session->elementExists('css', '.media--type-image > div');
    $assert_session->elementExists('css', 'img.image-style-medium', $media_item);

    $test_filename = $this->randomMachineName() . '.txt';
    $test_filepath = 'public://' . $test_filename;
    file_put_contents($test_filepath, $this->randomMachineName());
    $this->drupalGet("media/add/file");
    $page->fillField('name[0][value]', 'File media 1');
    $page->attachFileToField("files[field_media_file_0]", \Drupal::service('file_system')->realpath($test_filepath));
    $result = $assert_session->waitForButton('Remove');
    $this->assertNotEmpty($result);
    $page->pressButton('Save');

    // Here we expect to see only the linked filename.
    // Assert only one element in the content region.
    $this->assertEquals(1, count($page->findAll('css', 'article.media--type-file > div')));
    // Assert the file link is present, and its text matches the filename.
    $assert_session->elementExists('css', 'article.media--type-file .field--name-field-media-file a');
    $link = $page->find('css', 'article.media--type-file .field--name-field-media-file a');
    $this->assertEquals($test_filename, $link->getText());

    // Create a node type "page" to use as host entity.
    $node_type = NodeType::create([
      'type' => 'page',
      'name' => 'Page',
    ]);
    $node_type->save();

    // Reference the created media using an entity_refernce field and make sure
    // the output is what we expect.
    $storage = FieldStorageConfig::create([
      'entity_type' => 'node',
      'field_name' => 'field_related_media',
      'type' => 'entity_reference',
      'settings' => [
        'target_type' => 'media',
      ],
    ]);
    $storage->save();

    FieldConfig::create([
      'field_storage' => $storage,
      'entity_type' => 'node',
      'bundle' => $node_type->id(),
      'label' => 'Related media',
      'settings' => [
        'handler_settings' => [
          'target_bundles' => [
            'image' => 'image',
          ],
        ],
      ],
    ])->save();

    entity_get_display('node', $node_type->id(), 'default')
      ->setComponent('field_related_media', [
        'type' => 'entity_reference_entity_view',
        'label' => 'hidden',
        'settings' => [
          'view_mode' => 'full',
        ],
      ])->save();

    $node = Node::create([
      'title' => 'Host node',
      'type' => $node_type->id(),
      'field_related_media' => [
        'target_id' => $image_media_id,
      ],
    ]);
    $node->save();

    $this->drupalGet('/node/' . $node->id());
    // Media field is there.
    $assert_session->elementExists('css', '.field--name-field-related-media');
    // Media name element is not there.
    $assert_session->elementNotExists('css', '.field--name-name');
    $assert_session->pageTextNotContains($image_media_name);
    // Only one element is present inside the media container.
    $this->assertEquals(1, count($page->findAll('css', '.field--name-field-related-media article.media--type-image > div')));
    // Assert the image is present, with "medium" image style.
    $assert_session->elementExists('css', '.field--name-field-related-media article.media--type-image img.image-style-medium');
  }

}
