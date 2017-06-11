<?php

namespace Drupal\commerce_klarna_checkout\PluginForm\OffsiteRedirect;

use Drupal\commerce_payment\PluginForm\PaymentOffsiteForm as BasePaymentOffsiteForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

class KlarnaCheckoutForm extends BasePaymentOffsiteForm {

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
    $payment = $this->entity;
    /** @var \Drupal\commerce_klarna_checkout\Plugin\Commerce\PaymentGateway\KlarnaCheckout $payment_gateway_plugin */
    $payment_gateway_plugin = $payment->getPaymentGateway()->getPlugin();

    try {
      $order = $payment->getOrder();
      if (empty($order)) {
        throw new \InvalidArgumentException('The provided payment has no order referenced.');
      }

      // Add cart items and create a checkout order.
      $klarna_order = $payment_gateway_plugin->setKlarnaCheckout($payment);

      // Save klarna order id.
      $order->setData('klarna_id', $klarna_order['id']);
      $order->save();

      // Get checkout snippet.
      $snippet = $klarna_order['gui']['snippet'];
    }
    catch (\Exception $e) {
      debug($e->getMessage(), TRUE);
    }

    $redirect_url = Url::fromRoute('commerce_klarna_checkout.redirect_post', [
      'commerce_order' => $payment->getOrder()->id(),
      'step' => 'payment',
    ], ['absolute' => TRUE])->toString();

    // Encode snippet to prevent potential
    // "ERR_BLOCKED_BY_XSS_AUDITOR" errors from browsers (Chrome & Safari)
    // @todo: Any better way to handle this?
    $data = [
      'snippet' => base64_encode($snippet),
    ];

    return $this->buildRedirectForm($form, $form_state, $redirect_url, $data, self::REDIRECT_POST);
  }

}
