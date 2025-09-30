<?php echo $header; ?><?php echo $column_left; ?>
<div id="content">
    <div class="page-header">
        <div class="container-fluid">
            <div class="pull-right">
                <button type="submit" form="form-apirone" data-toggle="tooltip" title="<?php echo $button_save; ?>" class="btn btn-primary"><i class="fa fa-save"></i></button>
                <a href="<?php echo $cancel; ?>" data-toggle="tooltip" title="<?php echo $button_cancel; ?>" class="btn btn-default"><i class="fa fa-reply"></i></a>
            </div>
            <h1><?php echo $heading_title; ?></h1>
            <ul class="breadcrumb">
                <?php foreach ($breadcrumbs as $breadcrumb) : ?>
                <li><a href="<?php echo $breadcrumb['href']; ?>"><?php echo $breadcrumb['text']; ?></a></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
    <div class="container-fluid">
        <?php if (isset($success) && $success) : ?>
        <div class="alert alert-success alert-dismissible"><i class="fa fa-check-circle"></i> <?php echo $success; ?>
              <button type="button" class="close" data-dismiss="alert">&times;</button>
        </div>
        <?php endif; ?>

        <?php if (isset($error) && $error) : ?>
        <div class="alert alert-danger"><i class="fa fa-exclamation-circle"></i> <?php echo $error; ?>
            <button type="button" class="close" data-dismiss="alert">&times;</button>
        </div>
        <?php endif; ?>

        <div class="panel panel-default">
            <div class="panel-heading">
                <h3 class="panel-title"><i class="fa fa-pencil"></i> <?php echo $text_edit; ?></h3>
            </div>
            <div class="panel-body" style="font-size:14px;">
                <form action="<?php echo $action; ?>" method="post" id="form-apirone" class="form-horizontal">
                <ul class="nav nav-tabs">
                    <?php if (isset($settings_loaded) && $settings_loaded) : ?>
                    <li class="active"><a href="#tab-settings" data-toggle="tab"><i class="fa fa-cog"></i> <?php echo $tab_settings; ?></a></li>
                    <li><a href="#tab-currencies" data-toggle="tab"><i class="fa fa-bitcoin"></i> <?php echo $tab_currencies; ?></a></li>
                    <li><a href="#tab-statuses" data-toggle="tab"><i class="fa fa-check"></i> <?php echo $tab_statuses; ?></a></li>
                    <?php endif; ?>
                    <li><a href="#tab-info" data-toggle="tab"><i class="fa fa-info-circle"></i> <?php echo $tab_info; ?></a></li>
                </ul>
                <div class="tab-content">
                    <?php if (isset($settings_loaded) && $settings_loaded) : ?>
                    <div class="tab-pane active" id="tab-settings">
                        <div class="form-group">
                            <label class="col-lg-4 control-label" for="input-merchant"><?php echo $entry_merchant; ?></label>
                            <div class="col-lg-8">
                            <input type="text" name="apirone_mccp_merchant" value="<?php echo $apirone_mccp_merchant; ?>" placeholder="<?php echo $entry_merchant; ?>" id="input-merchant" class="form-control" />
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-lg-4 control-label" for="input-testcustomer"><?php echo $entry_testcustomer; ?></label>
                            <div class="col-lg-8">
                                <input type="text" name="apirone_mccp_testcustomer" value="<?php echo $apirone_mccp_testcustomer; ?>" placeholder="<?php echo $entry_testcustomer_placeholder; ?>" id="input-testcustomer" class="form-control" />
                                <span class="contorl-label" style="margin-top: 4px; display: inline-block;"><?php echo $text_test_currency_customer; ?></span>
                            </div>
                        </div>
                        <div class="form-group required">
                            <label class="col-lg-4 control-label" for="input-timeout"><?php echo $entry_timeout; ?></label>
                            <div class="col-lg-8">
                                <input type="number" min="0" name="apirone_mccp_timeout" value="<?php echo $apirone_mccp_timeout; ?>" placeholder="<?php echo $entry_timeout; ?>" id="input-timeout" class="form-control" />
                                <?php if (isset($errors['apirone_mccp_timeout']) && $errors['apirone_mccp_timeout']) : ?>
                                <div class=" text-danger"><?php echo $errors['apirone_mccp_timeout']; ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-lg-4 control-label" for="input-processing-fee"><?php echo $entry_processing_fee; ?></label>
                            <div class="col-lg-8">
                                <select name="apirone_mccp_processing_fee" id="input-processing-fee" class="form-control">
                                    <option value="fixed"<?php echo $apirone_mccp_processing_fee == 'fixed' ? ' selected' : ''; ?>>
                                        <?php echo $text_processing_fee_fixed; ?>
                                    </option>
                                    <option value="percentage"<?php echo $apirone_mccp_processing_fee == 'percentage' ? ' selected' : ''; ?>>
                                        <?php echo $text_processing_fee_percentage; ?>
                                    </option>
                                </select>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-lg-4 control-label" for="input-with-fee">
                                <span data-toggle="tooltip" data-original-title="<?php echo $text_with_fee_tooltip; ?>"><?php echo $entry_with_fee; ?></span>
                            </label>
                            <div class="col-lg-8">
                                <select name="apirone_mccp_with_fee" id="input-with-fee" class="form-control">
                                    <?php if ($apirone_mccp_with_fee) : ?>
                                    <option value="1" selected="selected"><?php echo $text_enabled; ?></option>
                                    <option value="0"><?php echo $text_disabled; ?></option>
                                    <?php else : ?>
                                    <option value="1"><?php echo $text_enabled; ?></option>
                                    <option value="0" selected="selected"><?php echo $text_disabled; ?></option>
                                    <?php endif; ?>
                                </select>
                            </div>
                        </div>
                        <div class="form-group required">
                            <label class="col-lg-4 control-label" for="input-factor">
                                <span data-toggle="tooltip" data-original-title="<?php echo $text_factor_tooltip; ?>"><?php echo $entry_factor; ?></span>
                            </label>
                            <div class="col-lg-8">
                                <input name="apirone_mccp_factor" type="number" min="0.01" step='0.01' value="<?php echo $apirone_mccp_factor; ?>" id="input-factor" class="form-control" />
                                <?php if (isset($errors['apirone_mccp_factor'])) : ?>
                                <div class=" text-danger"><?php echo $errors['apirone_mccp_factor']; ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-lg-4 control-label" for="input-logo">
                                <span data-toggle="tooltip" data-original-title="<?php echo $text_logo_tooltip; ?>"><?php echo $entry_logo; ?></span>
                            </label>
                            <div class="col-lg-8">
                                <select name="apirone_mccp_logo" id="input-logo" class="form-control">
                                    <?php if ($apirone_mccp_logo) : ?>
                                    <option value="1" selected="selected"><?php echo $text_enabled; ?></option>
                                    <option value="0"><?php echo $text_disabled; ?></option>
                                    <?php else : ?>
                                    <option value="1"><?php echo $text_enabled; ?></option>
                                    <option value="0" selected="selected"><?php echo $text_disabled; ?></option>
                                    <?php endif; ?>
                                </select>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-lg-4 control-label" for="input-debug"><?php echo $entry_debug; ?></label>
                            <div class="col-lg-8">
                                <select name="apirone_mccp_debug" id="input-debug" class="form-control">
                                    <?php if ($apirone_mccp_debug) : ?>
                                    <option value="1" selected="selected"><?php echo $text_enabled; ?></option>
                                    <option value="0"><?php echo $text_disabled; ?></option>
                                    <?php else : ?>
                                    <option value="1"><?php echo $text_enabled; ?></option>
                                    <option value="0" selected="selected"><?php echo $text_disabled; ?></option>
                                    <?php endif; ?>
                                </select>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-lg-4 control-label" for="input-geo-zone"><?php echo $entry_geo_zone; ?></label>
                            <div class="col-lg-8">
                                <select name="apirone_mccp_geo_zone_id" id="input-geo-zone" class="form-control">
                                    <option value="0"><?php echo $text_all_zones; ?></option>
                                    <?php foreach ($geo_zones as $geo_zone) :?>
                                    <?php if ($geo_zone['geo_zone_id'] == $apirone_mccp_geo_zone_id) : ?>
                                    <option value="<?php echo $geo_zone['geo_zone_id']; ?>" selected="selected"><?php echo $geo_zone['name']; ?></option>
                                    <?php else : ?>
                                    <option value="<?php echo $geo_zone['geo_zone_id']; ?>"><?php echo $geo_zone['name']; ?></option>
                                    <?php endif; ?>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-lg-4 control-label" for="input-status"><?php echo $entry_status; ?></label>
                            <div class="col-lg-8">
                                <select name="apirone_mccp_status" id="input-status" class="form-control">
                                    <?php if ($apirone_mccp_status) : ?>
                                    <option value="1" selected="selected"><?php echo $text_enabled; ?></option>
                                    <option value="0"><?php echo $text_disabled; ?></option>
                                    <?php else : ?>
                                    <option value="1"><?php echo $text_enabled; ?></option>
                                    <option value="0" selected="selected"><?php echo $text_disabled; ?></option>
                                    <?php endif; ?>
                                </select>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-lg-4 control-label" for="input-sort-order"><?php echo $entry_sort_order; ?></label>
                            <div class="col-lg-8">
                                <input type="number" min="0" name="apirone_mccp_sort_order" value="<?php echo $apirone_mccp_sort_order; ?>" placeholder="<?php echo $entry_sort_order; ?>" id="input-sort-order" class="form-control" />
                            </div>
                        </div>
                    </div>
                    <div class="tab-pane" id="tab-currencies">
                        <?php foreach ($networks as $network => $network_dto) : ?>
                        <div class="form-group">
                            <label class="col-lg-4 control-label" for="address_<?php echo $network; ?>">
                                <span data-toggle="tooltip" data-original-title="<?php echo $network_dto->tooltip; ?>" syle="padding:10px 0;"><?php echo $network_dto->name; ?></span>
                            </label>
                            <div class="col-lg-8">
                                <div class="input-group">
                                    <span class="input-group-addon">
                                        <img src="view/image/payment/apirone/currencies/<?php echo $network_dto->icon; ?>.svg" width="18">
                                    </span>
                                    <input type="text" name="address[<?php echo $network; ?>]" value="<?php echo $network_dto->address; ?>" id="address_<?php echo $network; ?>" class="form-control" style="height:38px;"/>
                                </div>
                                <?php if (property_exists($network_dto, 'error') && $network_dto->error) : ?>
                                <div class=" text-danger"><?php echo $currency_address_incorrect; ?></div>
                                <?php endif ?>
                                <?php if ($network_dto->testnet) : ?>
                                <label class="control-label" style="color: inherit !important;"><span data-toggle="tooltip" data-original-title="<?php echo $network_dto->test_tooltip; ?>"><?php echo $text_test_currency; ?></span></label>
                                <?php endif; ?>
                                <?php if (property_exists($network_dto, 'tokens')) : ?>
                                <?php foreach ($network_dto->tokens as $abbr => $token_dto) : ?>
                                <div class="col-lg-8">
                                    <input class="checkbox-inline" style="margin-inline-end:4px;" type="checkbox" name="visible[<?php echo $abbr; ?>]" checked="<?php echo $token_dto->state; ?>" value="<?php echo $token_dto->state; ?>" id="<?php echo $token_dto->checkbox_id; ?>" class="form-control" />
                                    <label class="control-label" for="<?php echo $token_dto->checkbox_id; ?>">
                                        <img src="view/image/payment/apirone/currencies/<?php echo $token_dto->icon; ?>.svg" width="18">
                                        <span data-toggle="tooltip" data-original-title="<?php echo $token_dto->tooltip; ?>" style="font-size:12px;padding-left:4px;"><?php echo $token_dto->name; ?></span>
                                    </label>
                                </div>
                                <?php endforeach; ?>
                                <?php endif ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="tab-pane" id="tab-statuses">
                        <?php foreach ($apirone_mccp_invoice_status_ids as $apirone_status => $oc_status_id) : ?>
                        <div class="form-group">
                            <label class="col-lg-4 control-label" for="input-invoice-status-<?php echo $apirone_status; ?>"><?php echo $apirone_status_labels[$apirone_status]; ?></label>
                            <div class="col-lg-8">
                                <select name="apirone_mccp_invoice_<?php echo $apirone_status; ?>_status_id" id="input-invoice-status-<?php echo $apirone_status; ?>" class="form-control">
                                    <?php foreach ($order_statuses as $order_status) : ?>
                                    <?php if ($order_status['order_status_id'] == $oc_status_id) :?>
                                    <option value="<?php echo $order_status['order_status_id']; ?>" selected="selected"><?php echo $order_status['name']; ?></option>
                                    <?php else : ?>
                                    <option value="<?php echo $order_status['order_status_id']; ?>"><?php echo $order_status['name']; ?></option>
                                    <?php endif; ?>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    <div class="tab-pane" id="tab-info">
                        <div style="padding: 1rem 0; margin-bottom: 1rem">
                            <h4><?php echo $heading_testnet_hint; ?></h4>
                            <hr>
                            <p><?php echo $text_testnet_hint; ?></p>
                            <a target="_blank" href="https://coinfaucet.eu/en/btc-testnet/?lid=apirone">Coinfaucet</a><br>
                            <a target="_blank" href="https://bitcoinfaucet.uo1.net?lid=apirone">Bitcoinfaucet</a><br>
                            <a target="_blank" href="https://testnet-faucet.com/btc-testnet/?lid=apirone">Testnet faucet</a><br>
                            <a target="_blank" href="https://kuttler.eu/en/bitcoin/btc/faucet/?lid=apirone">Kuttler</a>
                            <hr>
                            <p class="mb-0"><strong><?php echo $text_read_more; ?>:</strong> <a href="https://apirone.com/faq" target="_blank">https://apirone.com/faq</a></p>
                        </div>
                        <div style="padding: 1rem 0; margin-bottom: 1rem">
                            <h4><?php echo $heading_plugin_info; ?></h4>
                            <hr>
                            <p>
                                <strong><?php echo $text_apirone_account; ?>:</strong> <?php echo $apirone_mccp_account; ?><br/>
                                <strong><?php echo $text_plugin_version; ?>:</strong> <?php echo $apirone_mccp_version; ?><br/>
                                <strong><?php echo $text_php_version; ?>:</strong> <?php echo $phpversion; ?><br/>
                                <strong><?php echo $text_oc_version; ?></strong>: <?php echo $oc_version; ?><br/>
                            </p>
                            <hr>
                            <p class="mb-0"><strong><?php echo $text_apirone_support; ?>:</strong> <a href="mailto:support@apirone.com">support@apirone.com</a></p>
                        </div>
                    </div>
                </div>
            </form>
            </div>
            <div class="panel-footer">
                <?php echo $text_apirone_survey; ?>
            </div>
        </div>
    </div>
</div>
<?php echo $footer; ?>
