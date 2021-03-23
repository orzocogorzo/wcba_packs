<?php

class WCBA_Rest {

	public function __construct () {
		add_action("rest_api_init", array(
			$this,
			"initializer"
		));
	}

	public function initializer () {

		register_rest_route("wcba_packs/v1", "/get/(?P<id>[\d]+)", array(
			"methods" => "GET",
			"callback" => array(
				$this,
				"get_pack"
			),
			"args" => array(
				"id" => array(
					"sanitize_callback" => "absint",
					"required" => true,
					"type" => "integer",
				)
			),
			"permission_callback" => function () {
				#return current_user_can("edit_posts");
				return true;
			}
		));

		register_rest_route("wcba_packs/v1", "/add", array(
			"methods" => "POST",
			"callback" => array(
				$this,
				"add_pack"
			),
			"args" => array(
				"name" => array(
					"sanitize_callback" => "sanitize_text_field",
					"required" => true,
					"type" => "string"
				)
			),
			"permission_callback" => function () {
				#return current_user_can("edit_posts");
				return true;
			}
		));

		register_rest_route("wcba_packs/v1", "/list", array(
			"methods" => "GET",
			"callback" => array(
				$this,
				"list_packs"
			),
			"permission_callback" => function () {
				#return current_user_can("edit_posts");
				return true;
			}
		));

		register_rest_route("wcba_packs/v1", "/delete/(?P<id>[\d]+)", array(
			"methods" => "DELETE",
			"callback" => array(
				$this,
				"delete_pack"
			),
			"args" => array(
				"id" => array(
					"sanitize_callback" => "absint",
					"required" => true,
					"type" => "integer",
				)
			),
			"permission_callback" => function () {
				#return current_user_can("edit_posts");
				return true;
			}
		));

		register_rest_route("wcba_packs/v1", "/debug/(?P<id>[\d]+)", array(
			"methods" => "GET",
			"callback" => array(
				$this,
				"debug"
			),
			"args" => array(
				"id" => array(
					"sanitize_callback" => "absint",
					"required" => true,
					"type" => "integer"
				)
			),
			"permission_callback" => function () {
				return true;
			}
		));
	}

	public function get_pack (WP_REST_Request $request) {
		if (!isset($request["id"])) {
			echo "{}";
		} else {
			$pack_id = (string) $request["id"];
			$pack = wc_get_product(intval($pack_id));
			if ($pack && $pack->get_type() === "wcba_pack") {
				echo json_encode($pack->get_data());
			} else {
				echo "{}";
			}
		}
	}

	public function list_packs (WP_REST_Request $request) {
		$args = array(
			"type" => "wcba_pack"
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

	public function delete_pack (WP_REST_Request $request) {
		if (!isset($request["id"])) {
			echo "{\"success\": false}";
		}
		$pack_id = (string) $request["id"];
		$pack = wc_get_product(intval($pack_id));
		if ($pack && $pack->get_type() === "wcba_pack") {
			$pack->delete();
			echo '{"success": true}';
		} else {
			echo '{"success": false}';
		}
	}

	public function add_pack (WP_REST_Request $request) {
		try {
			$payoad = json_decode($request->get_body(), true);

			if (!isset($payload["name"]) || !isset($payload["relateds"])) {
				echo "{\"success\": false}";
			} else {
				$pack = new WCBA_Product_Pack();
				$pack->set_name($payload["name"]);
				$pack->set_status("publish");
				$pack->set_catalog_visibility("hidden");
				$pack->set_description("WCBA Pack created programatically through the REST API");
				$pack->set_relateds($payload["relateds"]);
				$id = $pack->save();

				$product = wc_get_product($id);
				echo json_encode($product->get_data());
			}
		} catch (Exception $e) {
			echo "{\"error\": \"".$e->getMessage()."\"}";
		}
	}

	public function debug (WP_REST_Request $request) {
		$id = $request["id"];
		$product = wc_get_product($id);
		$parent = wc_get_product($product->get_parent_id());
		$bundles = null;
		if ($parent && $parent->is_type("wcba_pack")) {
			$bundles = $product->get_meta("_wcba_pack_bundles", true);
		} else if ($product->get_meta("_wcba_pack_role", true) == "wcba_pack_bundle") {
			$bundles = $product->get_meta("_wcba_pack_bundles", true);
		}

		$res = array();
		if ($bundles) {
			$res = explode("|", $bundles);
		}

		echo json_encode($res);
	}
}

new WCBA_Rest();
?>
