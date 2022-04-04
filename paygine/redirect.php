<?php

// Работаем в корневой директории
chdir ('../../');
require_once('api/Simpla.php');
$simpla = new Simpla();

$fail_url = "/";

try {
	$order_id = $simpla->request->get('reference', 'integer');
	$order = $simpla->orders->get_order($order_id);

	if (empty($order))
		throw new Exception('Оплачиваемый заказ не найден');
	if ($order->paid)
		throw new Exception('Этот заказ уже оплачен');

	$method = $simpla->payment->get_payment_method(intval($order->payment_method_id));
	if (empty($method))
		throw new Exception('Неизвестный метод оплаты');

	$settings = unserialize($method->settings);
	$sector = $settings['sector'];
	$password = $settings['password'];
	$test_mode = $settings['mode'] == 'test';
	$success_url = $simpla->config->root_url.'/order/'.$order->url;
	$fail_url = $simpla->config->root_url.'/order/'.$order->url.'?error=1';
	
	$paygine_order_id = $simpla->request->get('id', 'integer');
	$paygine_operation_id = $simpla->request->get('operation', 'integer');
	
	$signature = base64_encode(md5($sector . $paygine_order_id . $paygine_operation_id . $password));

	$paygine_url = "https://pay.paygine.com";
	if ($test_mode)
		$paygine_url = "https://test.paygine.com";
	
	$context  = stream_context_create(array(
			'http' => array(
					'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
					'method'  => 'POST',
					'content' => http_build_query(array(
							'sector' => $sector,
							'id' => $paygine_order_id,
							'operation' => $paygine_operation_id,
							'signature' => $signature
					)),
			)
	));
	
	$repeat = 3;
	
	while ($repeat) {
	
		$repeat--;
	
		// pause because of possible background processing in the Paygine
		sleep(2);
	
		$xml = file_get_contents($paygine_url . '/webapi/Operation', false, $context);
		if (!$xml)
			throw new Exception("Empty data");
		$xml = simplexml_load_string($xml);
		if (!$xml)
			throw new Exception("Non valid XML was received");
		$response = json_decode(json_encode($xml));
		if (!$response)
			throw new Exception("Non valid XML was received");

		// check server signature
		$tmp_response = (array)$response;
		unset($tmp_response["signature"]);
		$signature = base64_encode(md5(implode('', $tmp_response) . $settings['password']));
		if ($signature !== $response->signature)
			throw new Exception("Invalid signature");

		// check order state
		if ($response->type != 'PURCHASE' || $response->state != 'APPROVED')
			continue;

		$amount = $response->amount / 100.0;
		if ($amount != $simpla->money->convert($order->total_price, $method->currency_id, false) || $amount <= 0)
			throw new Exception("Invalid price");
		
		header("Location: {$success_url}", true, 302);

		$simpla->orders->update_order(intval($order->id), array('paid'=>1));
		$simpla->notify->email_order_user(intval($order->id));
		$simpla->notify->email_order_admin(intval($order->id));
		
		header("Location: {$success_url}", true, 302);
		exit();
	}
	
	header("Location: {$fail_url}", true, 302);
	exit();

} catch (Exception $ex) {
	error_log($ex->getMessage());
	header("Location: {$fail_url}", true, 302);
}
