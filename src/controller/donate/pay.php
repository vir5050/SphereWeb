<?php
/**
 * Created by Logan22
 * Github -> https://github.com/Cannabytes/SphereWeb
 * Date: 30.08.2022 / 0:33:14
 */

namespace Ofey\Logan22\controller\donate;

use Ofey\Logan22\component\alert\board;
use Ofey\Logan22\component\config\config;
use Ofey\Logan22\component\lang\lang;
use Ofey\Logan22\controller\page\error;
use Ofey\Logan22\model\admin\validation;
use Ofey\Logan22\model\db\sql;
use Ofey\Logan22\model\donate\donate;
use Ofey\Logan22\model\user\auth\auth;
use Ofey\Logan22\model\user\player\character;
use Ofey\Logan22\model\user\player\player_account;
use Ofey\Logan22\template\tpl;

class pay {

    public static function pay(): void {
        $dir = 'src/component/donate/';
        $donateSysNames = array_values(array_diff(scandir($dir), array('..', '.')));
        foreach($donateSysNames AS $i=>$sys){
            if(!$sys::isEnable()){
                unset($donateSysNames[$i]);
            }
        }
        if(!config::getEnableDonate()) error::error404("Отключено");
        $donateInfo = require_once 'src/config/donate.php';
        $point = 0;
        if(auth::get_is_auth()){
            if($donateInfo['DONATE_DISCOUNT_TYPE_STORAGE']){
                $point = donate::getBonusDiscount(auth::get_id(), $donateInfo['discount']['table']);
            }
        }
        tpl::addVar("donate_history_pay_self", donate::donate_history_pay_self());
        tpl::addVar("title", lang::get_phrase(233));
        tpl::addVar("discount", $donateInfo["discount"]);
        tpl::addVar("count_all_donate_bonus", sql::run("SELECT SUM(point) AS `count` FROM donate_history_pay WHERE user_id = ?", [auth::get_id()])->fetch()['count'] ?? 0);
        tpl::addVar("procentDiscount", $point);
        tpl::addVar("donateSysNames", $donateSysNames);
        tpl::display("/donate/pay.html");
    }

    public static function shop(): void {
        if(!config::getEnableDonate()) error::error404("Отключено");

        $donateInfo = require_once 'src/config/donate.php';
        $point = 0;
        if(auth::get_is_auth()){
            if($donateInfo['DONATE_DISCOUNT_TYPE_PRODUCT_ENABLE']){
                $point = donate::getBonusDiscount(auth::get_id(), $donateInfo['discount_product']['table']);
            }
        }
        tpl::addVar("DONATE_DISCOUNT_TYPE_PRODUCT_ENABLE", $donateInfo['DONATE_DISCOUNT_TYPE_PRODUCT_ENABLE']);
        tpl::addVar("DONATE_DISCOUNT_COUNT_ENABLE", $donateInfo['DONATE_DISCOUNT_COUNT_ENABLE']);
        tpl::addVar("discount_count_product_table", $donateInfo["discount_count_product"]['table']);
        tpl::addVar("discount_count_product_items", $donateInfo["discount_count_product"]['items']);
        tpl::addVar("procentProductDiscount", $point);
        tpl::addVar("donate_history", donate::donate_history());
        tpl::addVar("products", donate::products());
        tpl::addVar("title", lang::get_phrase(233));
        tpl::display("/donate/shop.html");
    }

    public static function transaction(): void {
        validation::user_protection();
        if(!config::getEnableDonate()) error::error404("Отключено");
        if(!auth::get_is_auth()) board::notice(false, lang::get_phrase(234));
        donate::transaction();
    }

    public static function currency_exchange_info(){
        echo json_encode(require 'src/config/donate.php');
    }
}