<?php
/**
 * Created by Logan22
 * Github -> https://github.com/Cannabytes/SphereWeb
 * Date: 23.08.2022 / 23:20:45
 */

namespace Ofey\Logan22\model\user\player;

use Exception as ExceptionAlias;
use Ofey\Logan22\component\alert\board;
use Ofey\Logan22\component\base\base;
use Ofey\Logan22\component\lang\lang;
use Ofey\Logan22\component\time\time;
use Ofey\Logan22\model\admin\server;
use Ofey\Logan22\model\admin\validation;
use Ofey\Logan22\model\db\sdb;
use Ofey\Logan22\model\db\sql;
use Ofey\Logan22\model\encrypt\encrypt;
use Ofey\Logan22\model\user\auth\auth;
use Ofey\Logan22\model\user\auth\registration;

class player_account {

    //Запрещает или разрешает просмотр выбранного (своего персонажа) другими пользователями
    public static function forbiddenViewPlayerData(): void {
        validation::user_protection();
        $account = trim($_POST['account']);
        $player = trim($_POST['player']);
        $forbidden = (int)filter_var($_POST['forbidden'], FILTER_VALIDATE_BOOLEAN);
        $server_id = (int)$_POST['server_id'];

        $server_info = server::server_info($server_id);
        if (!$server_info) {
            board::notice(false, lang::get_phrase(150));
        }

        $player_info = player_account::is_player($server_info, [$player]);
        $player_info = $player_info->fetch();
        if($player_info['login']!=$account){
            board::notice(false, "Запрещенное действие");
        }
        if (sql::getRow("SELECT `forbidden` FROM `player_forbidden` WHERE server_id = ? AND account = ? AND player = ?", [$server_id, $account, $player])) {
            sql::getRow("UPDATE `player_forbidden` SET `forbidden` = ? WHERE server_id = ? AND account = ? AND `player` = ?", [$forbidden, $server_id, $account, $player]);
        } else {
            sql::run("INSERT INTO `player_forbidden` (`server_id`, `email`, `account`, `player`, `forbidden`) VALUES (?, ?, ?, ?, ?)", [$server_id, auth::get_email(), $account, $player, $forbidden]);
        }
    }

    /**
     * @param      $account_name название аккаунта
     *
     * @return mixed
     * @throws ExceptionAlias
     */
    public static function get_show_characters_info($account_name) {
        $info = sql::run("SELECT `email`, `server_id` FROM player_accounts WHERE login = ?", [$account_name])->fetch();
        if ($info == null) {
            return null;
        }
        return $info;
    }


    public static function get_forbidden_players($arrayAcPlayers = [], $server_id = 0){
        if(empty($arrayAcPlayers)){
            return false;
        }
        $account = $arrayAcPlayers[0]['account_name'];

        $allForbiddenInfo = sql::getRows("SELECT * FROM player_forbidden WHERE account = ? AND server_id=?;",[
            $account, $server_id,
        ]);

        foreach($arrayAcPlayers AS &$player){
            $player['forbidden'] = 1;
            foreach($allForbiddenInfo AS $info){
                if($player['player_name'] == $info['player']){
                    $player['forbidden'] = $info['forbidden'];
                }
            }
        }
        return $arrayAcPlayers;
    }

    public static function add_account_not_user($login, $password, $password_hide, $email) {
        $server_id = auth::get_default_server();
        self::valid_login($login);
        self::valid_password($password);
        self::valid_email($email);

        if (!auth::exist_user($email)) {
            registration::add($email, $password, true);
        }

        $reQuest = self::getReQuest($server_id, $login);
        $err = self::account_registration($reQuest, [
            $login,
            encrypt::server_password($password, $reQuest),
            $email,
        ]);
        //TODO: логирование ошибок
        if (is_array($err)) {
            if (!$err['ok']) {
                board::notice(false, $err['message']);
            }
        }
        self::add_inside_account($login, $password, $email, $_SERVER['REMOTE_ADDR'], $server_id, $password_hide);
        board::notice(true, lang::get_phrase(207));
    }

