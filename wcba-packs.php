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

add_action("rest_api_init", function () {
	register_rest_route("wcba_packs/v1", "/get/(?P<id>[\d]+)", array(
		"methods" => "GET",
		"callback" => "wcba_get_pack",
		"args" => array(
			"id" => array(
				"sanitize_callback" => "absint",
				"required" => true,
				"type" => "integer",
			)
		)
	));
	register_rest_route("wcba_packs/v1", "/add", array(
		"methods" => "POST",
		"callback" => "wcba_create_pack",
		"args" => array(
			"name" => array(
				"sanitize_callback" => "sanitize_text_field",
				"required" => true,
				"type" => "string"
			)
		)
	));
	register_rest_route("wcba_packs/v1", "/list", array(
		"methods" => "GET",
		"callback" => "wcba_list_packs",
	));
	register_rest_route("wcba_packs/v1", "/delete/(?P<id>[\d]+)", array(
		"methods" => "DELETE",
		"callback" => "wcba_delete_pack",
		"args" => array(
			"id" => array(
				"sanitize_callback" => "absint",
				"required" => true,
				"type" => "integer",
			)
		)
	));
});

function wcba_get_pack (WP_REST_Request $request) {
	$pack_id = (string) $request["id"];
	$pack = wc_get_product(intval($pack_id));
	if ($pack) {
		echo json_encode($pack->get_data());
	} else {
		echo "{}";
	}
}

function wcba_create_pack (WP_REST_Request $request) {
	try {
		$payload = json_decode($request->get_body(), true);

        	$product = new WC_Product_Variable();
		$pack_name = filter_var($payload["name"], FILTER_SANITIZE_STRING, FILTER_FLAG_STRIP_LOW);
        	$product->set_name($pack_name);
        	$product->set_status("draft");
        	$product->set_catalog_visibility("hidden");
        	$product->set_description("Producte creat com a pack automàtic, recorda fer-lo visible per que pugui ser trobat a la botiga.");
        	# $product->set_price(10);
		$product->set_sku("wcba_packs_".str_replace(" ", "_", strtolower($pack_name)));
        	# $product->set_manage_stock(true);
        	# $product->set_stock_quantity(100);
        	$product->set_reviews_allowed(true);
        	$product->set_category_ids(array(43));
        	$id = $product->save();
        
        	$attributes = wcba_cross_related_attributes($payload["relateds"]);
		echo json_encode($attributes);
        
        	$productAttrs = array();
        	foreach ($attributes as $attribute) {
        		$attr = wc_sanitize_taxonomy_name(stripslashes($attribute["name"]));
        		$tax = "pa_".$attr;

			if (!taxonomy_exists($tax)) {
				register_taxonomy(
					$tax,
					"product_variation",
					array(
						"hierarchical" => false,
						# "labels" => array(ucfirst(strtolower($attr))),
						"query_var" => true,
						"rewrite" => array(
							"slug" => sanitize_title($attr)
						)
					)
				);
			}

        		if ($attribute["options"]) {
        			foreach ($attribute["options"] as $option) {
					if (!term_exists($option, $tax)) {
						wp_insert_term($option, $tax);
					}
        				wp_set_object_terms($id, $option, $tax, true);
        				$productAttrs[sanitize_title($tax)] = array(
        					"name" => sanitize_title($tax),
						"value" => $option,
        					# "value" => $attribute["options"],
        					# "position" => $attribute["position"],
        					"is_visible" => "1", #$attribute["visible"],
        					"is_variation" => "1", #$attribute["variation"],
        					"is_taxonomy" => "1"
        				);
        				update_post_meta($id, "_product_attributes", $productAttrs);
        			}
        		}

        	}


		$product_meta = wp_get_post_terms($id, $tax, array("fields" => "names"));
		return rest_ensure_response($product_meta);
        
        	$variations = wcba_cross_related_variations($payload["relateds"]);
        
        	if ($variations) {
        		foreach ($variations as $d) {
        			$var = new WC_Product_Variation();
        			$var->set_parent_id($id);
        			$var->set_price($d["price"]);
        			$var->set_sku($d["sku"]);
        			$var->set_manage_stock($d["manage_stock"]);
        			$var->set_stock_quantity($d["stock_quantity"]);
        			$var->set_stock_status($d["instock"]);
        
        			$var_attrs = array();
        			foreach ($d["attributes"] as $attr) {
        				$tax= "pa_".wc_sanitize_taxonomy_name(stripslashes($attr["name"]));
        				$slug = wc_sanitize_taxonomy_name(stripslashes($attr["option"]));
        				$var_attrs[$tax] = $slug;
        			}
        			$var->set_attributes($var_attrs);
        			$var->save();
        		}
        	}

        	$product = wc_get_product($id);
        	echo json_encode($product->get_data());
	} catch (Exception $e) {
		echo '{"error": "'.$e->getMessage().'"}';
	}
}

