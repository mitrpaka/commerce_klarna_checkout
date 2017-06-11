<?php

namespace Drupal\commerce_klarna_checkout\Controller;

use Drupal\commerce\Response\NeedsRedirectException;
use Drupal\commerce_checkout\CheckoutOrderManagerInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayInterface;
use Drupal\Core\Access\AccessException;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * This is a controller for Klarna Checkout.
 */
class KlarnaCheckoutController implements ContainerInjectionInterface {

  /**
   * The checkout order manager.
   *
   * @var \Drupal\commerce_checkout\CheckoutOrderManagerInterface
   */
  protected $checkoutOrderManager;

  /**
   * Constructs a new KlarnaCheckoutController object.
   *
   * @param \Drupal\commerce_checkout\CheckoutOrderManagerInterface
   *   The checkout order manager.
   */
  public function __construct(CheckoutOrderManagerInterface $checkout_order_manager) {
    $this->checkoutOrderManager = $checkout_order_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('commerce_checkout.checkout_order_manager')
    );
  }

  /**
   * Callback method for checkout form.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $commerce_order
   * @param \Symfony\Component\HttpFoundation\Request $request
   * @return array
   */
  public function returnCheckoutForm(OrderInterface $commerce_order, Request $request) {
    $snippet = $request->get('snippet');

    return \Drupal::formBuilder()->getForm('Drupal\commerce_klarna_checkout\Form\KlarnaSnippetForm', $snippet);
  }

  /**
   * Provides the "return" checkout payment page.
   *
   * Redirects to the next checkout page, completing checkout.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $commerce_order
   *   The order.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   */
  public function returnConfirmationPage(OrderInterface $commerce_order, Request $request) {
    /** @var \Drupal\commerce_payment\Entity\PaymentGatewayInterface $payment_gateway */
    $payment_gateway = $commerce_order->get('payment_gateway')->entity;
    $payment_gateway_plugin = $payment_gateway->getPlugin();
    if (!$payment_gateway_plugin instanceof OffsitePaymentGatewayInterface) {
      throw new AccessException('The payment gateway for the order does not implement ' . OffsitePaymentGatewayInterface::class);
    }
    /** @var \Drupal\commerce_checkout\Entity\CheckoutFlowInterface $checkout_flow */
    $checkout_flow = $commerce_order->get('checkout_flow')->entity;
    $checkout_flow_plugin = $checkout_flow->getPlugin();
    $step_id = $this->checkoutOrderManager->getCheckoutStepId($commerce_order);

    try {
      $payment_gateway_plugin->onReturn($commerce_order, $request);
      $redirect_step_id = $checkout_flow_plugin->getNextStepId($step_id);
    }
    catch (PaymentGatewayException $e) {
      \Drupal::logger('commerce_klarna_checkout')->error($e->getMessage());
      drupal_set_message(t('Payment failed at the payment server. Please review your information and try again.'), 'error');
      $redirect_step_id = $checkout_flow_plugin->getPreviousStepId($step_id);
    }
    $this->redirectToStep($commerce_order, $redirect_step_id);
  }

  /**
   * @param \Drupal\commerce_order\Entity\OrderInterface $commerce_order
   * @param $step_id
   * @throws \Drupal\commerce\Response\NeedsRedirectException
   */
  public function redirectToStep(OrderInterface $commerce_order, $step_id) {
    // Move to next (i.e. complete) step but do not complete order yet.
    // Order completed when push notification sent by Klarna.
    $commerce_order->set('checkout_step', $step_id);
    $commerce_order->save();
    throw new NeedsRedirectException(Url::fromRoute('commerce_checkout.form', [
      'commerce_order' => $commerce_order->id(),
      'step' => $step_id,
    ])->toString());
  }
}
