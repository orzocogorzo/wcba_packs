<?php
/**
 * Pack Product Type
 */

class WC_Product_WCBA_Pack extends WC_Product_Variable {

	public function get_type () {
		return "wcba_pack";
	}

	public function set_relateds ($relateds, $create_variations=false) {
		if (is_string($relateds) && strpos($relateds, "|")) {
			$relateds = implode("|", array_map("trim", explode("|", $relateds)));
		} else if (!is_numeric($relateds)) {
			$relateds = strval($relateds);
		} else if (is_array($relateds)) {
			$relateds = implode("|", $relateds);
		} else {
			$relateds = array();	
		}

		update_post_meta($this->get_ID(), "_pack_relateds", $relateds);

		if ($create_variations) {
			$this->set_pack_variations();
		}
	}

	public function get_relateds ($as_array=false) {
		$relateds = $this->get_meta("_pack_relateds", true);
		if ($as_array) {
			return array_map("trim", explode("|", $relateds));
		}
		return $relateds;
	}

	public function set_discount ($rate) {
		if (!is_numeric($rate)) {
			$rate = 0;
		}

		$rate = absint($rate);

		if ($rate > 1) {
			$rate = $rate / 100;
		}

		update_post_meta($this->get_ID(), "_pack_discount", $rate);
	}

	public function get_discount ($as_factor=false) {
		$discount = $this->get_meta("_pack_discount", true);
		if ($as_factor) {
			return $discount;
		}
		return $discount * 100;
	}

	public function get_related_products () {
 		$products = array();
		$ids = explode("|", $this->get_relateds());

		foreach ($ids as $id) {
			if (is_numeric($id)) {
				$product = wc_get_product(absint($id));
				if ($product) {
					$products[] = $product;
				}
			}
		}

		return $products;
	}

	private function set_pack_variations () {
		$products = $this->get_related_products();

		$attributes = $this->get_cross_related_attributes($products);
		if ($attributes) {
			$this->set_cross_related_attributes($attributes);
			$this->data_store->create_all_product_variations($this);
			$this->setup_variations();
		}
	}

	private function get_cross_related_attributes ($products) {
		$attributes = array();
		foreach ($products as $p) {
			$p_name = $p->get_name();
			if ($p->is_type("variable")) {
				$p_attrs = get_post_meta($p->get_ID(), "_product_attributes")[0];
				foreach ($p_attrs as $attr) {
					$attributes[
						$p_name." ".$attr["name"]
					] = array_map("trim", explode("|", $attr["value"]));
				}
			}
		}

		return $attributes;
	}

	private function set_cross_related_attributes ($attributes) {
		$obj_attributes = $this->get_prop("attributes");
		$pack_attrs = array();

		foreach ($attributes as $attr => $options) {
			$slug = wc_sanitize_taxonomy_name($attr);

			wp_set_object_terms($this->get_ID(), implode("|", $options), $slug);
			$pack_attrs[$slug] = array(
				"name" => wc_clean($attr),
				"value" => implode("|", $options),
				"position" => 0,
				"is_visible" => 1,
				"is_variation" => 1,
				"is_taxonomy" => 0
			);
		}

		update_post_meta($this->get_ID(), "_product_attributes", $pack_attrs);
	}

	private function setup_variations () {
		$relateds = array_map("wc_get_product", $this->get_relateds(true));
		$variations = array_map("wc_get_product", $this->get_children());

		foreach ($variations as $var) {
			set_post_meta($var, "_wcba_role", "pack_bundle");
			$var_relateds = array();
			$var_attributes = $var->get_variation_attributes(false);
			foreach ($var_attributes as $attr => $val) {
				foreach ($relateds as $prod) {
					if (strpos($attr, $prod->get_name()) !== false) {
						$var_relateds[] = $prod;	
					}
				}
			}

			$price = 0;
			$stock = INF;
			foreach ($var_relateds as $prod) {
				$price += $prod->get_price();
				$stock = min($stock, $prod->get_quantity());
			}

			$stock_status = $stock > 0 ? "instock" : "outofstock";
			$var->set_manage_stock(true);
			$var->set_stock_status($stock_status);
			$var->set_stock($stock);

			$var->set_price($price);
			$var->set_regular_price($price);

			$var->save();
		}
	}
}
?>
