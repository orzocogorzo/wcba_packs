jQuery(document).ready(function ($) {
	var relateds = $("#wcba_pack_relateds");
	var discount = $("#wcba_pack_discount");
	var update_btn = $("#wcba_pack_update");
	var store_products = $("#wcba_pack_store_products");

	update_btn.on("click", function (ev) {
		update_btn.prop("disabled", true);
		$.post(my_ajax_obj.ajax_url, {
			_ajax_nonce: my_ajax_obj.nonce,
			action: "wcba_pack_update",
			wcba_pack_id: String(my_ajax_obj.post_id),
			wcba_pack_relateds: relateds.val(),
			wcba_pack_discount: discount.val()
		}, function (data) {
			json = JSON.parse(data);
			if (json.success) {
				location.reload();
			}
		}).fail(function (err) {
			console.log(err);	
		}).always(function () {
			update_btn.prop("disabled", false);
		});
	});

	store_products.on("change", function (ev) {
		var values = Array.apply(null, this.getElementsByTagName("option")).filter(function (opt) {
			return opt.selected;
		}).map(function (opt) {
			return opt.value;	
		});
		relateds.val(values.join("|"));
	});
});
