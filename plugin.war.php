<?php

Aseco::registerEvent('onSync', 'war_setup');
Aseco::registerEvent('onPlayerConnect', 'war_playerConnected');
Aseco::registerEvent('onPlayerFinish', 'war_updateWidgets');
Aseco::registerEvent('onPlayerManialinkPageAnswer', 'war_toggleWindow');
Aseco::registerEvent('onNewChallenge', 'war_loadxml');

Aseco::addChatCommand('war', 'Manage war commands');

class WarPlugin
{
    public $aseco;
    public $warmode = null;
    public $xml = null;

    public function __construct($aseco)
    {
        $this->aseco = $aseco;
        $this->loadXmlFile();
    }

    public function loadXmlFile()
    {
        if ($this->xml === null) {
            // Read Configuration
            if (!$xml_config = simplexml_load_file('war.xml')) {
                trigger_error('[plugin.war.php] Could not read/parse config file "war.xml"!', E_USER_ERROR);
            }
            $this->xml = $xml_config;
        }
    }

    private function isMasterAdmin($player)
    {
        // check if masteradmin
        if ($this->aseco->isMasterAdmin($player)) {
            return true;
        }

        return false;
    }

    private function isCaptainOrMasterAdmin($player)
    {
        // check if masteradmin
        if ($this->aseco->isMasterAdmin($player)) {
            return true;
        }

        $sql = 'SELECT * from players where Login="' . $player->login . '"';
        $res = $this->arrayQuery($sql);
        if (count($res) > 0) {
            $levelQuery = 'SELECT * from war_team_players where player_id=' . $res[0]['Id'];
            $levelRes = $this->arrayQuery($levelQuery);
            $level = $levelRes[0]['level'];
            if ($level == 1) {
                return true;
            }
        }

        return false;
    }

    public function setupDb()
    {
        global $maxrecs;
        // create main tables
        $query1 = "CREATE TABLE IF NOT EXISTS `war_teams` (
                `Id` mediumint(9) NOT NULL auto_increment,
                `team_identifiers` varchar(200) NOT NULL default '',
                `team_name` varchar(200) NOT NULL default '',
                `team_avg` float NOT NULL default '" . ($maxrecs * 10000) . "',
                PRIMARY KEY (`Id`)
            ) ENGINE=MyISAM AUTO_INCREMENT=1 DEFAULT CHARSET=utf8;";

        mysql_query($query1);

