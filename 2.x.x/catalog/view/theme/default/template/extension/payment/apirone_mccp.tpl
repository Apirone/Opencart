<?php if (empty($coins)) : ?>
  	<div class="pull-right">
        <legend><?php echo $payment_details; ?></legend>
    	<p><?php echo $unavailable; ?></p>
  	</div>
<?php else: ?>
<?php // echo '<pre>'; print_r($coins); echo '</pre>'; ?>
<form id="mccp-form" class="form form-horizontal">
    <input type="hidden" name="order_id" value="<?php echo $order_id; ?>">
    <fieldset id="payment">
        <legend><?php echo $payment_details; ?></legend>
        <div class="form-group">
            <div class="col-sm-12">
                <?php echo $pay_message; ?>
                <select name="currency" id="mccp-currency" class="form-control" required>
                <?php foreach($coins as $coin) : ?>
                <?php $disabled = !$coin->amount; ?>
                <option value="<?php echo $coin->abbr . $disabled;?>">
                    <?php echo $coin->alias; ?>: <?php echo $coin->amount ? $coin->amount : $cant_convert; ?>
                </option>
                <?php endforeach; ?>
                </select>
            </div>
        </div>
    </fieldset>
    <div class="buttons">
        <div class="pull-right">
            <button type="button" id="button-confirm" onclick="mccpConfirm(event)" class="btn btn-primary"><?php echo $button_confirm; ?></button>
        </div>
    </div>
</form>
<?php endif; ?>

<script type="text/javascript">
    function mccpConfirm(event) {
        event.preventDefault();

        currency = $('#mccp-currency');
        if (currency === 'undefined') return;

        currencyVal = currency.val();
        if (currencyVal === '' || currencyVal === null) return;

        location = '<?php echo $url_redirect; ?>&currency=' + currencyVal + '&key=<?php echo $order_key; ?>&order=<?php echo $order_id; ?>';
    }
</script>
