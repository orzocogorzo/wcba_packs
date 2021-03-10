<?php
/**
 * Plugin Name: WooCommerce BA Packs
 * Author: Lèmur
 */

add_action("woocommerce_payment_complete", "wcba_on_payment_complete");
function wcba_on_payment_complete ($order_id) {
	$order = wc_get_order($order_id);

	foreach ($order->get_items() as $item_key => $item) {
		$product = $item->get_product();
		$sku = $product->get_sku();

		if (substr($sku, 0, 7) == "ba_pack") { 
			# if ($sku == "ba_pack_descompte") {
			$data = $item->get_data();
			$string = serialize($data);
			file_put_contents("log.txt", $string);
		}
	}
}
?>
