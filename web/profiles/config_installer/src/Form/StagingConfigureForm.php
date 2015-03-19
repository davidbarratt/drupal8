<?php

/**
 * @file
 * Contains \Drupal\config_installer\Form\StagingConfigureForm.
 */

namespace Drupal\config_installer\Form;

use Drupal\Core\Archiver\ArchiveTar;
use Drupal\Core\Config\FileStorage;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\HttpCache\StoreInterface;

/**
 * .
 */
class StagingConfigureForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'config_installer_staging_configure_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['#title'] = $this->t('Configure configuration import location');

    $form['staging_directory'] = array(
      '#type' => 'textfield',
      '#title' => $this->t('Staging directory'),
      '#default_value' => config_get_config_directory(CONFIG_STAGING_DIRECTORY),
      '#maxlength' => 255,
      '#description' => $this->t('@todo'),
      '#required' => TRUE,
    );

    $form['import_tarball'] = array(
      '#type' => 'file',
      '#title' => $this->t('Select your configuration export file'),
      '#description' => $this->t('This form will redirect you to the import configuration screen.'),
    );

    $form['actions'] = array('#type' => 'actions');
    $form['actions']['submit'] = array(
      '#type' => 'submit',
      '#value' => $this->t('Save and continue'),
      '#weight' => 15,
      '#button_type' => 'primary',
    );

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $file_upload = $this->getRequest()->files->get('files[import_tarball]', NULL, TRUE);
    $has_upload = FALSE;
    if ($file_upload && $file_upload->isValid()) {
      // The staging directory must be empty if we are doing an upload.
      $form_state->setValue('import_tarball', $file_upload->getRealPath());
      $has_upload = TRUE;
    }
    $staging_directory = $form_state->getValue('staging_directory');
    // If we've customised the staging directory ensure its good to go.
    if ($staging_directory != config_get_config_directory(CONFIG_STAGING_DIRECTORY)) {
      // Ensure it exists and is writeable.
      if (!file_prepare_directory($staging_directory, FILE_CREATE_DIRECTORY | FILE_MODIFY_PERMISSIONS)) {
        $form_state->setErrorByName('staging_directory', t('The directory %directory could not be created or could not be made writable. To proceed with the installation, either create the directory and modify its permissions manually or ensure that the installer has the permissions to create it automatically. For more information, see the <a href="@handbook_url">online handbook</a>.', array(
          '%directory' => $staging_directory,
          '@handbook_url' => 'http://drupal.org/server-permissions',
        )));
      }
    }

    // If no tarball ensure we have files.
    if (!$form_state->hasAnyErrors() && !$has_upload) {
      $staging = new FileStorage($staging_directory);
      if (count($staging->listAll()) === 0) {
        $form_state->setErrorByName('staging_directory', t('No file upload provided and the staging directory is empty'));
      }
    }

  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    global $config_directories;
    $staging_directory = $form_state->getValue('staging_directory');
    if ($staging_directory != config_get_config_directory(CONFIG_STAGING_DIRECTORY)) {
      $settings['config_directories'][CONFIG_STAGING_DIRECTORY] = (object) array(
        'value' => $staging_directory,
        'required' => TRUE,
      );
      drupal_rewrite_settings($settings);
      $config_directories[CONFIG_STAGING_DIRECTORY] = $staging_directory;
    }
    if ($path = $form_state->getValue('import_tarball')) {
      // Ensure that we have an empty directory if we're going.
      $staging = new FileStorage($staging_directory);
      $staging->deleteAll();
      try {
        $archiver = new ArchiveTar($path, 'gz');
        $files = array();
        foreach ($archiver->listContent() as $file) {
          $files[] = $file['filename'];
        }
        $archiver->extractList($files, config_get_config_directory(CONFIG_STAGING_DIRECTORY));
        drupal_set_message($this->t('Your configuration files were successfully uploaded, ready for import.'));
      }
      catch (\Exception $e) {
        drupal_set_message($this->t('Could not extract the contents of the tar file. The error message is <em>@message</em>', array('@message' => $e->getMessage())), 'error');
      }
      drupal_unlink($path);
    }

  }

}