    public static function valid_login($login) {
        if (3 > mb_strlen($login)) {
            board::notice(false, lang::get_phrase(208));
        }
        if (16 < mb_strlen($login)) {
            board::notice(false, lang::get_phrase(209));
        }
        if (!preg_match("/^[a-zA-Z0-9]+$/", $login) == 1) {
            board::notice(false, lang::get_phrase(210));
        }
    }

    /*
     * Проверка существования персонажа
     */

    public static function valid_password($password) {
        if (4 > mb_strlen($password)) {
            board::notice(false, lang::get_phrase(211));
        }
        if (32 < mb_strlen($password)) {
            board::notice(false, lang::get_phrase(212));
        }
    }

    public static function valid_email($email) {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            board::notice(false, lang::get_phrase(213));
        }
    }

    //Имеется ли на персонаже предмет N

    /**
     * TODO: На будущее переделать, сначала проверить что N аккаунт пуст во внутренне БД и в БД сервера,
     * и только после этого производить регистрацию.
     *
     * @param $server_id
     * @param $login
     * @param $password
     * @param $password_hide
     *
     * @throws ExceptionAlias
     */
    public static function add($login, $password, $password_hide) {
        $server_id = auth::get_default_server();
        self::valid_login($login);
        self::valid_password($password);
        if (self::count_account($server_id) >= 20) {
            board::notice(false, lang::get_phrase(206));
        }
        sdb::setShowErrorPage(false);
        $reQuest = self::getReQuest($server_id, $login);
        $err = self::account_registration($reQuest, [
            $login,
            encrypt::server_password($password, $reQuest),
            auth::get_email(),
        ]);
        if (is_array($err)) {
            if (!$err['ok']) {
                board::notice(false, $err['message']);
            }
        }
        self::add_inside_account($login, $password, auth::get_email(), auth::get_ip(), $server_id, $password_hide);
        board::notice(true, lang::get_phrase(207));
    }

    static function count_account($server_id) {
        if (!auth::get_is_auth())
            return;
        return sql::run("SELECT COUNT(*) as `count` FROM player_accounts WHERE server_id = ? AND email = ?", [
            $server_id,
            auth::get_email(),
        ])->fetch()["count"];
    }

    /**
     * @param $server_id
     * @param $login
     *
     * @return mixed|void
     */
    public static function getReQuest($server_id, $login): mixed {
        $server_info = server::server_info($server_id);
        if (!$server_info) {
            board::notice(false, lang::get_phrase(150));
        }
        if (self::exist_account_inside($login, $server_id)) {
            board::alert([
                'ok' => false,
                'message' => lang::get_phrase(214),
                'getCode' => 0,
            ]);
        }
        $account = self::account_is_exist($server_info, $login);
        if (gettype($account) != "object") {
            if (!$account['ok']) {
                board::alert([
                    'ok' => false,
                    'message' => $account['message'],
                    'getCode' => 0,
                ]);
            }
        }
        if ($account->fetch()) {
            board::alert([
                'ok' => false,
                'message' => lang::get_phrase(214),
                'getCode' => 0,
            ]);
        }
        return $server_info;
    }

    public static function exist_account_inside($login, $server_id) {
        return sql::run("SELECT id, password_hide FROM player_accounts WHERE login = ? AND server_id = ?", [
            $login,
            $server_id,
        ])->fetch();
    }

    public static function account_is_exist($info, $prepare) {
        $base = base::get_sql_source($info['collection_sql_base_name'], "account_is_exist");
        return self::extracted($base, $info, $prepare);
    }

    /**
     * @throws ExceptionAlias
     */
    public static function extracted($sqlQuery, $info, $prepare = [], $gameServer = true) {
        if (gettype($prepare) == "string") {
            $prepare = [$prepare];
        }
        $prepare = self::placeholderPrepareFormat($sqlQuery, $prepare);
        $server_id = $info['id'];
        sdb::set_server_id($server_id);
        if ($gameServer) {
            sdb::set_type('game');
            sdb::set_connect($info['game_host'], $info['game_user'], $info['game_password'], $info['game_name']);
        } else {
            sdb::set_type('login');
            sdb::set_connect($info['login_host'], $info['login_user'], $info['login_password'], $info['login_name']);
        }
        return sdb::run($sqlQuery, $prepare);
    }

    /**
     * Функция для отсечения лишних параметров в массиве, которые превышают кол-во плейхолдеров в строке запроса
     * К примеру: В некоторых сборках при регистрации аккаунта можно указывать email, в других сборках такой колонки
     * в бд нет, но по умолчанию пользовательский email идет в запросе. Если в запросе нет его, мы отсекаем лишнее.
     */
    public static function placeholderPrepareFormat($query, $prepare) {
        $numPlaceholders = substr_count($query, '?');
        return array_slice($prepare, 0, $numPlaceholders);
    }

    /**
     * @throws ExceptionAlias
     */
    public static function account_registration($info, $prepare) {
        $sqlQuery = base::get_sql_source($info['collection_sql_base_name'], "account_registration");
        return self::extracted($sqlQuery, $info, $prepare, gameServer: false);
    }

    /*
     * Проверка на диапазон значений в пароле
     */

    /**
     * @throws ExceptionAlias
     */
    public static function add_inside_account($login, $password, $email, $ip, $server_id, $password_hide) {
        return sql::run("INSERT INTO `player_accounts` (`login`, `password`, `email`, `ip`, `server_id`, `password_hide`, `date_create`, `date_update`) VALUES (?, ?, ?, ?, ?, ?, ?, ?)", [
            $login,
            $password,
            $email,
            $ip,
            $server_id,
            (int)$password_hide,
            time::mysql(),
            time::mysql(),
        ]);
    }

    public static function is_player($info, $prepare) {
        $base = base::get_sql_source($info['collection_sql_base_name'], "is_player");
        return self::extracted($base, $info, $prepare);
    }

    public static function max_value_item_object($info, $prepare = []) {
        $base = base::get_sql_source($info['collection_sql_base_name'], 'max_value_item_object');
        return self::extracted($base, $info, $prepare);
    }

    //Проверка аккаунта во внутренней БД

    public static function check_item_player($info, $prepare = []) {
        $base = base::get_sql_source($info['collection_sql_base_name'], 'check_item_player');
        return self::extracted($base, $info, $prepare);
    }

    public static function update_item_count_player($info, $prepare = []) {
        $base = base::get_sql_source($info['collection_sql_base_name'], 'update_item_count_player');
        return self::extracted($base, $info, $prepare);
    }

    //Возвращаем список всех аккаунтов пользователя
    //$default_server - вернуть данные своих аккаунтов только сервера который по умолчанию

    public static function add_item($info, $prepare = []) {
        $base = base::get_sql_source($info['collection_sql_base_name'], 'add_item');
        return self::extracted($base, $info, $prepare);
    }

    //Кол-во имеющихся аккаунтов

    static function show_all_account_player($server_id = null) {
        if (!auth::get_is_auth())
            return;

        if ($server_id === null) {
            return sql::getRows("SELECT id, login, `password`, email, ip, server_id, password_hide, date_create, date_update FROM player_accounts WHERE email = ? ORDER BY date_create", [
                auth::get_email(),
            ]);
        }

        if (is_int((int)$server_id)) {
            return sql::getRows("SELECT id, login, `password`, email, ip, server_id, password_hide, date_create, date_update FROM player_accounts WHERE email = ? AND server_id = ? ORDER BY date_create", [
                auth::get_email(),
                $server_id,
            ]);
        }

        return sql::getRows("SELECT id, login, `password`, email, ip, server_id, password_hide, date_create, date_update FROM player_accounts WHERE email = ? AND server_id = ? ORDER BY date_create", [
            auth::get_email(),
            auth::get_default_server(),
        ]);
    }
}