        $query = "CREATE TABLE IF NOT EXISTS `war_team_players` (
                `player_id` mediumint(9) NOT NULL,
                `team_id` mediumint(9) NOT NULL,
                `level` mediumint(9) NOT NULL default 0,
                PRIMARY KEY  (`player_id`),
                INDEX `team_id` (`team_id`)
                ) ENGINE=MyISAM AUTO_INCREMENT=0 DEFAULT CHARSET=utf8;";

        mysql_query($query);


        $query3 = "CREATE TABLE IF NOT EXISTS `war_settings` (
            `Id` mediumint(9) NOT NULL auto_increment,
            `max_point_positions` integer(2) NOT NULL default 10,
            `war_mode` varchar(20) NOT NULL default 'team',
            PRIMARY KEY (`Id`)
        ) ENGINE=MyISAM AUTO_INCREMENT=1 DEFAULT CHARSET=utf8;";

        mysql_query($query3);
        $result = mysql_query("SELECT * FROM war_settings");
        $num_rows = mysql_num_rows($result);
        if ($num_rows === 0) {
            $sql1 = 'INSERT INTO war_settings (max_point_positions) VALUES (10)';
            mysql_query($sql1);
        }
    }

    public function createTeam($params, $command)
    {
        $player = $command['author'];
        if (!$this->isCaptainOrMasterAdmin($player)) {
            $this->aseco->client->query('ChatSendServerMessageToLogin', $this->aseco->formatColors('NICE TRY BUT NO BANANA'), $player->login);
            return;
        }

        $team = array_shift($params);
        $identifiers = $params;

        $query = 'INSERT INTO `war_teams` (team_identifiers, team_name) VALUES (' . quotedString(implode(",", $identifiers)) . ',' . quotedString($team) . ')';

        $result = mysql_query($query);

        $this->aseco->client->query('ChatSendServerMessageToLogin', $this->aseco->formatColors('Team: ' . $team . ' added, with identifiers: ' . implode(', ', $identifiers)), $player->login);
    }

    public function addTag($params, $command)
    {
        $player = $command['author'];
        if (!$this->isCaptainOrMasterAdmin($player)) {
            $this->aseco->client->query('ChatSendServerMessageToLogin', $this->aseco->formatColors('NICE TRY BUT NO BANANA'), $player->login);
            return;
        }

        $teamId = array_shift($params);
        $newIdentifiers = $params;

        $sql = 'SELECT * from war_teams where Id=' . $teamId;
        $res = $this->arrayQuery($sql);
        if (count($res) > 0) {
            $oldIdentifiers = $res[0]['team_identifiers'];
            $identifers = $oldIdentifiers . ',' . implode(',', $newIdentifiers);
            $query = 'UPDATE `war_teams` set team_identifiers = ' . quotedString($identifers) . ' WHERE Id=' . $teamId;
            $result = mysql_query($query);
        }

        $this->aseco->client->query('ChatSendServerMessageToLogin', $this->aseco->formatColors('Identifiers added: ' . implode(', ', $identifers)), $player->login);
    }

    public function getPlayerPointsPerMap($challengeId, $playerId)
    {
        $query1 = 'SELECT * FROM war_settings';
        $resQ1 = $this->arrayQuery($query1);

        $sql1 = 'SELECT r.PLayerID as player_id, r.Score as score From records r left join challenges c on r.ChallengeId = c.Id where r.ID IS NOT NULL AND c.id=' . $challengeId . ' ORDER BY Score ASC, Date ASC';
        $res1 = $this->arrayQuery($sql1);

        if (count($res1) === 0) {
            return 0;
        }

        $maxPointsPerMapSetting = $resQ1[0]['max_point_positions'];
        $playerPos = 0;
        $playerPoint = 0;

        $points = range($maxPointsPerMapSetting, 1);

        foreach ($res1 as $i => $rec) {
            if ($playerId === $rec['player_id']) {
                $playerPoint = $points[$i];
            }
        }

        return $playerPoint;
    }

    public function drawPlayerScoreWidget()
    {
        if ($this->xml->your_score_widget->enabled == false || $this->xml->your_score_widget->enabled == 'false') {
            return;
        }

        foreach ($this->aseco->server->players->player_list as $player) {
            $playerPoint = $this->getPlayerPointsPerMap($this->aseco->server->challenge->id, $player->id);

            $sql = 'SELECT * from challenges';
            $res = $this->arrayQuery($sql);
            $pPoints = 0;
            foreach ($res as $map) {
                $pPoints += $this->getPlayerPointsPerMap($map['Id'], $player->id);
            }
            $playerScoreWidgetHeight = 7;

            $xml = '<?xml version="1.0" encoding="UTF-8"?>';
            $xml .= '<manialink id="123123458">';
            if ($this->getWarMode() === 'team') {
                $xml .= '<frame posn="' . $this->xml->your_score_widget->team->pos_x . ' ' . $this->xml->your_score_widget->team->pos_y . '">';
            } else {
                $xml .= '<frame posn="' . $this->xml->your_score_widget->all->pos_x . ' ' . $this->xml->your_score_widget->all->pos_y . '">';
            }
            $xml .= '<format textsize="0.5"/>';

            $xml .= '<quad  posn="0 0" sizen="10 ' . $playerScoreWidgetHeight . '" halign="center" valign="top" style="BgsPlayerCard" substyle="ProgressBar" />';
            if ($this->getWarMode() === 'team') {
                $xml .= '<label posn="0 -1" sizen="10 2" halign="center" valign="top" text="$o' . $this->xml->your_score_widget->team->title . '"/>';
            } else {
                $xml .= '<label posn="0 -1" sizen="10 2" halign="center" valign="top" text="$o' . $this->xml->your_score_widget->all->title . '"/>';
            }
            $posY = -2.6;
            $xml .= '<label posn="-4.3 ' . $posY . '" sizen="10 2" halign="left" textsize="1.2" valign="top" text="This track:"/>';
            $xml .= '<label posn="4.3 ' . $posY . '" sizen="10 2" halign="right" textsize="1.2" valign="top" text="$fff' . $playerPoint . '"/>';

            $xml .= '<label posn="-4.3 -4.6" sizen="10 2" halign="left" textsize="1.2" valign="top" text="Total:"/>';
            $xml .= '<label posn="4.3 -4.6" sizen="10 2" halign="right" textsize="1.2" valign="top" text="$fff' . $pPoints . '"/>';

            $xml .= '</frame></manialink>';
            $this->aseco->client->query("SendDisplayManialinkPageToLogin", $player->login, $xml, 0, false);
        }
    }

    public function drawMapScoreWidget()
    {
        if ($this->xml->map_score_widget->enabled == false || $this->xml->map_score_widget->enabled == 'false') {
            return;
        }
        $boxHeight = 3;
        // $query = 'SELECT wt.team_name, wtp.player_id FROM war_team_players wtp join war_teams wt on wtp.team_id = wt.Id';
        // $res = $this->arrayQuery($query);

        $query = 'SELECT * FROM war_teams';
        $res = $this->arrayQuery($query);

        $mapsQuery = 'SELECT * FROM challenges';
        $maps = $this->arrayQuery($mapsQuery);
        $teams = [];

        foreach ($res as $i => $team) {
            $teams[$i]['team_name'] = $team['team_name'];
            $teams[$i]['score'] = 0;
            $teamPlayerQuery = 'SELECT * FROM war_team_players where team_id=' . $team['Id'];
            $teamPlayers = $this->arrayQuery($teamPlayerQuery);
            if (count($teamPlayers) > 0) {
                foreach ($teamPlayers as $player) {
                    $teams[$i]['score'] += $this->getPlayerPointsPerMap($this->aseco->server->challenge->id, $player['player_id']);
                }
            }
        }

        // Team score widgeten
        $boxHeight = (count($res) * 2) + $boxHeight;
        $xml = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<manialink id="123123459">';
        $xml .= '<frame posn="' . $this->xml->map_score_widget->pos_x . ' ' . $this->xml->map_score_widget->pos_y . '">';
        $xml .= '<format textsize="0.5"/>';

        $xml .= '<quad  posn="0 0" sizen="10 ' . $boxHeight . '" halign="center" valign="top" style="BgsPlayerCard" substyle="ProgressBar" />';

        $xml .= '<label posn="0 -1" sizen="10 2" halign="center" valign="top" text="$o' . $this->xml->map_score_widget->title . '"/>';
        $posY = -2.6;
        foreach ($teams as $team) {
            $xml .= '<label posn="-4.3 ' . $posY . '" sizen="10 2" halign="left" textsize="1.2" valign="top" text="' . $team['team_name'] . ':"/>';
            $xml .= '<label posn="4.3 ' . $posY . '" sizen="10 2" halign="right" textsize="1.2" valign="top" text="$fff' . $team['score'] . '"/>';
            $posY = $posY - 2;
        }

        $xml .= '</frame></manialink>';

        $this->aseco->client->query("SendDisplayManialinkPage", $xml, 0, false);
    }

    public function drawTeamsWidget()
    {
        if ($this->xml->war_score_widget->enabled == false || $this->xml->war_score_widget->enabled == 'false') {
            return;
        }
        $boxHeight = 3;
        // $query = 'SELECT wt.team_name, wtp.player_id FROM war_team_players wtp join war_teams wt on wtp.team_id = wt.Id';
        // $res = $this->arrayQuery($query);

        $query = 'SELECT * FROM war_teams';
        $res = $this->arrayQuery($query);

        $mapsQuery = 'SELECT * FROM challenges';
        $maps = $this->arrayQuery($mapsQuery);
        $teams = [];

        foreach ($res as $i => $team) {
            $teams[$i]['team_name'] = $team['team_name'];
            $teams[$i]['score'] = 0;
            $teamPlayerQuery = 'SELECT * FROM war_team_players where team_id=' . $team['Id'];
            $teamPlayers = $this->arrayQuery($teamPlayerQuery);
            if (count($teamPlayers) > 0) {
                foreach ($maps as $map) {
                    foreach ($teamPlayers as $player) {
                        $teams[$i]['score'] += $this->getPlayerPointsPerMap($map['Id'], $player['player_id']);
                    }
                }
            }
        }

        // Team score widgeten
        $boxHeight = (count($res) * 2) + $boxHeight;
        $xml = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<manialink id="123123457">';
        $xml .= '<frame posn="' . $this->xml->war_score_widget->pos_x . ' ' . $this->xml->war_score_widget->pos_y . '">';
        $xml .= '<format textsize="0.5"/>';

        $xml .= '<quad  posn="0 0" sizen="10 ' . $boxHeight . '" halign="center" valign="top" style="BgsPlayerCard" substyle="ProgressBar" />';

        $xml .= '<label posn="0 -1" sizen="10 2" halign="center" valign="top" text="$o' . $this->xml->war_score_widget->title . '"/>';
        $posY = -2.6;
        foreach ($teams as $team) {
            $xml .= '<label posn="-4.3 ' . $posY . '" sizen="10 2" halign="left" textsize="1.2" valign="top" text="' . $team['team_name'] . ':"/>';
            $xml .= '<label posn="4.3 ' . $posY . '" sizen="10 2" halign="right" textsize="1.2" valign="top" text="$fff' . $team['score'] . '"/>';
            $posY = $posY - 2;
        }

        $xml .= '</frame></manialink>';

        $this->aseco->client->query("SendDisplayManialinkPage", $xml, 0, false);
    }

    public function drawPointsWindow()
    {
        global $re_config;
        global $re_scores;
        $xml = str_replace(
            array(
                '%icon_style%',
                '%icon_substyle%',
                '%window_title%',
                '%prev_next_buttons%'
            ),
            array(
                $re_config['DEDIMANIA_RECORDS'][0]['ICON_STYLE'][0],
                $re_config['DEDIMANIA_RECORDS'][0]['ICON_SUBSTYLE'][0],
                'All player points',
                ''
            ),
            $re_config['Templates']['WINDOW']['HEADER']
        );

        $xml .= '<frame posn="2.5 -6.5 1">';
        $xml .= '<format textsize="1" textcolor="' . $re_config['STYLE'][0]['WIDGET_RACE'][0]['COLORS'][0]['DEFAULT'][0] . '"/>';

        $xml .= '<quad posn="0 0.8 0.02" sizen="17.75 46.88" style="BgsPlayerCard" substyle="BgRacePlayerName"/>';
        $xml .= '<quad posn="19.05 0.8 0.02" sizen="17.75 46.88" style="BgsPlayerCard" substyle="BgRacePlayerName"/>';
        $xml .= '<quad posn="38.1 0.8 0.02" sizen="17.75 46.88" style="BgsPlayerCard" substyle="BgRacePlayerName"/>';
        $xml .= '<quad posn="57.15 0.8 0.02" sizen="17.75 46.88" style="BgsPlayerCard" substyle="BgRacePlayerName"/>';


        // Add all connected PlayerLogins
        $players = array();
        foreach ($this->aseco->server->players->player_list as &$player) {
            $players[] = $player->login;
        }
        unset($player);

        $playerRecs = $this->getPlayerRecs();


        $rank = 1;
        $line = 0;
        $offset = 0;
        foreach ($playerRecs as &$item) {

            // Mark current connected Players
            if (in_array($item['login'], $players)) {
                $xml .= '<quad posn="' . ($offset + 0.4) . ' ' . (((1.83 * $line - 0.2) > 0) ? - (1.83 * $line - 0.2) : 0.2) . ' 0.03" sizen="16.95 1.83" style="' . $re_config['STYLE'][0]['WIDGET_RACE'][0]['HIGHLITE_OTHER_STYLE'][0] . '" substyle="' . $re_config['STYLE'][0]['WIDGET_RACE'][0]['HIGHLITE_OTHER_SUBSTYLE'][0] . '"/>';
            }

            $xml .= '<label posn="' . (2.6 + $offset) . ' -' . (1.83 * $line) . ' 0.04" sizen="2 1.7" halign="right" scale="0.9" text="' . $rank . '."/>';
            $xml .= '<label posn="' . (6.4 + $offset) . ' -' . (1.83 * $line) . ' 0.04" sizen="4 1.7" halign="right" scale="0.9" textcolor="' . $re_config['STYLE'][0]['WIDGET_RACE'][0]['COLORS'][0]['SCORES'][0] . '" text="' . $item['points'] . '"/>';
            $xml .= '<label posn="' . (6.9 + $offset) . ' -' . (1.83 * $line) . ' 0.04" sizen="11.2 1.7" scale="0.9" text="' . str_replace('$i', '', $item['nickname']) . '"/>';

            $line++;
            $rank++;

            // Reset lines
            if ($line >= 25) {
                $offset += 19.05;
                $line = 0;
            }

            // Display max. 100 entries, count start from 1
            if ($rank >= 101) {
                break;
            }
        }
        unset($item);
        $xml .= '</frame>';

        $xml .= $re_config['Templates']['WINDOW']['FOOTER'];

        return $xml;
    }

    public function sendManialinks($widgets, $login = false, $timeout = 0)
    {
        $xml  = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<manialinks>';
        $xml .= $widgets;
        $xml .= '</manialinks>';

        if ($login != false) {
            // Send to given Player
            $this->aseco->client->query('SendDisplayManialinkPageToLogin', $login, $xml, ($timeout * 1000), false);
        } else {
            // Send to all connected Players
            $this->aseco->client->query('SendDisplayManialinkPage', $xml, ($timeout * 1000), false);
        }
    }

    public function resetWar($command)
    {
        $player = $command['author'];
        if (!$this->isMasterAdmin($player)) {
            $this->aseco->client->query('ChatSendServerMessageToLogin', $this->aseco->formatColors('NICE TRY BUT NO BANANA'), $player->login);
            return;
        }

        $query = 'DELETE FROM `war_team_players`';
        mysql_query($query);

        $query1 = 'DELETE FROM `war_teams`';
        mysql_query($query1);

        $msg = 'War is reset';

        $this->aseco->client->query('ChatSendServerMessageToLogin', $this->aseco->formatColors($msg), $player->login);
    }

    public function listTeams($command)
    {
        $player = $command['author'];
        $query = 'SELECT * FROM `war_teams`';
        $result = $this->arrayQuery($query);
        $msg = 'This is the registered teams: ';
        foreach ($result as $team) {
            $msg .= $team['Id'] . ': ' .  $team['team_name'] . ' (' . $team['team_identifiers'] . '), ';
        }

        $msg = rtrim($msg, ', ');

        $this->aseco->client->query('ChatSendServerMessageToLogin', $this->aseco->formatColors($msg), $player->login);
    }

    public function addPlayer($params, $command)
    {
        $player = $command['author'];
        if (!$this->isCaptainOrMasterAdmin($player)) {
            $this->aseco->client->query('ChatSendServerMessageToLogin', $this->aseco->formatColors('NICE TRY BUT NO BANANA'), $player->login);
            return;
        }

        $playerQuery = 'SELECT * from players where Login="' . $params[0] . '"';
        $res = $this->arrayQuery($playerQuery);
        if (count($res)) {
            $del = 'DELETE from war_team_players WHERE player_id=' . $res[0]['Id'];
            mysql_query($del);

            $insert = 'INSERT INTO war_team_players (player_id, team_id) VALUES (' . $res[0]['Id'] . ',' . $params[1] . ')';
            mysql_query($insert);
            $msg = 'Player added to team';
            $this->aseco->client->query('ChatSendServerMessageToLogin', $this->aseco->formatColors($msg), $player->login);
        }
    }

    public function removePlayer($params, $command)
    {
        $player = $command['author'];
        if (!$this->isCaptainOrMasterAdmin($player)) {
            $this->aseco->client->query('ChatSendServerMessageToLogin', $this->aseco->formatColors('NICE TRY BUT NO BANANA'), $player->login);
            return;
        }

        $playerQuery = 'SELECT * from players where Login="' . $params[0] . '"';
        $res = $this->arrayQuery($playerQuery);
        if (count($res)) {
            $del = 'DELETE from war_team_players WHERE player_id=' . $res[0]['Id'];
            mysql_query($del);

            $msg = 'Player removed from team';
            $this->aseco->client->query('ChatSendServerMessageToLogin', $this->aseco->formatColors($msg), $player->login);
        }
    }

    public function addCaptain($params, $command)
    {
        $player = $command['author'];
        if (!$this->isCaptainOrMasterAdmin($player)) {
            $this->aseco->client->query('ChatSendServerMessageToLogin', $this->aseco->formatColors('NICE TRY BUT NO BANANA'), $player->login);
            return;
        }
        $playerQuery = 'SELECT * from players where Login="' . $params[0] . '"';
        $res = $this->arrayQuery($playerQuery);
        if (count($res)) {

            $insert = 'UPDATE war_team_players set level=1 where player_id =' . $res[0]['Id'] . '';
            mysql_query($insert);
            $msg = 'Player set as captain';
            $this->aseco->client->query('ChatSendServerMessageToLogin', $this->aseco->formatColors($msg), $player->login);
        }
    }

    public function updateSettings($params, $command)
    {
        $player = $command['author'];
        if (!$this->isMasterAdmin($player)) {
            $this->aseco->client->query('ChatSendServerMessageToLogin', $this->aseco->formatColors('NICE TRY BUT NO BANANA'), $player->login);
            return;
        }
        $max = array_shift($params);
        $sql = 'DELETE from war_settings';
        mysql_query($sql);

        $sql1 = 'INSERT INTO war_settings (max_point_positions) VALUES (' . $max . ')';
        mysql_query($sql1);
        $msg = 'Settings updated';
        $this->aseco->client->query('ChatSendServerMessageToLogin', $this->aseco->formatColors($msg), $player->login);
    }

    public function addPlayerToTeam($player)
    {
        $query = 'SELECT * FROM `war_team_players` WHERE player_id=' . $player->id;
        $res = $this->arrayQuery($query);
        if (count($res) > 0) {
            $msg = 'Welcome! You are not alone, you belong to a team.';
            $this->aseco->client->query('ChatSendServerMessageToLogin', $this->aseco->formatColors($msg), $player->login);
        } else {
            $matchedTeam = null;
            $nickname = $player->nickname;
            $sql = "SELECT * FROM war_teams";
            $res = $this->arrayQuery($sql);
            foreach ($res as $team) {
                $ids = explode(",", $team['team_identifiers']);
                foreach ($ids as $id) {
                    if (strpos($nickname, $id) !== false) {
                        $matchedTeam = $team;
                    }
                }
            }

            if ($matchedTeam === null) {
                $msg = 'No team matches your nickname, ask an admin or team captain to manually add you or change nickname';
                $this->aseco->client->query('ChatSendServerMessageToLogin', $this->aseco->formatColors($msg), $player->login);
                $this->aseco->console($msg);
            } else {
                $query = 'INSERT INTO war_team_players (player_id, team_id) VALUES (' . $player->id . ',' . $matchedTeam['Id'] . ')';
                $this->aseco->console($query);
                mysql_query($query);

                $msg = 'You were added to team: ' . $matchedTeam['team_name'];
                $this->aseco->client->query('ChatSendServerMessageToLogin', $this->aseco->formatColors($msg), $player->login);
                $this->aseco->console($msg);
            }
        }
    }

    public function setMode($params, $command)
    {
        $player = $command['author'];
        if (!$this->isMasterAdmin($player)) {
            $this->aseco->client->query('ChatSendServerMessageToLogin', $this->aseco->formatColors('NICE TRY BUT NO BANANA'), $player->login);
            return;
        }

        $mode = array_shift($params);

        if ($mode === 'team' || $mode === 'all') {
            $sql = 'UPDATE war_settings set war_mode="' . $mode . '"';
            mysql_query($sql);
            $this->warmode = $mode;
            $this->aseco->client->query('ChatSendServerMessageToLogin', $this->aseco->formatColors('War mode updated'), $player->login);

            if ($mode === 'all') {
                $xml = '';
                $xml .= '<manialink id="123123459"></manialink>';
                $xml .= '<manialink id="123123457"></manialink>';

                $this->aseco->client->query("SendDisplayManialinkPage", $xml, 0, false);
                $this->drawPlayerScoreWidget();
            }
        } else {
            $this->aseco->client->query('ChatSendServerMessageToLogin', $this->aseco->formatColors('That mode does not exist'), $player->login);
        }
    }

    public function getWarMode()
    {
        if ($this->warmode === null) {
            $sql = 'SELECT * from war_settings';
            $res = $this->arrayQuery($sql);
            $mode = $res[0]['war_mode'];
            $this->warmode = $mode;
        }

        return $this->warmode;
    }

    public function drawSidebar()
    {
        // $xml = '<?xml version="1.0" encoding="UTF-8"';
        // $xml .= '<manialink id="123123421">';
        // $xml .= '<frame posn="-49.2 -10.7">';

        // $xml .= '<quad posn="0 0" sizen="15.5 15.5" style="Bgs1InRace" substyle="NavButton"/>';

        // $xml .= '</frame></manialink>';

        global $re_config;

        if ($this->xml->sidebar_widget->enabled == false || $this->xml->sidebar_widget->enabled == 'false') {
            return;
        }

        $gamemode = 1;
        $header = '<?xml version="1.0" encoding="UTF-8"?>';
        $header .= '<manialink id="%manialinkid%">';
        $header .= '<frame posn="%posx% %posy% 0">';

        $header .= '<quad posn="0 0 0.001" sizen="%widgetwidth% %widgetheight%" action="%actionid%" style="' . $re_config['STYLE'][0]['WIDGET_RACE'][0]['BACKGROUND_STYLE'][0] . '" substyle="' . $re_config['STYLE'][0]['WIDGET_RACE'][0]['BACKGROUND_SUBSTYLE'][0] . '"/>';
        $header .= '<quad posn="0.4 -2.6 0.002" sizen="2 %column_height%" bgcolor="' . $re_config['STYLE'][0]['WIDGET_RACE'][0]['COLORS'][0]['BACKGROUND_RANK'][0] . '"/>';
        $header .= '<quad posn="2.4 -2.6 0.002" sizen="3.65 %column_height%" bgcolor="' . $re_config['STYLE'][0]['WIDGET_RACE'][0]['COLORS'][0]['BACKGROUND_SCORE'][0] . '"/>';
        $header .= '<quad posn="6.05 -2.6 0.002" sizen="%column_width_name% %column_height%" bgcolor="' . $re_config['STYLE'][0]['WIDGET_RACE'][0]['COLORS'][0]['BACKGROUND_NAME'][0] . '"/>';
        $header .= '<quad posn="%image_open_pos_x% %image_open_pos_y% 0.05" sizen="3.5 3.5" image="%image_open%"/>';

        // Icon and Title
        $header .= '<quad posn="0.4 -0.36 0.002" sizen="%title_background_width% 2" style="' . $re_config['STYLE'][0]['WIDGET_RACE'][0]['TITLE_STYLE'][0] . '" substyle="' . $re_config['STYLE'][0]['WIDGET_RACE'][0]['TITLE_SUBSTYLE'][0] . '"/>';
        $header .= '<quad posn="%posx_icon% %posy_icon% 0.004" sizen="2.5 2.5" style="%icon_style%" substyle="%icon_substyle%"/>';
        $header .= '<label posn="%posx_title% %posy_title% 0.004" sizen="10.2 0" halign="%halign%" textsize="1" text="' . $this->xml->sidebar_widget->title . '"/>';
        $header .= '<format textsize="1" textcolor="' . $re_config['STYLE'][0]['WIDGET_RACE'][0]['COLORS'][0]['DEFAULT'][0] . '"/>';

        $footer  = '</frame>';
        $footer .= '</manialink>';


        // Set the right Icon and Title position
        $position = (($re_config['LIVE_RANKINGS'][0]['GAMEMODE'][0][$gamemode][0]['POS_X'][0] < 0) ? 'left' : 'right');

        // Set the Topcount
        $topcount = $this->xml->sidebar_widget->topcount;

        // Calculate the widget height (+ 3.3 for title)
        $widget_height = ($re_config['LineHeight'] * $this->xml->sidebar_widget->entries + 5.3);

        if ($position == 'right') {
            $imagex    = ($re_config['Positions'][$position]['image_open']['x'] + ($re_config['LIVE_RANKINGS'][0]['WIDTH'][0] - 15.5));
            $iconx    = ($re_config['Positions'][$position]['icon']['x'] + ($re_config['LIVE_RANKINGS'][0]['WIDTH'][0] - 15.5));
            $titlex    = ($re_config['Positions'][$position]['title']['x'] + ($re_config['LIVE_RANKINGS'][0]['WIDTH'][0] - 15.5));
        } else {
            $imagex    = $re_config['Positions'][$position]['image_open']['x'];
            $iconx    = $re_config['Positions'][$position]['icon']['x'];
            $titlex    = $re_config['Positions'][$position]['title']['x'];
        }

        $build['header'] = str_replace(
            array(
                '%manialinkid%',
                '%actionid%',
                '%posx%',
                '%posy%',
                '%image_open_pos_x%',
                '%image_open_pos_y%',
                '%image_open%',
                '%posx_icon%',
                '%posy_icon%',
                '%icon_style%',
                '%icon_substyle%',
                '%halign%',
                '%posx_title%',
                '%posy_title%',
                '%widgetwidth%',
                '%widgetheight%',
                '%column_width_name%',
                '%column_height%',
                '%title_background_width%',
                '%title%'
            ),
            array(
                '1234512348',
                '1234512349',
                $this->xml->sidebar_widget->pos_x,
                $this->xml->sidebar_widget->pos_y,
                $imagex,
                - ($widget_height - 3.3 + 2),
                $re_config['Positions'][$position]['image_open']['image'],
                $iconx,
                $re_config['Positions'][$position]['icon']['y'],
                $this->xml->sidebar_widget->icon_style,
                $this->xml->sidebar_widget->icon_substyle,
                $re_config['Positions'][$position]['title']['halign'],
                $titlex,
                $re_config['Positions'][$position]['title']['y'],
                $re_config['LIVE_RANKINGS'][0]['WIDTH'][0],
                $widget_height + 2,
                ($re_config['LIVE_RANKINGS'][0]['WIDTH'][0] - 6.45),
                ($widget_height - 3.1 + 2),
                ($re_config['LIVE_RANKINGS'][0]['WIDTH'][0] - 0.8),
                $re_config['LIVE_RANKINGS'][0]['TITLE'][0]
            ),
            $header
        );

        // Add Background for top X Players
        if ($topcount > 0) {
            $build['header'] .= '<quad posn="0.4 -2.6 0.003" sizen="' . ($re_config['LIVE_RANKINGS'][0]['WIDTH'][0] - 0.8) . ' ' . (($topcount * $re_config['LineHeight']) + 0.3) . '" style="' . $re_config['STYLE'][0]['WIDGET_RACE'][0]['TOP_STYLE'][0] . '" substyle="' . $re_config['STYLE'][0]['WIDGET_RACE'][0]['TOP_SUBSTYLE'][0] . '"/>';
        }

        $build['footer'] = $footer;
        $build['body'] = '';

        $playerRecs = $this->getPlayerRecs();

        $limit = $this->xml->sidebar_widget->entries;
        if (count($playerRecs) > 0) {
            // Build the entries
            $line = 0;
            $offset = 3;
            foreach ($playerRecs as &$item) {
                $build['body'] .= '<label posn="2.1 -' . ($re_config['LineHeight'] * $line + $offset) . ' 0.002" sizen="1.7 1.7" halign="right" scale="0.9" text="' . $re_config['STYLE'][0]['WIDGET_SCORE'][0]['FORMATTING_CODES'][0] . ($line + 1) . '."/>';
                $build['body'] .= '<label posn="5.7 -' . ($re_config['LineHeight'] * $line + $offset) . ' 0.002" sizen="3.8 1.7" halign="right" scale="0.9" textcolor="' . $re_config['STYLE'][0]['WIDGET_SCORE'][0]['COLORS'][0]['SCORES'][0] . '" text="' . $re_config['STYLE'][0]['WIDGET_SCORE'][0]['FORMATTING_CODES'][0] . $item['points'] . '"/>';
                $build['body'] .= '<label posn="5.9 -' . ($re_config['LineHeight'] * $line + $offset) . ' 0.002" sizen="10.2 1.7" scale="0.9" text="' . $re_config['STYLE'][0]['WIDGET_SCORE'][0]['FORMATTING_CODES'][0] . str_replace('$i', '', $item['nickname']) . '"/>';

                $line++;

                if ($line >= $limit) {
                    break;
                }
            }
            unset($item);
        }

        $xml = $build['header'];
        $xml .= $build['body'];
        $xml .= $build['footer'];


        $this->aseco->client->query("SendDisplayManialinkPage", $xml, 0, false);
    }

    private function getPlayerRecs()
    {
        $q1 = 'SELECT * FROM players';
        $playerList = $this->arrayQuery($q1);

        $sql = 'SELECT * from challenges';
        $res = $this->arrayQuery($sql);
        $pPoints = 0;
        $playerRecs = [];
        foreach ($playerList as $player) {
            $pPoints = 0;
            foreach ($res as $map) {
                $pPoints += $this->getPlayerPointsPerMap($map['Id'], $player['Id']);
            }
            $playerRecs[] = ['nickname' => $player['NickName'], 'login' => $player['Login'], 'points' => $pPoints];
        }

        usort($playerRecs, function ($item1, $item2) {
            if ($item1['points'] == $item2['points']) return 0;
            return $item2['points'] < $item1['points'] ? -1 : 1;
        });

        return $playerRecs;
    }

    private function arrayQuery($query)
    {
        $q = mysql_query($query);
        $error = mysql_error();
        if (strlen($error)) {
            print("Error with war's MYSQL query! " . $error);
            return null;
        }
        while (true) {
            $row = mysql_fetch_assoc($q);
            if (!$row) {
                break;
            }
            $data[] = $row;
        }
        mysql_free_result($q);
        return $data;
    }
}

