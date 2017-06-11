<?php

namespace Drupal\commerce_klarna_checkout\Plugin\Commerce\CheckoutPane;

use Drupal\commerce_checkout\Plugin\Commerce\CheckoutFlow\CheckoutFlowInterface;
use Drupal\commerce_checkout\Plugin\Commerce\CheckoutPane\CheckoutPaneBase;
use Drupal\commerce_klarna_checkout\KlarnaManager;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the completion message pane.
 *
 * @CommerceCheckoutPane(
 *   id = "klarna_completion_message",
 *   label = @Translation("Klarna Confirmation message"),
 *   default_step = "complete",
 * )
 */
class KlarnaCompletionMessage extends CheckoutPaneBase {

  /**
   * The klarna payment manager.
   *
   * @var \Drupal\commerce_klarna_checkout\KlarnaManager
   */
  protected $klarna;

  /**
   * Constructs a new ShippingInformation object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\commerce_checkout\Plugin\Commerce\CheckoutFlow\CheckoutFlowInterface $checkout_flow
   *   The parent checkout flow.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\commerce_klarna_checkout\KlarnaManager
   *  The klarna payment manager.
   * @param \Drupal\Core\Form\FormBuilderInterface
   *  The form builder.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, CheckoutFlowInterface $checkout_flow, EntityTypeManagerInterface $entity_type_manager, KlarnaManager $klarnaManager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $checkout_flow, $entity_type_manager);

    $this->klarna = $klarnaManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition, CheckoutFlowInterface $checkout_flow = NULL) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $checkout_flow,
      $container->get('entity_type.manager'),
      $container->get('commerce_klarna_checkout.payment_manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildPaneForm(array $pane_form, FormStateInterface $form_state, array &$complete_form) {
    $checkout_id = $this->order->getData('klarna_id');
    $klarna_order = $this->klarna->getOrder($this->order, $checkout_id);
    $snippet = $klarna_order['gui']['snippet'];

    $pane_form['klarna'] = [
      '#type' => 'inline_template',
      '#template' => "<div id='klarna-checkout-form'>{$snippet}</div>",
      '#context' => ['snippet' => $snippet],
    ];

    return $pane_form;
  }

  /**
   * {@inheritdoc}
   */
  public function isVisible() {
    /** @noinspection PhpUndefinedFieldInspection */
    // @todo debug, why the order can be empty, if payment fails!?!
    if ($this->order && $this->order->hasField('payment_gateway') && !$this->order->payment_gateway->isEmpty()) {
      /** @var \Drupal\commerce_payment\Entity\PaymentGatewayInterface $payment_gateway */
      /** @noinspection PhpUndefinedFieldInspection */
      $payment_gateway = $this->order->payment_gateway->entity;
      if ($payment_gateway && $payment_gateway->getPluginId() == 'klarna_checkout') {
        return TRUE;
      }
    }
    return FALSE;
  }
}
