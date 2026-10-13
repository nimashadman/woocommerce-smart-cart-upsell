(function ($) {
  'use strict';

  var lastProductId = 0;
  var suppressNextModal = false;
  var addRequestRunning = false;
  var lastModalOpen = 0;

  function isWoodmartActive() {
    return typeof window.woodmartThemeModule !== 'undefined' ||
      $('body').hasClass('theme-woodmart') ||
      $('body').hasClass('woodmart-theme');
  }

  function escapeHtml(value) {
    return $('<div>').text(value || '').html();
  }

  function modalShell(data) {
    var cards = data.recommendations.map(function (item) {
      var action = item.can_add
        ? '<button type="button" class="wcscu-add" data-product-id="' + item.id + '">' + escapeHtml(item.button_text) + '</button>'
        : '<a class="wcscu-options" href="' + item.url + '">' + escapeHtml(item.button_text) + '</a>';

      return '<article class="wcscu-card">' +
        '<a class="wcscu-card-image" href="' + item.url + '"><img src="' + item.image + '" alt=""></a>' +
        '<div class="wcscu-card-info"><a class="wcscu-card-name" href="' + item.url + '">' + escapeHtml(item.name) + '</a>' +
        '<div class="wcscu-card-price">' + item.price_html + '</div></div>' + action +
        '</article>';
    }).join('');

    var suggestions = cards
      ? '<section class="wcscu-suggestions"><h3>این‌ها هم کنارش به‌کارت میاد</h3><div class="wcscu-grid">' + cards + '</div></section>'
      : '';

    return '<div class="wcscu-overlay" role="presentation">' +
      '<section class="wcscu-modal" role="dialog" aria-modal="true" aria-labelledby="wcscu-title" dir="rtl">' +
      '<header class="wcscu-header"><button class="wcscu-close" type="button" aria-label="بستن">×</button>' +
      '<div class="wcscu-success"><span class="wcscu-check">✓</span><div><h2 id="wcscu-title">کالا به سبد خرید اضافه شد</h2><p>' + escapeHtml(data.product.name) + '</p></div></div>' +
      '<a class="wcscu-cart" href="' + wcscuData.cartUrl + '">برو به سبد خرید <span>‹</span></a></header>' +
      suggestions + '</section></div>';
  }

  function closeModal() {
    $('.wcscu-overlay').removeClass('is-visible');
    $('body').removeClass('wcscu-lock');
    window.setTimeout(function () { $('.wcscu-overlay').remove(); }, 220);
  }

  function mountModal(data) {
    $('.wcscu-overlay').remove();
    $('body').append(modalShell(data)).addClass('wcscu-lock');
    window.requestAnimationFrame(function () { $('.wcscu-overlay').addClass('is-visible'); });
    $('.wcscu-close').trigger('focus');
  }

  function openModal(productId) {
    if (!productId) return;
    if (Date.now() - lastModalOpen < 700) return;
    lastModalOpen = Date.now();
    lastProductId = productId;

    // Always show a confirmation, even if recommendations are blocked by a
    // cache/security plugin. Replace it with the complete content when ready.
    mountModal({
      product: {
        id: productId,
        name: $.trim($('.product_title, .wd-entities-title').first().text()) || 'محصول انتخاب‌شده'
      },
      recommendations: []
    });

    $.post(wcscuData.endpoint, { product_id: productId, nonce: wcscuData.nonce })
      .done(function (response) {
        if (!response || !response.success) return;
        mountModal(response.data);
      });
  }

  $(document.body).on('added_to_cart', function (event, fragments, cartHash, button) {
    if (suppressNextModal) {
      suppressNextModal = false;
      return;
    }
    var id = button && button.data ? parseInt(button.data('product_id'), 10) : 0;
    openModal(id || lastProductId);
  });

  // Compatibility hook used by some WoodMart releases/extensions.
  $(document).on('wdActionAfterAddToCart', function () {
    openModal(lastProductId);
  });

  $(document).on('click', '.single_add_to_cart_button, .add_to_cart_button', function () {
    lastProductId = parseInt($(this).val() || $(this).data('product_id'), 10) || 0;
  });

  // WooCommerce does not use AJAX on single-product pages by default.
  // Submit the native cart form through the WooCommerce endpoint so the modal
  // works without requiring a theme-specific AJAX add-to-cart feature.
  $(document).on('submit', 'form.cart', function (event) {
    // WoodMart has its own AJAX cart flow. Let it run and listen only to its
    // standard added_to_cart success event to avoid duplicate requests.
    if (isWoodmartActive()) return;

    var form = $(this);
    var button = form.find('.single_add_to_cart_button');
    if (!button.length || button.hasClass('disabled') || button.hasClass('wc-variation-selection-needed')) return;

    event.preventDefault();
    if (addRequestRunning) return;

    var productId = parseInt(button.val() || form.find('[name="add-to-cart"]').val(), 10) || 0;
    var fields = form.serializeArray();
    if (!fields.some(function (field) { return field.name === 'product_id'; })) {
      fields.push({ name: 'product_id', value: productId });
    }

    lastProductId = productId;
    addRequestRunning = true;
    button.addClass('loading').prop('disabled', true);

    $.post(wcscuData.addEndpoint, $.param(fields))
      .done(function (response) {
        if (response && response.error && response.product_url) {
          window.location = response.product_url;
          return;
        }
        if (response && response.fragments) {
          $(document.body).trigger('added_to_cart', [response.fragments, response.cart_hash, button]);
        }
      })
      .fail(function () {
        if (form[0] && typeof form[0].submit === 'function') form[0].submit();
      })
      .always(function () {
        addRequestRunning = false;
        button.removeClass('loading').prop('disabled', false);
      });
  });

  // Also support shop/category pages when WooCommerce AJAX is disabled.
  $(document).on('click', 'a.add_to_cart_button:not(.ajax_add_to_cart)', function (event) {
    if (isWoodmartActive()) return;

    var button = $(this);
    var productId = parseInt(button.data('product_id'), 10) || 0;
    if (!productId || button.hasClass('product_type_variable')) return;

    event.preventDefault();
    if (button.hasClass('loading')) return;
    lastProductId = productId;
    button.addClass('loading');

    $.post(wcscuData.addEndpoint, {
      product_id: productId,
      quantity: parseInt(button.data('quantity'), 10) || 1
    }).done(function (response) {
      if (response && response.error && response.product_url) {
        window.location = response.product_url;
        return;
      }
      if (response && response.fragments) {
        $(document.body).trigger('added_to_cart', [response.fragments, response.cart_hash, button]);
      }
    }).always(function () {
      button.removeClass('loading');
    });
  });

  $(document).on('click', '.wcscu-close, .wcscu-overlay', function (event) {
    if ($(event.target).is('.wcscu-close, .wcscu-overlay')) closeModal();
  });

  $(document).on('keydown', function (event) {
    if (event.key === 'Escape' && $('.wcscu-overlay').length) closeModal();
  });

  $(document).on('click', '.wcscu-add', function () {
    var button = $(this);
    var id = parseInt(button.data('product-id'), 10);
    if (!id || button.prop('disabled')) return;

    button.prop('disabled', true).addClass('is-loading').text(wcscuData.i18n.adding);
    $.post(wcscuData.addEndpoint, {
      product_id: id,
      quantity: 1
    }).done(function (response) {
      if (response && response.error && response.product_url) {
        window.location = response.product_url;
        return;
      }
      if (response && response.fragments) {
        suppressNextModal = true;
        $(document.body).trigger('added_to_cart', [response.fragments, response.cart_hash, $()]);
      }
      button.removeClass('is-loading').addClass('is-added').text('✓ ' + wcscuData.i18n.added);
    }).fail(function () {
      button.prop('disabled', false).removeClass('is-loading').text(wcscuData.i18n.add);
    });
  });
})(jQuery);
