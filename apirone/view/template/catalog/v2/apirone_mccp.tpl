<?php if (empty($coins)) : ?>
  	<div class="pull-right">
        <legend><?php echo $payment_details; ?></legend>
    	<p><?php echo $unavailable; ?></p>
  	</div>
<?php else: ?>
<link rel="stylesheet" crossorigin href="<?php echo $apirone_path_to_css; ?>coins.min.css">
<form id="mccp-form">
    <fieldset id="payment">
        <legend><?php echo $payment_details; ?></legend>
        <div class="form-group">
            <div class="col-sm-12">
                <?php echo $pay_message; ?>
                <div id="apirone_mccp_dropdown" class="dropdown">
                    <button type="button" onclick="mccpDropdownToggle(event)"></button>
                    <div class="dropdown-menu"><div class="dropdown-inner">
                        <ul class="list-unstyled">
                            <?php foreach($coins as $coin) : ?>
                            <li><button type="button" onclick="mccpDropdownSelect(event, '<?php echo $coin->abbr; ?>')">
                                <img src="<?php echo $apirone_path_to_images; ?>currencies/<?php echo $coin->token ?? $coin->network; ?>.svg" width="50" height="30" class="apirone-mccp-img">
                                <?php if ($coin->token) : ?>
                                    <img src="<?php echo $apirone_path_to_images; ?>currencies/<?php echo $coin->network; ?>.svg" width="20" class="apirone-mccp-img-small">
                                <?php endif; ?>
                                <span class="coin-alias"><?php echo $coin->alias; ?></span>
                                <?php if ($coin->with_fee) : ?>
                                    <span class="with-fee"><?php echo $coin->with_fee; ?></span>
                                <?php endif; ?>
                            </button></li>
                            <?php endforeach; ?>
                        </ul>
                    </div></div>
                </div>
            </div>
        </div>
    </fieldset>
    <div class="buttons">
        <div class="pull-right">
            <button type="button" id="button-confirm" onclick="mccpConfirm(event)" class="btn btn-primary"><?php echo $button_confirm; ?></button>
        </div>
    </div>
</form>
<script type="text/javascript">
    window.mccp_currency = '<?php echo $coins[0]->abbr; ?>';
    $('#apirone_mccp_dropdown>button').html($('#apirone_mccp_dropdown ul li:first-child button').html());

    function mccpDropdownToggle(event) {
        event.preventDefault();

        $('#apirone_mccp_dropdown').toggleClass('open');
    }
    function mccpDropdownSelect(event, currency) {
        event.preventDefault();

        $('#apirone_mccp_dropdown').removeClass('open');

        if (button = event.target.closest('button')) {
            $('#apirone_mccp_dropdown>button').html(button.innerHTML);
            window.mccp_currency = currency;
        }
    }
    function mccpConfirm(event) {
        event.preventDefault();

        currencyVal = window.mccp_currency;
        if (!currencyVal) return;

        location = '<?php echo $url_redirect; ?>&currency=' + currencyVal + '&key=<?php echo $order_key; ?>&order=<?php echo $order_id; ?>';
    }
</script>
<?php endif; ?>
