<?php

/**
 * @file
 * Contains \Drupal\config_installer\Form\SiteConfigureForm.
 */

namespace Drupal\config_installer\Form;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\State\StateInterface;
use Drupal\user\UserStorageInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Updates the user 1 account.
 *
 * This is based on the install_configure_form provided by core.
 *
 * @see \Drupal\Core\Installer\Form\SiteConfigureForm
 */
class SiteConfigureForm extends FormBase {

  /**
   * The user storage.
   *
   * @var \Drupal\user\UserStorageInterface
   */
  protected $userStorage;

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * The app root.
   *
   * @var string
   */
  protected $root;

  /**
   * The module handler.
   *
   * @var ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * Constructs a new SiteConfigureForm.
   *
   * @param string $root
   *   The app root.
   * @param \Drupal\user\UserStorageInterface $user_storage
   *   The user storage.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \Drupal\Core\Extension\ModuleInstallerInterface $module_installer
   *   The module installer.
   * @param \Drupal\Core\Locale\CountryManagerInterface $country_manager
   *   The country manager.
   */
  public function __construct($root, UserStorageInterface $user_storage, StateInterface $state, ModuleHandlerInterface $module_handler) {
    $this->root = $root;
    $this->userStorage = $user_storage;
    $this->state = $state;
    $this->moduleHandler = $module_handler;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('app.root'),
      $container->get('entity.manager')->getStorage('user'),
      $container->get('state'),
      $container->get('module_handler')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'config_installer_site_configure_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['#title'] = $this->t('Configure site');

    // Warn about settings.php permissions risk
    $settings_dir = conf_path();
    $settings_file = $settings_dir . '/settings.php';
    // Check that $_POST is empty so we only show this message when the form is
    // first displayed, not on the next page after it is submitted. (We do not
    // want to repeat it multiple times because it is a general warning that is
    // not related to the rest of the installation process; it would also be
    // especially out of place on the last page of the installer, where it would
    // distract from the message that the Drupal installation has completed
    // successfully.)
    $post_params = $this->getRequest()->request->all();
    if (empty($post_params)) {
      // Try to fix the install profile.
      foreach ($this->moduleHandler->getModuleList() as $module) {
        $name = $module->getName();
        if ($module->getType() == 'profile' && $name != 'config_installer') {
          $settings['settings']['install_profile'] = (object) array(
            'value' => $name,
            'required' => TRUE,
          );
          drupal_rewrite_settings($settings);
          break;
        }
      }

      if (!drupal_verify_install_file($this->root . '/' . $settings_file, FILE_EXIST|FILE_READABLE|FILE_NOT_WRITABLE) || !drupal_verify_install_file($this->root . '/' . $settings_dir, FILE_NOT_WRITABLE, 'dir')) {
        drupal_set_message(t('All necessary changes to %dir and %file have been made, so you should remove write permissions to them now in order to avoid security risks. If you are unsure how to do so, consult the <a href="@handbook_url">online handbook</a>.', array(
          '%dir' => $settings_dir,
          '%file' => $settings_file,
          '@handbook_url' => 'http://drupal.org/server-permissions'
        )), 'warning');
      }
    }

    $form['#attached']['library'][] = 'system/drupal.system';

    // Cache a fully-built schema. This is necessary for any invocation of
    // index.php because: (1) setting cache table entries requires schema
    // information, (2) that occurs during bootstrap before any module are
    // loaded, so (3) if there is no cached schema, drupal_get_schema() will
    // try to generate one but with no loaded modules will return nothing.
    //
    // @todo Move this to the 'install_finished' task?
    drupal_get_schema(NULL, TRUE);


    $form['admin_account'] = array(
      '#type' => 'fieldgroup',
      '#title' => $this->t('Site maintenance account'),
    );
    $form['admin_account']['account']['name'] = array(
      '#type' => 'textfield',
      '#title' => $this->t('Username'),
      '#maxlength' => USERNAME_MAX_LENGTH,
      '#description' => $this->t('Spaces are allowed; punctuation is not allowed except for periods, hyphens, and underscores.'),
      '#required' => TRUE,
      '#attributes' => array('class' => array('username')),
    );
    $form['admin_account']['account']['pass'] = array(
      '#type' => 'password_confirm',
      '#required' => TRUE,
      '#size' => 25,
    );
    $form['admin_account']['account']['#tree'] = TRUE;
    $form['admin_account']['account']['mail'] = array(
      '#type' => 'email',
      '#title' => $this->t('Email address'),
      '#required' => TRUE,
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
    if ($error = user_validate_name($form_state->getValue(array('account', 'name')))) {
      $form_state->setErrorByName('account][name', $error);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {

    $account_values = $form_state->getValue('account');

    // We precreated user 1 with placeholder values. Let's save the real values.
    $account = $this->userStorage->load(1);
    $account->init = $account->mail = $account_values['mail'];
    $account->roles = $account->getRoles();
    $account->activate();
    $account->timezone = $form_state->getValue('date_default_timezone');
    $account->pass = $account_values['pass'];
    $account->name = $account_values['name'];
    $account->save();

    // Record when this install ran.
    $this->state->set('install_time', $_SERVER['REQUEST_TIME']);
  }

}
