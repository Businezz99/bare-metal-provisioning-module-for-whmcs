<?php
namespace HivelocityPricingTool\classes;

use Illuminate\Database\Capsule\Manager as Capsule;
use WHMCS\Module\AbstractModule;
use Exception;

class Addon extends AbstractModule {

    // Method to configure the module
    static public function config() {
        // Your existing config method remains unchanged.
        // ... (same as before)
    }

    // Method to handle output and form actions
    static public function output($params) {

        $crondisable = '';
        
        // Check cron status using WHMCS's cron logs (instead of using shell_exec)
        $cronStatus = Capsule::table('mod_hivelocity_cron')
                             ->where('value', 'RunFiveMinCron')
                             ->first();
        
        if ($cronStatus) {
            $crondisable = 'It seems cron is not set up yet. Please set the cron first.';
        }

        $disabled = '';
        $disabledmsg = '';

        // Check if the sync is in progress (same logic as before)
        $cronStatus = Capsule::table('mod_hivelocity_cron')
                             ->where('value', 'RunFiveMinCron')
                             ->first();
        
        if ($cronStatus) {
            $disabled = 'disabled';
            $disabledmsg = 'Product sync is in progress. It may take 5-10 minutes. Please be patient.';
        }

        if ($_GET['action'] == 'generateproducts') {
            // Prevent duplicate cron job entries
            Capsule::table('mod_hivelocity_cron')->where('value', 'RunFiveMinCron')->delete();
            Capsule::table('mod_hivelocity_cron')->insert([
                'value' => 'RunFiveMinCron',
                'created_at' => date('Y-m-d h:i:s')
            ]);
            $disabled = 'disabled';
            $disabledmsg = 'Product sync is in progress. It may take 5-10 minutes. Please be patient.';
        }

        $action = isset($_POST["hivelocityPricingToolAction"]) ? $_POST["hivelocityPricingToolAction"] : "";
        
        $success = false;
        $error = false;

        $productList = Helpers::getProductList();
        $totalActiveProducts = Helpers::countActiveProducts();
        $totalHiddenProducts = Helpers::countHiddenProducts();

        try {
            if ($action == "updatePricing") { 
                if ($_POST["globalchange"] == 'true') {
                    unset($_POST['DataTables_Table_0_length']);
                    $globalprofit = (float)$_POST["globalprofit"];
                    foreach ($productList as $productData) {
                        $remoteProductPrice = Helpers::getHivelocityProductPrice($productData["configoption1"]);
                        $profit = ($remoteProductPrice * $globalprofit) / 100;
                        $price = $remoteProductPrice + $profit;
                        $currencyId = $_POST["currencyId"];
                        
                        Helpers::setProductPrice($productData["id"], $price, $currencyId);
                    }
                } else {
                    foreach ($_POST["productId"] as $index => $productId) {
                        $price = $_POST["localPrice"][$index];
                        $currencyId = $_POST["currencyId"];
                        Helpers::setProductPrice($productId, $price, $currencyId);
                    }
                }

                $success = true;
            }
        } catch (Exception $e) {
            // Log error in WHMCS
            logModuleCall(
                'HivelocityPricingTool',
                'updatePricing',
                $_POST,
                $e->getMessage(),
                $e->getTraceAsString()
            );
            $error = $e->getMessage();
        }    

        $currencyList = Helpers::getCurrencyList(); 
        $smartyVarsCurrencyList = array();

        foreach ($currencyList as $currencyData) {
            $currencyId = $currencyData["id"];
            $smartyVarsCurrencyList[$currencyData["id"]] = array(
                "code" => $currencyData["code"],
                "suffix" => $currencyData["suffix"]
            );
        }

        $smartyVarsProductList = array();

        foreach ($productList as $productData) {
            $productId = $productData["id"];
            $serverConfig = Helpers::getServerConfigByProductId($productId);
        
            $apiUrl = $serverConfig["hostname"];
            $apiKey = $serverConfig["accesshash"];
            
            Api::setApiDetails($apiUrl, $apiKey);
            
            $remoteProductId = $productData["configoption1"];
            $remoteProductPrice = Helpers::getHivelocityProductPrice($remoteProductId);
            $usdRate = Helpers::getCurrencyRate("USD");
            
            if ($usdRate === false) {
                break;
            }

            $remoteProductPrice = $remoteProductPrice / $usdRate;
            
            $smartyVarsProductList[$productId] = array(
                "name" => $productData["name"],
                "hidden" => $productData["hidden"]
            );

            foreach ($currencyList as $currencyData) {
                $currencyId = $currencyData["id"];
                $productPrice = Helpers::getProductPrice($productId, $currencyId);
                $currencyRate = $currencyData["rate"];
                $remoteProductPriceConverted = $remoteProductPrice * $currencyRate;

                $profit = $productPrice - $remoteProductPriceConverted;
                $profitPercentage = ($remoteProductPrice != 0) ? ($profit / $remoteProductPrice) * 100 : 0;

                $smartyVarsProductList[$productId]["remotePrice"][$currencyId] = number_format($remoteProductPriceConverted, 2);
                $smartyVarsProductList[$productId]["localPrice"][$currencyId] = number_format($productPrice, 2);
                $smartyVarsProductList[$productId]["profit"][$currencyId] = number_format($profitPercentage, 2);
            }
        }

        // Initialize Smarty template
        $smarty = new \Smarty();
        $smarty->assign('activeProducts', $totalActiveProducts);
        $smarty->assign('hiddenProducts', $totalHiddenProducts);
        $smarty->assign('productList', $smartyVarsProductList);
        $smarty->assign('currencyList', $smartyVarsCurrencyList);
        $smarty->assign('success', $success);
        $smarty->assign('error', $error);
        $smarty->assign('disabled', $disabled);
        $smarty->assign('disabledmsg', $disabledmsg);
        $smarty->assign('crondisable', $crondisable);
        
        $smarty->caching = false;
        $smarty->compile_dir = $GLOBALS['templates_compiledir'];
        $smarty->display(dirname(dirname(__FILE__)) . '/templates/tpl/adminArea.tpl');
    }
}
?>