function wcba_get_related_products ($ids) {
 	$products = array();

	foreach ($ids as $id) {
		$products[] = wc_get_product(intval($id));
	}

	return $products;
}

function wcba_cross_related_attributes ($ids) {
	$products = wcba_get_related_products($ids);

	$related_taxonomies = array();
	foreach ($products as $p) {
		$p_name = filter_var($p->get_name(), FILTER_SANITIZE_STRING, FILTER_FLAG_STRIP_LOW);
		if ($p->is_type("variable")) {
			$attrs = get_post_meta($p->get_ID(), "_product_attributes")[0];
			foreach ($attrs as $attr) {
				$related_taxonomies[] = array(
					"name" => $p_name." ".$attr["name"],
					"options" => array_map("trim", explode("|", $attr["value"])),
					# "taxonomy" => "1",
					# "position" => "1", #$attr["position"],
					# "visible" => "1", #$attr["is_visible"],
					# "variation" => "1" #$attr["is_variation"]
				);
			}
		}
	}

	return $related_taxonomies;
}

function wcba_cross_related_variations ($ids) {
	$products = wcba_get_related_products($ids);

	$pack_variations = array();
	$related_names = array();
	$related_variations = array();
	foreach ($products as $product) {
		if ($product->is_type("variable")) {
			$related_names[] = filter_var($product->get_name(), FILTER_SANITIZE_STRING, FILTER_FLAG_STRIP_LOW);
			$related_variations[] = $product->get_available_variations();
		}
	}

	$l = count($related_variations);
	for ($i = 0; $i < $l; $i++) {
		$name1 = $related_names[$i];
		$p1 = $related_variations[$i];
		for ($j = $i; $j < $l; $j++) {
			if ($i == $j) {
				continue;
			} else {
				$name2 = $related_names[$j];
				$p2 = $related_variations[$j];
				foreach ($p1 as $var1) {
					foreach ($p2 as $var2) {
						$new_var = array();
						$new_var["price"] = $var1["display_price"] + $var2["display_price"];
						$new_var["manage_stock"] = 1;
						$new_var["stock_quantity"] = min($var1["max_qty"], $var2["max_qty"]);
						$new_var["instock"] = $new_var["stock_quantity"] > 0;
						$new_var["attributes"] = array();
						foreach ($var1["attributes"] as $attr => $val) {
							$new_var["attributes"][] = array(
								"name" => $name1." ".ucfirst(str_replace("-", " ", str_replace("attribute_", "", strtolower($attr)))),
								"option" => $val
							);
						}
						foreach ($var2["attributes"] as $attr => $val) {
							$new_var["attributes"][] = array(
								"name" => $name2." ".ucfirst(str_replace("-", " ", str_replace("attribute_", "", strtolower($attr)))),
								"option" => $val
							);
						}
						$pack_variations[] = $new_var;
					}
				}	
			}
		}
	}

	return $pack_variations;
}

function wcba_list_packs (WP_REST_Request $request) {
	$args = array(
		"category" => "packs"
	);
	$packs = wc_get_products($args);

	echo "[";
	$list = array();
	foreach ($packs as $pack) {
		$list[] = json_encode($pack->get_data());
	}
	echo join(",", $list);
	echo "]";
}

function wcba_delete_pack (WP_REST_Request $request) {
	$pack_id = (string) $request["id"];
	$pack = wc_get_product(intval($pack_id));
	if ($pack) {
		$pack->delete();
		echo '{"success": true}';
	} else {
		echo '{"success": false}';
	}
}
?>
