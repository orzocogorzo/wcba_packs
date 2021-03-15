jQuery(document).ready(function ($) {
	var self = this;
	var relateds = $("#wcba_pack_relateds");
	var discount = $("#wcba_pack_discount");
	var update_btn = $("#wcba_pack_update");
	var store_products = $("#wcba_pack_store_products");

	function onInputChange (ev) {
		update_btn.prop("disabled", false);
	}

	relateds.on("change", onInputChange);
	discount.on("change", onInputChange);

	update_btn.on("click", function (ev) {
		$.post(my_ajax_obj.ajax_url, {
			_ajax_nonce: my_ajax_obj.nonce,
			action: "wcba_pack_update",
			_pack_id: String(my_ajax_obj.post_id),
			_pack_relateds: relateds.val(),
			_pack_discount: discount.val()
		}, function (data) {
			json = JSON.parse(data);
			if (json.success) {
				location.reload();
				// update_btn.prop("disabled", true);
			}
		});
	});

	store_products.on("change", function (ev) {
		var values = Array.apply(null, this.getElementsByTagName("option")).filter(function (opt) {
			return opt.selected;
		}).map(function (opt) {
			return opt.value;	
		});
		relateds.val(values.join("|"));
		update_btn.prop("disabled", false);
	});

	console.log(relateds.val());
});
