<?php

namespace Drupal\commerce_klarna_checkout\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the payment form containing the Klarna Checkout widget.
 */
class KlarnaSnippetForm extends FormBase {

  /**
   * The language manager.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected $languageManager;

  /**
   * Constructs a new KlarnaCheckouForm object.
   *
   * @param \Drupal\Core\Language\LanguageManagerInterface $language_manager
   *   The language manager.
   */
  public function __construct(LanguageManagerInterface $language_manager) {
    $this->languageManager = $language_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    /** @noinspection PhpParamsInspection */
    return new static(
      $container->get('language_manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormID() {
    return 'commerce_klarna_checkout_embedded_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $snippet = NULL) {
    if (empty($snippet)) {
      $form['error'] = ['#markup' => 'An unknown error occurred when connecting to the payment gateway. Please contact the site administrator and/or choose a different payment method.'];
      return $form;
    }
    else {
      $snippet = base64_decode($snippet);
    }

    $form = [];
    $form['klarna'] = [
      '#type' => 'inline_template',
      '#template' => "<div id='klarna-checkout-form'>{$snippet}</div>",
      '#context' => ['snippet' => $snippet],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Can be left empty, as we don't have an action button at all.
    // The embedded payment widget has it's own "pay" button integrated instead.
  }

}
