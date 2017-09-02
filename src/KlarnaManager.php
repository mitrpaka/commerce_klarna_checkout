<?php

namespace Drupal\commerce_klarna_checkout;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_price\Calculator;
use Drupal\Component\Utility\SortArray;
use Drupal\Component\Utility\Unicode;
use Drupal\Core\Url;
use Klarna_Checkout_Connector;
use Klarna_Checkout_Order;


/**
 * Class KlarnaManager.
 *
 * @package Drupal\commerce_klarna_checkout
 */
class KlarnaManager {

  /**
   * {@inheritdoc}
   */
  public function buildTransaction(OrderInterface $order) {
    $plugin_configuration = $this->getPluginConfiguration($order);

    $create['cart']['items'] = [];

    // Add order item data.
    foreach ($order->getItems() as $item) {
      $tax_rate = 0;
      foreach ($item->getAdjustments() as $adjustment) {
        if ($adjustment->getType() == 'tax') {
          $tax_rate = $adjustment->getPercentage();
        }
      }
      $item_amount = $item->getUnitPrice();
      $create['cart']['items'][] = [
        'reference' => $item->getTitle(),
        'name' => $item->getTitle(),
        'quantity' => (int) $item->getQuantity(),
        'unit_price' => (int) $item_amount->multiply('100')->getNumber(),
        'tax_rate' => $tax_rate ? (int) Calculator::multiply($tax_rate, '10000') : 0,
      ];
    }

    // Add adjustments (excluding tax).
    $adjustments = [];
    foreach ($order->collectAdjustments() as $adjustment) {
      $type = $adjustment->getType();
      $source_id = $adjustment->getSourceId();
      if ($type != 'tax') {
        if (empty($source_id)) {
          // Adjustments without a source ID are always shown standalone.
          $key = count($adjustments);
        }
        else {
          // Adjustments with the same type and source ID are combined.
          $key = $type . '_' . $source_id;
        }

        if (empty($adjustments[$key])) {
          $adjustments[$key] = [
            'reference' => $adjustment->getLabel()->getUntranslatedString(),
            'name' => $adjustment->getLabel()->getUntranslatedString(),
            'quantity' => 1,
            'unit_price' => (int) $adjustment->getAmount()->multiply('100')->getNumber(),
            'tax_rate' => 0,
          ];

          // Cart item object type (Klarna).
          if ($type == 'promotion') {
            $adjustments[$key]['type'] = 'discount';
          }
          elseif ($type == 'shipping') {
            $adjustments[$key]['type'] = 'shipping_fee';
          }
        }
        else {
          $adjustments[$key]['unit_price'] += (int) $adjustment->getAmount()->multiply('100')->getNumber();
        }
      }
    }
    // Sort the adjustments by weight.
    uasort($adjustments, [SortArray::class, 'sortByWeightElement']);
    // Merge adjustments to cart item objects (Klarna).
    $create['cart']['items'] = array_values(array_merge($create['cart']['items'], $adjustments));

    $create['purchase_country'] = $this->getCountryFromLocale($plugin_configuration['language']);
    $create['purchase_currency'] = $order->getTotalPrice()->getCurrencyCode();
    $create['locale'] = $plugin_configuration['language'];
    $create['merchant_reference'] = ['orderid1' => $order->id()];
    $create['merchant'] = array(
      'id' => $plugin_configuration['merchant_id'],
      'terms_uri' => Url::fromUserInput($plugin_configuration['terms_path'], ['absolute' => TRUE])->toString(),
      'checkout_uri' => $this->getReturnUrl($order, 'commerce_payment.checkout.cancel'),
      'confirmation_uri' => $this->getReturnUrl($order, 'commerce_payment.checkout.return') .
        '&klarna_order_id={checkout.order.id}',
      'push_uri' => $this->getReturnUrl($order, 'commerce_payment.notify', 'complete') .
        '&klarna_order_id={checkout.order.id}',
      'back_to_store_uri' => $this->getReturnUrl($order, 'commerce_payment.checkout.cancel'),
    );

    try {
      $connector = $this->getConnector($plugin_configuration);
      $klarna_order = new Klarna_Checkout_Order($connector);
      $klarna_order->create($create);
      $klarna_order->fetch();
    }
    catch (\Klarna_Checkout_ApiErrorException $e) {
      debug($e->getMessage(), TRUE);
      debug($e->getPayload(), TRUE);
    }

    return $klarna_order;
  }