function war_setup($aseco)
{
    global $warPlugin;
    $warPlugin = new WarPlugin($aseco);
    $warPlugin->setupDb();
    $warPlugin->drawPlayerScoreWidget();
    if ($warPlugin->getWarMode() === 'team') {
        $warPlugin->drawTeamsWidget();
        $warPlugin->drawMapScoreWidget();
    }
    $warPlugin->drawSidebar();
}

function war_playerConnected($aseco, $player)
{
    global $warPlugin;
    $warPlugin->drawPlayerScoreWidget();
    if ($warPlugin->getWarMode() === 'team') {
        $warPlugin->addPlayerToTeam($player);
        $warPlugin->drawTeamsWidget();
        $warPlugin->drawMapScoreWidget();
    }
    $warPlugin->drawSidebar();
}

function war_updateWidgets($aseco, $record)
{
    global $warPlugin;
    $warPlugin->drawPlayerScoreWidget();
    if ($warPlugin->getWarMode() === 'team') {
        $warPlugin->drawTeamsWidget();
        $warPlugin->drawMapScoreWidget();
    }
    $warPlugin->drawSidebar();
}

function war_loadxml($aseco, $tab)
{
    global $warPlugin;
    $warPlugin->loadXmlFile();
}


function war_toggleWindow($aseco, $answer)
{
    global $warPlugin;

    // If id = 0, bail out immediately
    if ($answer[2] == 0) {
        return;
    }

    // Init
    $widgets = '';

    // Get the Player object
    $player = $aseco->server->players->player_list[$answer[1]];

    if ($answer[2] == (int)'1234512349') {

        // Show the All points window
        $widgets .= $warPlugin->drawPointsWindow();
        $warPlugin->sendManialinks($widgets, $player->login);
    }
}


