<?php if (empty($coins)) : ?>
  	<div class="pull-right">
        <legend><?php echo $payment_details; ?></legend>
    	<p><?php echo $unavailable; ?></p>
  	</div>
<?php else: ?>
<form id="mccp-form" class="form form-horizontal">
    <style>
        #apirone_mccp_dropdown .dropdown-menu,
        #apirone_mccp_dropdown>button {
            font-size:16px;
            width: 50%;
            padding: 0;
        }
        #apirone_mccp_dropdown .dropdown-inner {
            padding: 0;
        }
        #apirone_mccp_dropdown .list-unstyled {
            margin-bottom: 0;
        }
        #apirone_mccp_dropdown > button {
            border:#1f90bb solid 1px;
        }
        #apirone_mccp_dropdown li button {
            border:none;
            width: 100%;
        }
        #apirone_mccp_dropdown button {
            padding: 8px;
            position:relative;
            background:none;
            text-align: start;
        }
        #apirone_mccp_dropdown button:hover {
            background:#eee;
        }
        .apirone-mccp-img {
            position:relative;
        }
        .apirone-mccp-img-small {
            position:absolute;
            top:22px;
            left:36px;
        }
    </style>

    <input type="hidden" name="order_id" value="<?php echo $order_id; ?>">
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
                                <img src="catalog/view/theme/default/image/apirone/currencies/<?php echo $coin->token ?? $coin->network; ?>.svg" width="50" height="30" class="apirone-mccp-img">
                                <?php if ($coin->token) : ?>
                                    <img src="catalog/view/theme/default/image/apirone/currencies/<?php echo $coin->network; ?>.svg" width="20" class="apirone-mccp-img-small">
                                <?php endif; ?>
                                <?php echo $coin->label; ?>
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

        window.mccp_currency = currency;

        $('#apirone_mccp_dropdown').removeClass('open');
        $('#apirone_mccp_dropdown>button').html(event.target.innerHTML);
    }
    function mccpConfirm(event) {
        event.preventDefault();

        currencyVal = window.mccp_currency;
        if (!currencyVal) return;

        location = '<?php echo $url_redirect; ?>&currency=' + currencyVal + '&key=<?php echo $order_key; ?>&order=<?php echo $order_id; ?>';
    }
</script>
<?php endif; ?>
