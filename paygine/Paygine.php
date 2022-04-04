<?php

require_once('api/Simpla.php');

class Paygine extends Simpla
{	
	public function checkout_form($order_id, $button_text = null)
	{
		// Код валюты в Paygine
		$currency = '643';

		if(empty($button_text))
			$button_text = 'Перейти к оплате';
		
		$order = $this->orders->get_order((int)$order_id);
		$payment_method = $this->payment->get_payment_method($order->payment_method_id);
		$payment_settings = $this->payment->get_payment_settings($payment_method->id);
		
		$price = $this->money->convert($order->total_price, $payment_method->currency_id, false);
		
		$success_url = $this->config->root_url.'/payment/Paygine/redirect.php';
		
		// регистрационная информация (логин, пароль #1)
		// registration info (login, password #1)
		$sector = $payment_settings['sector'];
		
		// номер заказа
		// number of order
		$id = $order->id;
		$password = $payment_settings['password'];
		
		// адрес api
		if($payment_settings['mode'] == 'test')
			$paygine_url = "https://test.paygine.com";
		else
			$paygine_url = "https://pay.paygine.com";
		
			
		// описание заказа
		// order description
		$desc = 'Оплата заказа №'.$id;
				
		// формирование подписи
		// generate signature
		$signature  = base64_encode(md5($sector.($price*100).$currency.$password));
		
		// Регистрируем заказ
		$url = $paygine_url.'/webapi/Register';
		
		$fiscalPositions = '';
		$fiscalAmount = 0;
		$TAX = 6;
		if (isset($order->items)) {
			foreach ($order->items as $item) {
				$fiscalPositions.=$item['quantity'].';';
	            $elementPrice = $item['price'];
	            $elementPrice = $elementPrice * 100;
	            $fiscalPositions.=$elementPrice.';';
	            $fiscalPositions.=$TAX.';';
	            $fiscalPositions.=$item['name'].'|';

	            $fiscalAmount += $item['quantity'] * $elementPrice;
			}
	        if ($order->shipping_method->price > 0) {
	            $fiscalPositions.='1;';
	            $fiscalPositions.=($order->shipping_method->price*100).';';
	            $fiscalPositions.=$TAX.';';
	            $fiscalPositions.='Доставка'.'|';

	            $fiscalAmount += $order->shipping_method->price*100;
	        }
	        $amountDiff = abs($fiscalAmount - $price*100);
	        if ($amountDiff) {
	        	$fiscalPositions.='1;'.$amountDiff.';6;coupon;14'.'|';
	        }
	        $fiscalPositions = substr($fiscalPositions, 0, -1);
	    }
		
		$data = array(
			'sector' => $sector,
			'reference' => $id,
			'fiscal_positions' => $fiscalPositions,
			'amount' => $price*100,
			'description' => $desc,
			'email' => htmlspecialchars($order->email,ENT_QUOTES),
			'currency' => $currency,
			'mode' => 1,
			'url' => $success_url,
			'signature' => $signature
		);
		$options = array(
		    'http' => array(
		        'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
		        'method'  => 'POST',
		        'content' => http_build_query($data),
		    ),
		);
		$context  = stream_context_create($options);
		$paygine_id = file_get_contents($url, false, $context);
		if(intval($paygine_id)==0)
			$button_text = $paygine_id;

		$error = false;
		$purchases = $this->orders->get_purchases(array('order_id'=>intval($id)));
		foreach($purchases as $purchase) {
			$variant = $this->variants->get_variant(intval($purchase->variant_id));
			if(empty($variant) || (!$variant->infinity && $variant->stock < $purchase->amount)) {
				$error = true;
				break;
			}
		}
		
		if (!$error) {
			// Спишем товары
			$this->orders->close(intval($order->id));
			
			$signature  = base64_encode(md5($sector.$paygine_id.$password));
			$button =	"<form accept-charset='utf8' action='".$paygine_url."/webapi/Purchase' method=POST>".
						"<input type=hidden name=sector value='$sector'>".
						"<input type=hidden name=id value='$paygine_id'>".
						"<input type=hidden name=signature value='$signature'>".
						"<input type=submit class=checkout_button value='$button_text'>".
						"</form>";
			return $button;
		}
		
		return "";

	}

}