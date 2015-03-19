<?php

/**
 * @file
 * Contains \Drupal\config_installer\Tests\ConfigInstallerStagingTest.
 */

namespace Drupal\config_installer\Tests;

use Drupal\Core\Archiver\ArchiveTar;

/**
 * Tests the config installer profile by having files in a staging directory.
 *
 * @group ConfigInstaller
 */
class ConfigInstallerStagingTest extends ConfigInstallerTestBase {

  protected $staging_dir;

  protected function setUp() {
    $this->staging_dir = 'public://' . $this->randomMachineName(128);
    parent::setUp();
  }

  /**
   * Ensures that the user page is available after installation.
   */
  public function testInstaller() {
    // Do assertions from parent.
    parent::testInstaller();

    // Do assertions specific to test.
    $this->assertEqual(drupal_realpath($this->staging_dir), config_get_config_directory(CONFIG_STAGING_DIRECTORY), 'The staging directory has been updated during the installation.');
  }

  /**
   * {@inheritdoc}
   */
  protected function setUpStagingForm() {
    // Create a new staging directory.
    drupal_mkdir($this->staging_dir);

    // Extract the tarball into the staging directory.
    $archiver = new ArchiveTar($this->tarball, 'gz');
    $files = array();
    foreach ($archiver->listContent() as $file) {
      $files[] = $file['filename'];
    }
    $archiver->extractList($files, $this->staging_dir);
    $this->drupalPostForm(NULL, array('staging_directory' => drupal_realpath($this->staging_dir)), 'Save and continue');
  }

}
