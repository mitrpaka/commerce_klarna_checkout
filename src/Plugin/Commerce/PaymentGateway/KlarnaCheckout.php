<?php

namespace Drupal\commerce_klarna_checkout\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_klarna_checkout\KlarnaManager;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\PaymentMethodTypeManager;
use Drupal\commerce_payment\PaymentTypeManager;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Provides the Off-site Redirect payment gateway.
 *
 * @CommercePaymentGateway(
 *   id = "klarna_checkout",
 *   label = "Example (Klarna Checkout)",
 *   display_label = "Klarna Checkout",
 *    forms = {
 *     "offsite-payment" = "Drupal\commerce_klarna_checkout\PluginForm\OffsiteRedirect\KlarnaCheckoutForm",
 *   },
 * )
 */
class KlarnaCheckout extends OffsitePaymentGatewayBase {

  /**
   * Service used for making API calls using Klarna Checkout library.
   *
   * @var \Drupal\commerce_klarna_checkout\KlarnaManager
   */
  protected $klarna;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * KlarnaCheckout constructor.
   *
   * @param array $configuration
   * @param string $plugin_id
   * @param mixed $plugin_definition
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   * @param \Drupal\commerce_payment\PaymentTypeManager $payment_type_manager
   * @param \Drupal\commerce_payment\PaymentMethodTypeManager $payment_method_type_manager
   * @param \Drupal\Component\Datetime\TimeInterface
   * @param \Drupal\commerce_klarna_checkout\KlarnaManager $klarnaManager
   * @param \Psr\Log\LoggerInterface $logger
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, PaymentTypeManager $payment_type_manager, PaymentMethodTypeManager $payment_method_type_manager, TimeInterface $time, KlarnaManager $klarnaManager, LoggerInterface $logger) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $entity_type_manager, $payment_type_manager, $payment_method_type_manager, $time);

    $this->klarna = $klarnaManager;
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('plugin.manager.commerce_payment_type'),
      $container->get('plugin.manager.commerce_payment_method_type'),
      $container->get('datetime.time'),
      $container->get('commerce_klarna_checkout.payment_manager'),
      $container->get('logger.factory')->get('commerce_klarna_checkout')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'live_mode' => 'test',
      'merchant_id' => '',
      'password' => '',
      'terms_path' => '',
      'language' => 'sv-se',
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['merchant_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Merchant ID'),
      '#default_value' => $this->configuration['merchant_id'],
    ];

    $form['password'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Password'),
      '#default_value' => $this->configuration['password'],
    ];

    $form['terms_path'] = array(
      '#type'           => 'textfield',
      '#title'          => t('Path to terms and conditions page'),
      '#default_value'  => $this->configuration['terms_path'],
      '#required'       => TRUE,
    );

    $form['language'] = array(
      '#type'           => 'select',
      '#title'          => t('Language'),
      '#default_value'  => $this->configuration['language'],
      '#required'       => TRUE,
      '#options'        => array(
        'sv-se'         => t('Swedish'),
        'nb-no'         => t('Norwegian'),
        'fi-fi'         => t('Finnish'),
        'sv-fi'         => t('Swedish (Finland)'),
      ),
    );

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);
    if (!$form_state->getErrors()) {
      $values = $form_state->getValue($form['#parents']);
      $this->configuration['live_mode'] = $this->getMode();
      $this->configuration['merchant_id'] = $values['merchant_id'];
      $this->configuration['password'] = $values['password'];
      $this->configuration['terms_path'] = $values['terms_path'];
      $this->configuration['language'] = $values['language'];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function onReturn(OrderInterface $order, Request $request) {
    $payment_storage = $this->entityTypeManager->getStorage('commerce_payment');
    $payment = $payment_storage->create([
      'state' => 'authorization',
      'amount' => $order->getTotalPrice(),
      'payment_gateway' => $this->entityId,
      'order_id' => $order->id(),
      'test' => $this->getMode() == 'test',
      'remote_id' => $request->query->get('klarna_order_id'),
      'remote_state' => 'paid', //$request->query->get('payment_status'),
      'authorized' => \Drupal::time()->getRequestTime(),
    ]);
    $payment->save();

    $klarna_order_id = $request->query->get('klarna_order_id');
    if ($klarna_order_id != $order->getData('klarna_id')) {
      $this->logger->error(
        $this->t('Confirmation post request sent with different id @order [@ref]', [
          '@order' => $klarna_order_id,
          '@ref' => $order->getData('klarna_id'),
        ])
      );
    }
  }

  /**
   * {@inheritdoc}
   */
  public function onNotify(Request $request) {
    $storage = $this->entityTypeManager->getStorage('commerce_order');

    /** @var \Drupal\commerce_order\Entity\OrderInterface $commerce_order */
    if (!$commerce_order = $storage->load($request->query->get('commerce_order'))) {

      $this->logger->notice(
        $this->t('Notify callback called for an invalid order @order [@values]', [
          '@order' => $request->query->get('commerce_order'),
          '@values' => print_r($request->query->all(), TRUE),
        ])
      );
    }

    // Get order from Klarna.
    $klarna_order = $this->klarna->getOrder($commerce_order, $commerce_order->getData('klarna_id'));

    // Validate commerce order and acknowledge order to Klarna.
    if (isset($klarna_order)) {
      if ($klarna_order['status'] == 'checkout_complete') {
        // Mark payment as captured.
        /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
        $payment = $this->getPayment($commerce_order);
        $payment->setState('completed');
        $payment->save();

        // Validate commerce order.
        $transition = $commerce_order->getState()
          ->getWorkflow()
          ->getTransition('validate');
        if (isset($transition)) {
          $commerce_order->getState()->applyTransition($transition);
          $commerce_order->save();
        }

        // Update Klarna order status.
        $update = [];
        $update['status'] = 'created';
        $klarna_order->update($update);
      }
      else {
        $this->logger->error(
          $this->t('Invalid order status (@status) received from Klarna for order @order_id', [
            '@status' => $klarna_order['status'],
            '@order_id' => $commerce_order->id(),
          ])
        );
      }
    }
    else {
      $this->logger->error(
        $this->t('No order details returned from Klarna to order @order_id', [
          '@order_id' => $commerce_order->id(),
        ])
      );
    }
  }

  /**
   * Add cart items and create checkout order.
   *
   * @param \Drupal\commerce_payment\Entity\PaymentInterface $payment
   * @return \Klarna_Checkout_Order
   */
  public function setKlarnaCheckout(PaymentInterface $payment) {
    $order = $payment->getOrder();

    return $this->klarna->buildTransaction($order);
  }

  /**
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   * @return bool|\Drupal\commerce_payment\Entity\PaymentInterface
   */
  protected function getPayment(OrderInterface $order) {
    /** @var \Drupal\commerce_payment\Entity\PaymentInterface[] $payments */
    $payments = $this->entityTypeManager
      ->getStorage('commerce_payment')
      ->loadByProperties(['order_id' => $order->id()]);

    if (empty($payments)) {
      return FALSE;
    }
    foreach ($payments as $payment) {
      if ($payment->getPaymentGateway()->getPluginId() !== 'klarna_checkout' || $payment->getAmount()->compareTo($order->getTotalPrice()) !== 0) {
        continue;
      }
      $klarna_payment = $payment;
    }
    return empty($klarna_payment) ? FALSE : $klarna_payment;
  }

}
