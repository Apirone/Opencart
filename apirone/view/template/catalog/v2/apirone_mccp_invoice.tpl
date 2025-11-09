<!DOCTYPE html>
<html>
    <head>
        <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
        <script>
            window.apirone_config = {
                service_url: 'index.php?route=<?php echo $apirone_path_for_routes; ?>',
                invoices_ep: 'invoices&id=%s',
                images_relative_path: '<?php echo $apirone_path_to_images; ?>',
                <?php echo $apirone_config; ?>
            };
        </script>
        <script type="module" crossorigin src="<?php echo $apirone_path_to_js; ?>script.min.js"></script>
        <link rel="stylesheet" crossorigin href="<?php echo $apirone_path_to_css; ?>style.min.css">
    </head>
    <body>
        <div id="app"></div>
    </body>
</html>