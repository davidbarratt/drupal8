<?php
/**
 * @file
 * Enables modules and site configuration for a minimal site installation.
 */

use Drupal\Core\Config\StorageComparer;
use Drupal\Core\Config\ConfigImporter;
use Drupal\Core\Config\ConfigImporterException;

/**
 * Need to do a manual include since this install profile never actually gets
 * installed so therefore its code cannot be autoloaded.
 */
include_once __DIR__ . '/src/Form/SiteConfigureForm.php';
include_once __DIR__ . '/src/Form/StagingConfigureForm.php';

/**
 * Implements hook_install_tasks_alter().
 */
function config_installer_install_tasks_alter(&$tasks, $install_state) {
  $key = array_search('install_profile_modules', array_keys($tasks));
  unset($tasks['install_profile_modules']);
  unset($tasks['install_profile_themes']);
  $config_tasks = array(
    'config_installer_upload' => array(
      'display_name' => t('Upload config'),
      'type' => 'form',
      'function' => 'Drupal\config_installer\Form\StagingConfigureForm'
    ),
    'config_install_batch' => array(
      'display_name' => t('Install configuration'),
      'type' => 'batch',
    ),
  );
  $tasks = array_slice($tasks, 0, $key, true) +
    $config_tasks +
    array_slice($tasks, $key, NULL , true);
  $tasks['install_configure_form']['function'] = 'Drupal\config_installer\Form\SiteConfigureForm';
}

/**
 * Creates a batch for the config importer to process.
 *
 * @see config_installer_install_tasks_alter()
 */
function config_install_batch() {
  // Match up the site uuids, the install_base_system install task will have
  // installed the system module and created a new UUID.
  $staging = \Drupal::service('config.storage.staging');
  $system_site = $staging->read('system.site');
  \Drupal::configFactory()->getEditable('system.site')->set('uuid', $system_site['uuid'])->save();

  // Create the storage comparer and the config importer.
  $config_manager = \Drupal::service('config.manager');
  $storage_comparer = new StorageComparer($staging, \Drupal::service('config.storage'), $config_manager);
  $storage_comparer->createChangelist();
  $config_importer = new ConfigImporter(
    $storage_comparer,
    \Drupal::service('event_dispatcher'),
    $config_manager,
    \Drupal::service('lock.persistent'),
    \Drupal::service('config.typed'),
    \Drupal::service('module_handler'),
    \Drupal::service('module_installer'),
    \Drupal::service('theme_handler'),
    \Drupal::service('string_translation')
  );

  try {
    $sync_steps = $config_importer->initialize();
    $batch = array(
      'operations' => array(),
      'finished' => 'config_install_batch_finish',
      'title' => t('Synchronizing configuration'),
      'init_message' => t('Starting configuration synchronization.'),
      'progress_message' => t('Completed @current step of @total.'),
      'error_message' => t('Configuration synchronization has encountered an error.'),
      'file' => drupal_get_path('module', 'config') . '/config.admin.inc',
    );
    foreach ($sync_steps as $sync_step) {
      $batch['operations'][] = array('config_install_batch_process', array($config_importer, $sync_step));
    }

    return $batch;
  }
  catch (ConfigImporterException $e) {
    // There are validation errors.
    drupal_set_message($this->t('The configuration synchronization failed validation.'));
    foreach ($config_importer->getErrors() as $message) {
      drupal_set_message($message, 'error');
    }
  }
}

/**
 * Processes the config import batch and persists the importer.
 *
 * @param \Drupal\Core\Config\ConfigImporter $config_importer
 *   The batch config importer object to persist.
 * @param string $sync_step
 *   The synchronisation step to do.
 * @param $context
 *   The batch context.
 *
 * @see config_install_batch()
 */
function config_install_batch_process(ConfigImporter $config_importer, $sync_step, &$context) {
  if (!isset($context['sandbox']['config_importer'])) {
    $context['sandbox']['config_importer'] = $config_importer;
  }

  $config_importer = $context['sandbox']['config_importer'];
  $config_importer->doSyncStep($sync_step, $context);
  if ($errors = $config_importer->getErrors()) {
    if (!isset($context['results']['errors'])) {
      $context['results']['errors'] = array();
    }
    $context['results']['errors'] += $errors;
  }
}

/**
 * Finish config importer batch.
 *
 * @see config_install_batch()
 */
function config_install_batch_finish($success, $results, $operations) {
  if ($success) {
    if (!empty($results['errors'])) {
      foreach ($results['errors'] as $error) {
        drupal_set_message($error, 'error');
        \Drupal::logger('config_sync')->error($error);
      }
      drupal_set_message(\Drupal::translation()->translate('The configuration was imported with errors.'), 'warning');
    }
    else {
      // Configuration sync needs a complete cache flush.
      drupal_flush_all_caches();
    }
  }
  else {
    // An error occurred.
    // $operations contains the operations that remained unprocessed.
    $error_operation = reset($operations);
    $message = \Drupal::translation()
      ->translate('An error occurred while processing %error_operation with arguments: @arguments', array(
        '%error_operation' => $error_operation[0],
        '@arguments' => print_r($error_operation[1], TRUE)
      ));
    drupal_set_message($message, 'error');
  }
}

/**
 * Implements hook_config_import_steps_alter().
 */
function config_installer_config_import_steps_alter(&$sync_steps, ConfigImporter $config_importer) {
  $sync_steps[] = 'config_installer_config_import_profile';
}

/**
 * Processes profile as part of configuration sync.
 *
 * @param array $context.
 *   The batch context.
 * @param \Drupal\Core\Config\ConfigImporter $config_importer
 *   The config importer.
 *
 * @see config_installer_config_import_steps_alter()
 */
function config_installer_config_import_profile(array &$context, ConfigImporter $config_importer) {
  // Profiles need to be extracted from the install list if they are there.
  // This is because profiles need to be installed after all the configuration
  // has been processed.
  $listing = new \Drupal\Core\Extension\ExtensionDiscovery(\Drupal::root());
  $listing->setProfileDirectories([]);
  $new_extensions = $config_importer->getStorageComparer()->getSourceStorage()->read('core.extension');
  $profiles = array_intersect_key($listing->scan('profile'), $new_extensions);

  if (!empty($profiles)) {
    // There can be only one.
    $name = key($profiles);
    \Drupal::service('config.installer')
      ->setSyncing(TRUE)
      ->setSourceStorage($config_importer->getStorageComparer()->getSourceStorage());
    $this->moduleInstaller->install([$name]);
    $context['message'] = t('Synchronising install profile: @name.', array('@name' => $name));
  }
  $context['finished'] = 1;
}
