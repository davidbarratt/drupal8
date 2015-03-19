<?php

/**
 * @file
 * Contains \Drupal\config_installer\Tests\ConfigInstallerTestBase.
 */

namespace Drupal\config_installer\Tests;

use Drupal\Core\DrupalKernel;
use Drupal\Core\Site\Settings;
use Drupal\simpletest\InstallerTestBase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the config installer profile by having files in a staging directory.
 *
 * @group ConfigInstaller
 */
abstract class ConfigInstallerTestBase extends InstallerTestBase {

  /**
   * The installation profile to install.
   *
   * @var string
   */
  protected $profile = 'config_installer';

  /**
   * The tarball of the minimal profile configuration exported.
   *
   * @var string
   */
  protected $tarball;

  protected function setUp() {
    $this->tarball =  __DIR__ . '/Fixtures/minimal.tar.gz';
    parent::setUp();
  }

  /**
   * Ensures that the user page is available after installation.
   */
  public function testInstaller() {
    $this->assertUrl('user/1');
    $this->assertResponse(200);
    // Confirm that we are logged-in after installation.
    $this->assertText($this->rootUser->getUsername());

    // @todo hmmm this message is wrong!
    // Verify that the confirmation message appears.
    require_once \Drupal::root() . '/core/includes/install.inc';
    $this->assertRaw(t('Congratulations, you installed @drupal!', array(
      '@drupal' => drupal_install_profile_distribution_name(),
    )));

    // Ensure that all modules, profile and themes have been installed and have
    // expected weights.
    $staging = \Drupal::service('config.storage.staging');
    $this->assertIdentical($staging->read('core.extension'), \Drupal::config('core.extension')->get());

    // Test that any configuration entities in staging have been created.
    // @todo
  }

  /**
   * Overrides method.
   *
   * We have several forms to navigate through.
   */
  protected function setUpSite() {
    // Recreate the container so that we can simulate the submission of the
    // StagingConfigureForm after the full bootstrap has occurred. Without out
    // this drupal_realpath() does not work so uploading files through
    // WebTestBase::postForm() is impossible.
    $request = Request::createFromGlobals();
    $class_loader = require $this->container->get('app.root') . '/core/vendor/autoload.php';
    Settings::initialize($this->container->get('app.root'), DrupalKernel::findSitePath($request), $class_loader);
    foreach ($GLOBALS['config_directories'] as $type => $path) {
      $this->configDirectories[$type] = $path;
    }
    $this->kernel = DrupalKernel::createFromRequest($request, $class_loader, 'prod', FALSE);
    $this->kernel->prepareLegacyRequest($request);
    $this->container = $this->kernel->getContainer();

    $this->setUpStagingForm();
    $this->setUpInstallConfigureForm();
  }

  /**
   * Submit the config_installer_staging_configure_form.
   *
   * @see \Drupal\config_installer\Form\StagingConfigureForm
   */
  abstract protected function setUpStagingForm();

  /**
   * Submit the config_installer_site_configure_form.
   *
   * @see \Drupal\config_installer\Form\SiteConfigureForm
   */
  protected function setUpInstallConfigureForm() {
    $params = $this->parameters['forms']['install_configure_form'];
    unset($params['site_name']);
    unset($params['site_mail']);
    unset($params['update_status_module']);
    $edit = $this->translatePostValues($params);
    $this->drupalPostForm(NULL, $edit, $this->translations['Save and continue']);
  }
}
