jQuery(document).ready(function ($) {

  function calc() {
    const weight = parseFloat($('#ds-weight').val());
    const time = parseFloat($('#ds-time').val());
    if (!weight || !time) {
      $('#ds-result').text('وزن و زمان را وارد کنید');
      return;
    }

    $('#ds-result').text('⏳ در حال محاسبه...');
    fetch(dsApi.root + 'calc', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': dsApi.nonce
      },
      body: JSON.stringify({ weight_g: weight, time_h: time })
    })
      .then(r => r.json())
      .then(data => {
        if (data.price_eur) {
          $('#ds-result').html(
            `💰 قیمت کامل: €${data.price_eur}<br>🪙 با تخفیف: €${data.price_discount_eur}`
          );
        } else {
          $('#ds-result').text('❌ خطا در محاسبه.');
        }
      })
      .catch(() => $('#ds-result').text('❌ خطا در ارتباط.'));
  }

  $('#ds-calc-btn').on('click', e => {
    e.preventDefault();
    calc();
  });
});