function war_closeAllWindows()
{
    global $re_config;


    $xml  = '<manialink id="' . $re_config['ManialinkId'] . '00"></manialink>';    // MainWindow
    $xml .= '<manialink id="' . $re_config['ManialinkId'] . '01"></manialink>';    // SubWindow
    return $xml;
}

function war_closeAllSubWindows()
{
    global $re_config;

    return '<manialink id="' . $re_config['ManialinkId'] . '01"></manialink>';
}

function chat_war($aseco, $command)
{
    global $warPlugin;
    $params = explode(" ", trim($command["params"]));
    $player = $command['author'];
    $cmd = array_shift($params);

    switch ($cmd) {
        case 'addteam':
            $warPlugin->createTeam($params, $command);
            break;
        case 'addtag':
            $warPlugin->addTag($params, $command);
            break;
        case 'list':
            $warPlugin->listTeams($command);
            break;
        case 'maxpoints':
            $warPlugin->updateSettings($params, $command);
            break;
        case 'resetwar':
            $warPlugin->resetWar($command);
            break;
        case 'addcaptain':
            $warPlugin->addCaptain($params, $command);
            break;
        case 'addplayer':
            $warPlugin->addPlayer($params, $command);
            break;
        case 'removeplayer':
            $warPlugin->removePlayer($params, $command);
            break;
        case 'mode':
            $warPlugin->setMode($params, $command);
            break;
        default:
            $aseco->client->query('ChatSendServerMessageToLogin', $aseco->formatColors('Command not found'), $player->login);
            break;
    }
}