  /**
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   * @param $type
   * @param string $step
   * @return \Drupal\Core\GeneratedUrl|string
   */
  protected function getReturnUrl(OrderInterface $order, $type, $step = 'payment') {
    $arguments = [
      'commerce_order' => $order->id(),
      'step' => $step,
      'commerce_payment_gateway' => 'klarna_checkout',
    ];
    $url = new Url($type, $arguments, [
      'absolute' => TRUE,
    ]);

    return $url->toString();
  }

  /**
   * Helper function that returns the Klarna Checkout order management endpoint.
   *
   * @return string
   *   The Klarna Checkout endpoint URI
   */
  public function getBaseEndpoint(array $plugin_configuration) {
    // Server URI.
    if ($plugin_configuration['live_mode'] == 'live') {
      $uri = Klarna_Checkout_Connector::BASE_URL;
    }
    else {
      $uri = Klarna_Checkout_Connector::BASE_TEST_URL;
    }
    return $uri;
  }

  /**
   * @param array $plugin_configuration
   * @return \Klarna_Checkout_ConnectorInterface
   */
  public function getConnector(array $plugin_configuration) {
    // Server URI.
    if ($plugin_configuration['live_mode'] == 'live') {
      $uri = Klarna_Checkout_Connector::BASE_URL;
    }
    else {
      $uri = Klarna_Checkout_Connector::BASE_TEST_URL;
    }

    $connector = Klarna_Checkout_Connector::create(
      $plugin_configuration['password'],
      $uri
    );

    return $connector;
  }

  /**
   * Get order details from Klarna.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   * @param $checkout_id
   * @return \Klarna_Checkout_Order
   */
  public function getOrder(OrderInterface $order, $checkout_id) {
    try {
      $connector = $this->getConnector($this->getPluginConfiguration($order));
      $klarna_order = new Klarna_Checkout_Order($connector, $checkout_id);
      $klarna_order->fetch();
    }
    catch (\Klarna_Checkout_ApiErrorException $e) {
      debug($e->getMessage(), TRUE);
      debug($e->getPayload(), TRUE);
    }

    return $klarna_order;
  }

  /**
   * Update order's billing profile.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   * @param array $klarna_billing_address
   */
  public function updateBillingProfile(OrderInterface $order, array $klarna_billing_address) {
    if ($billing_profile = $order->getBillingProfile()) {
      $billing_profile->get('address')->first()->setValue([
        'given_name' => $klarna_billing_address['given_name'],
        'family_name' => $klarna_billing_address['family_name'],
        'address_line1' => $klarna_billing_address['street_address'],
        'postal_code' => $klarna_billing_address['postal_code'],
        'locality' => $klarna_billing_address['city'],
        'country_code' => Unicode::strtoupper($klarna_billing_address['country']),
      ]);
      $billing_profile->save();
    }
  }

  /**
   * Get payment gateway configuration.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   * @return array
   */
  protected function getPluginConfiguration(OrderInterface $order) {
    /** @var \Drupal\commerce_payment\Entity\PaymentGatewayInterface $payment_gateway */
    $payment_gateway = $order->payment_gateway->entity;
    /** @var \Drupal\commerce_klarna_checkout\Plugin\Commerce\PaymentGateway\KlarnaCheckout $payment_gateway_plugin */
    $payment_gateway_plugin = $payment_gateway->getPlugin();

    return $payment_gateway_plugin->getConfiguration();
  }

  /**
   * Get country code from locale setting.
   *
   * @param string $locale
   * @return bool|mixed
   */
  protected function getCountryFromLocale($locale = 'sv-se') {
    $country_codes = [
      'sv-se' => 'SE',
      'fi-fi' => 'FI',
      'sv-fi' => 'FI',
      'nb-no' => 'NO',
    ];

    return empty($country_codes[$locale]) ? FALSE : $country_codes[$locale];
  }
}
