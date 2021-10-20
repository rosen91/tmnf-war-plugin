<?php

Aseco::registerEvent('onSync', 'war_setup');
Aseco::registerEvent('onPlayerConnect', 'war_playerConnected');
Aseco::registerEvent('onPlayerDisconnect', 'war_playerDisconnected');
Aseco::registerEvent('onLocalRecord', 'war_updateWidgets');
Aseco::registerEvent('onPlayerManialinkPageAnswer', 'war_toggleWindow');
Aseco::registerEvent('onNewChallenge2', 'war_onNewChallenge');
Aseco::registerEvent('onEndRace', 'war_onEndRace');
Aseco::registerEvent('onTracklistChanged', 'war_onTracklistChanged');
Aseco::addChatCommand('war', 'Manage war commands');


class WarPlayer
{
    public $login;
    public $id;
    public $showWidgets;
}

class WarPlugin
{
    public $aseco;
    public $warmode = null;
    public $xml = null;
    public $manialinkPrefix = '12345123';
    public $manialinks = ['sidebar_widget' => '01', 'sidebar_widget_window' => '02', 'mapscore_widget' => '03', 'teamscore_widget' => '04', 'yourscore_widget' => '05'];
    public $chatPrefix = '$f33[$fffWAR$f33]$fff - ';
    public $widgetsVisible = true;
    public $playerToggledWidgets = true;
    public $lastRedrawn = null;
    public $tracklist = [];
    public $tracklistString = '';
    public $playerList = [];
    public $onlinePlayerList = [];
    public $warTeams = [];

    public function __construct($aseco)
    {
        $this->aseco = $aseco;
        $this->loadXmlFile();
    }

    public function setAseco($aseco)
    {
        $this->aseco = $aseco;
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

    public function fetchInitialData()
    {
        // Get the Challenge List from Server
        $this->aseco->client->resetError();
        $this->aseco->client->query('GetChallengeList', 5000, 0);
        $trackinfos = $this->aseco->client->getResponse();
        $trackUIds = array_column($trackinfos, 'UId');
        $mapsString = "'" . implode("','", $trackUIds) . "'";

        $sql = 'SELECT * from challenges where Uid IN (' . $mapsString . ')';
        $res = $this->arrayQuery($sql);

        $trackIds = array_column($res, 'Id');
        $trackIdsString = "'" . implode("','", $trackIds) . "'";
        $this->tracklist = $res;
        $this->tracklistString = $trackIdsString;

        $playerQuery = 'SELECT distinct p.* from players p LEFT JOIN records r on p.Id = r.PlayerId WHERE r.ChallengeId IN (' . $trackIdsString . ')';
        $players = $this->arrayQuery($playerQuery);
        $this->playerList = $players;
        $this->playerListIds = array_column($players, 'Id');

        $this->updateTeams();
    }

    private function updateTeams()
    {
        $query = 'SELECT * FROM war_teams';
        $res = $this->arrayQuery($query);
        $this->warTeams = $res;
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
        if ($this->isMasterAdmin($player)) {
            return true;
        }

        $sql = 'SELECT * from players where Login="' . $player->login . '" LIMIT 1';
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
                `team_short` varchar(200) NOT NULL default '',
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

        $hasTeamShortQuery = mysql_query('SHOW COLUMNS FROM `war_teans`;');
        while ($row = mysql_fetch_row($result)) {
            $fields[] = $row[0];
        }
        mysql_free_result($result);

        // Add `timezone` column if not yet done
        if (!in_array('team_short', $fields)) {
            $this->aseco->console('   + Adding column `team_short` at table `war_teams`.');
            mysql_query('ALTER TABLE `war_teams` ADD `team_short` VARCHAR(200) CHARACTER SET utf8 COLLATE utf8_bin NULL DEFAULT "" COMMENT "Added by plugin.war.php";');
        }
    }

    public function addShort($params, $command)
    {
        $player = $command['author'];
        if (!$this->isCaptainOrMasterAdmin($player)) {
            $this->aseco->client->query('ChatSendServerMessageToLogin', $this->aseco->formatColors($this->chatPrefix . 'You don’t have permission to use that command.'), $player->login);
            return;
        }

        $team = array_shift($params);
        $shortName = $params[0];

        $query = 'UPDATE war_teams set team_short="' . $shortName . '" where id=' . $team . '';

        $result = mysql_query($query);

        $this->updateTeams();
        $this->redrawWidgets(null, true);

        $this->aseco->client->query('ChatSendServerMessageToLogin', $this->aseco->formatColors($this->chatPrefix . 'Team: ' . $team . '$z$s$fff has the new short name: ' . $shortName . ''), $player->login);
    }

    public function redrawWidgets($playerObject, $force = false)
    {
        if ($this->widgetsVisible === false || $this->aseco->startup_phase) {
            return;
        }

        if ($this->lastRedrawn && !$force) {
            if (time() - $this->lastRedrawn < 1) {
                $timePassed = time() - $this->lastRedrawn;
                return;
            }
        }
        $xml = '';

        $mapScore = $this->calculateMapScore();
        $teamScore = $this->calculateTeamScore();
        $playerRecs = $this->getPlayerRecs();
        foreach ($this->onlinePlayerList as $player) {
            if ($player->showWidgets) {

                $this->sendManialinks($this->drawPlayerScoreWidget($player), $player->login);
                $xml .= $this->drawSidebar($player, $playerRecs);
                if ($this->getWarMode() === 'team') {
                    $xml .= $this->drawMapScoreWidget($player, $mapScore);
                    $xml .= $this->drawTeamsWidget($player, $teamScore);
                }
                $this->sendManialinks($xml, $player->login);
            }
        }

        $this->lastRedrawn = time();
    }

    private function calculateTeamScore()
    {
        $teams = [];
        if (count($this->warTeams)) {
            foreach ($this->warTeams as $i => $team) {
                $teams[$i]['team_name'] = $team['team_name'];
                $teams[$i]['score'] = 0;
                $teamPlayerQuery = 'SELECT * FROM war_team_players where team_id=' . $team['Id'];
                $teamPlayers = $this->arrayQuery($teamPlayerQuery);
                if (count($teamPlayers) > 0) {
                    foreach ($this->tracklist as $map) {
                        foreach ($teamPlayers as $player) {
                            $teams[$i]['score'] += $this->getPlayerPointsPerMap($map['Id'], $player['player_id']);
                        }
                    }
                }
            }
        }

        return $teams;
    }

    private function calculateMapScore()
    {
        $teams = [];
        if (count($this->warTeams)) {
            foreach ($this->warTeams as $i => $team) {
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
        }

        return $teams;
    }

    public function toggleWidgets($player)
    {
        if (!$this->widgetsVisible) {
            return;
        }

        foreach ($this->onlinePlayerList as $onlinePlayer) {
            if ($onlinePlayer->login === $player->login) {
                if ($onlinePlayer->showWidgets === true) {
                    $onlinePlayer->showWidgets = false;
                    $this->hideWidgets($player);
                } else {
                    $onlinePlayer->showWidgets = true;
                    $this->showWidgets($player);
                }
            }
        }
    }

    public function hideWidgets($player)
    {
        $xml = '';
        foreach ($this->manialinks as $key => $manialink) {
            $xml .= '<manialink id="' . $this->manialinkPrefix . $manialink . '"></manialink>';
        }
        $this->sendManialinks($xml, $player->login);
    }

    public function showWidgets($player)
    {
        $this->redrawWidgets($player);
    }

    public function addOnlinePlayer($player)
    {
        $warPlayer = new WarPlayer;
        $warPlayer->login = $player->login;
        $warPlayer->id = $player->id;
        $warPlayer->showWidgets = true;

        $this->onlinePlayerList[] = $warPlayer;
    }

    public function removeOnlinePlayer($player)
    {
        foreach ($this->onlinePlayerList as $key => $onlinePlayer) {
            if ($onlinePlayer->login === $player->login) {
                unset($this->onlinePlayerList[$key]);
            }
        }
    }

    public function createTeam($params, $command)
    {
        $player = $command['author'];
        if (!$this->isCaptainOrMasterAdmin($player)) {
            $this->aseco->client->query('ChatSendServerMessageToLogin', $this->aseco->formatColors($this->chatPrefix . 'You don’t have permission to use that command.'), $player->login);
            return;
        }

        $team = array_shift($params);
        $identifiers = $params;

        $query = 'INSERT INTO `war_teams` (team_identifiers, team_name) VALUES (' . quotedString(implode(",", $identifiers)) . ',' . quotedString($team) . ')';

        $result = mysql_query($query);

        $this->updateTeams();
        $this->redrawWidgets(null, true);

        $this->aseco->client->query('ChatSendServerMessageToLogin', $this->aseco->formatColors($this->chatPrefix . 'Team: ' . $team . '$z$s$fff added, with identifiers: ' . implode(', ', $identifiers)), $player->login);
    }

    public function addTag($params, $command)
    {
        $player = $command['author'];
        if (!$this->isCaptainOrMasterAdmin($player)) {
            $this->aseco->client->query('ChatSendServerMessageToLogin', $this->aseco->formatColors($this->chatPrefix . 'You don’t have permission to use that command.'), $player->login);
            return;
        }

        $teamId = array_shift($params);
        $newIdentifiers = $params;

        $sql = 'SELECT * from war_teams where Id=' . $teamId . ' LIMIT 1';
        $res = $this->arrayQuery($sql);
        if (count($res) > 0) {
            $oldIdentifiers = $res[0]['team_identifiers'];
            $team = $res[0]['team_name'];
            $identifiers = $oldIdentifiers . ',' . implode(',', $newIdentifiers);
            $query = 'UPDATE `war_teams` set team_identifiers = ' . quotedString($identifiers) . ' WHERE Id=' . $teamId;
            $result = mysql_query($query);
            $msg = $this->chatPrefix . 'Identifiers: ' . implode(', ', $newIdentifiers) . ' added to team: ' . $team;
        } else {
            $msg = $this->chatPrefix . 'No team found with that ID';
        }

        $this->updateTeams();
        $this->aseco->client->query('ChatSendServerMessageToLogin', $this->aseco->formatColors($msg), $player->login);
    }

    public function getPlayerPointsPerMap($challengeId, $playerId)
    {
        $query1 = 'SELECT * FROM war_settings';
        $resQ1 = $this->arrayQuery($query1);


        $sql1 = 'SELECT r.PLayerID as player_id, r.Score as score From records r left join challenges c on r.ChallengeId = c.Id where r.ID IS NOT NULL AND c.Id=' . $challengeId . ' ORDER BY Score ASC, Date ASC';
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

    public function drawPlayerScoreWidget($player)
    {
        if ($this->xml->your_score_widget->enabled == false || $this->xml->your_score_widget->enabled == 'false' || $player->showWidgets === false) {
            return;
        }

        $playerPoint = 0;
        $pPoints = 0;
        $currentMapId = $this->aseco->server->challenge->id;
        foreach ($this->tracklist as $map) {
            $points = $this->getPlayerPointsPerMap($map['Id'], $player->id);
            $pPoints += $points;
            if ($map['Id'] == $currentMapId) {
                $playerPoint = $points;
            }
        }
        $playerScoreWidgetHeight = 7;

        $xml = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<manialink id="' . $this->manialinkPrefix . $this->manialinks['yourscore_widget'] . '">';
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

        return $xml;
    }

    public function drawMapScoreWidget($player, $teams = [])
    {
        if ($this->xml->map_score_widget->enabled == false || $this->xml->map_score_widget->enabled == 'false' || $player->showWidgets === false) {
            return;
        }
        $boxHeight = 3;

        // Team score widgeten
        $boxHeight = (count($this->warTeams) * 2) + $boxHeight;
        $xml = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<manialink id="' . $this->manialinkPrefix . $this->manialinks['mapscore_widget'] . '">';
        $xml .= '<frame posn="' . $this->xml->map_score_widget->pos_x . ' ' . $this->xml->map_score_widget->pos_y . '">';
        $xml .= '<format textsize="0.5"/>';

        $xml .= '<quad  posn="0 0" sizen="10 ' . $boxHeight . '" halign="center" valign="top" style="BgsPlayerCard" substyle="ProgressBar" />';

        $xml .= '<label posn="0 -1" sizen="10 2" halign="center" valign="top" text="$o' . $this->xml->map_score_widget->title . '"/>';
        $posY = -2.6;
        if (count($this->warTeams)) {
            foreach ($teams as $team) {
                $xml .= '<label posn="-4.3 ' . $posY . '" sizen="10 2" halign="left" textsize="1.2" valign="top" text="' . $team['team_name'] . ':"/>';
                $xml .= '<label posn="4.3 ' . $posY . '" sizen="10 2" halign="right" textsize="1.2" valign="top" text="$fff' . $team['score'] . '"/>';
                $posY = $posY - 2;
            }
        }

        $xml .= '</frame></manialink>';

        return $xml;
    }

    public function drawTeamsWidget($player, $teams)
    {
        if ($this->xml->war_score_widget->enabled == false || $this->xml->war_score_widget->enabled == 'false' || $player->showWidgets === false) {
            return;
        }
        $boxHeight = 3;

        // Team score widgeten
        $boxHeight = (count($this->warTeams) * 2) + $boxHeight;
        $xml = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<manialink id="' . $this->manialinkPrefix . $this->manialinks['teamscore_widget'] . '">';
        $xml .= '<frame posn="' . $this->xml->war_score_widget->pos_x . ' ' . $this->xml->war_score_widget->pos_y . '">';
        $xml .= '<format textsize="0.5"/>';

        $xml .= '<quad  posn="0 0" sizen="10 ' . $boxHeight . '" halign="center" valign="top" style="BgsPlayerCard" substyle="ProgressBar" />';

        $xml .= '<label posn="0 -1" sizen="10 2" halign="center" valign="top" text="$o' . $this->xml->war_score_widget->title . '"/>';
        $posY = -2.6;
        if (count($this->warTeams)) {
            foreach ($teams as $team) {
                $xml .= '<label posn="-4.3 ' . $posY . '" sizen="10 2" halign="left" textsize="1.2" valign="top" text="' . $team['team_name'] . ':"/>';
                $xml .= '<label posn="4.3 ' . $posY . '" sizen="10 2" halign="right" textsize="1.2" valign="top" text="$fff' . $team['score'] . '"/>';
                $posY = $posY - 2;
            }
        }

        $xml .= '</frame></manialink>';

        return $xml;
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
            $this->aseco->client->query('ChatSendServerMessageToLogin', $this->aseco->formatColors($this->chatPrefix . 'You don’t have permission to use that command.'), $player->login);
            return;
        }

        $query = 'DELETE FROM `war_team_players`';
        mysql_query($query);

        $query1 = 'DELETE FROM `war_teams`';
        mysql_query($query1);

        $msg = $this->chatPrefix . 'War has been reset';

        $this->updateTeams();
        $this->fetchInitialData();

        $this->aseco->client->query('ChatSendServerMessageToLogin', $this->aseco->formatColors($msg), $player->login);
    }

    public function listTeams($command)
    {
        $player = $command['author'];
        if (count($this->warTeams)) {
            $msg = $this->chatPrefix . 'This is the registered teams: ';
            foreach ($this->warTeams as $team) {
                $msg .= $team['Id'] . ': ' .  $team['team_name'] . '$z$s$fff (' . $team['team_identifiers'] . '), ';
            }

            $msg = rtrim($msg, ', ');

            $this->aseco->client->query('ChatSendServerMessageToLogin', $this->aseco->formatColors($msg), $player->login);
        } else {
            $this->aseco->client->query('ChatSendServerMessageToLogin', $this->aseco->formatColors($this->chatPrefix . 'No registered teams'), $player->login);
        }
    }

    public function addPlayer($params, $command)
    {
        $player = $command['author'];
        if (!$this->isCaptainOrMasterAdmin($player)) {
            $this->aseco->client->query('ChatSendServerMessageToLogin', $this->aseco->formatColors($this->chatPrefix . 'You don’t have permission to use that command.'), $player->login);
            return;
        }

        $playerQuery = 'SELECT * from players where Login="' . $params[0] . '" LIMIT 1';
        $res = $this->arrayQuery($playerQuery);
        if (count($res)) {
            $del = 'DELETE from war_team_players WHERE player_id=' . $res[0]['Id'];
            mysql_query($del);

            $insert = 'INSERT INTO war_team_players (player_id, team_id) VALUES (' . $res[0]['Id'] . ',' . $params[1] . ')';
            mysql_query($insert);

            $teamQuery = 'SELECT * from war_teams where Id=' . $params[1] . ' LIMIT 1';
            $teamRes = $this->arrayQuery($teamQuery);
            if (count($teamRes) === 0) {
                $msg = $this->chatPrefix . 'No team found with that ID';
            } else {
                $msg = $this->chatPrefix . $res[0]['NickName'] . '$z$s$fff has been added to ' . $teamRes[0]['team_name'];
            }
            $this->aseco->client->query('ChatSendServerMessage', $this->aseco->formatColors($msg));
        } else {
            $msg = $this->chatPrefix . 'No player found with that login';
            $this->aseco->client->query('ChatSendServerMessageToLogin', $this->aseco->formatColors($msg), $player->login);
        }
    }

    public function removePlayer($params, $command)
    {
        $player = $command['author'];
        if (!$this->isCaptainOrMasterAdmin($player)) {
            $this->aseco->client->query('ChatSendServerMessageToLogin', $this->aseco->formatColors($this->chatPrefix . 'You don’t have permission to use that command.'), $player->login);
            return;
        }

        $playerQuery = 'SELECT * from players where Login="' . $params[0] . '"';
        $res = $this->arrayQuery($playerQuery);
        if (count($res)) {
            $teamQuery = 'SELECT war_teams.team_name as team_name, war_team_players.player_id as player_id from war_team_players join war_teams on war_team_players.team_id=war_teams.Id where war_team_players.player_id=' . $res[0]['Id'];
            $teamRes = $this->arrayQuery($teamQuery);

            $del = 'DELETE from war_team_players WHERE player_id=' . $res[0]['Id'];
            mysql_query($del);

            //  ‘ . $player[‘nickname’] . ‘ has been removed from ‘ . $team[‘team_name’]

            $msg = $this->chatPrefix . $res[0]['NickName'] . '$z$s$fff has been removed from team ' . $teamRes[0]['team_name'];
        } else {
            $msg = $this->chatPrefix . 'No player found with that login';
        }

        $this->aseco->client->query('ChatSendServerMessageToLogin', $this->aseco->formatColors($msg), $player->login);
    }

    public function addCaptain($params, $command)
    {
        $player = $command['author'];
        if (!$this->isCaptainOrMasterAdmin($player)) {
            $this->aseco->client->query('ChatSendServerMessageToLogin', $this->aseco->formatColors($this->chatPrefix . 'You don’t have permission to use that command.'), $player->login);
            return;
        }
        $playerQuery = 'SELECT * from players where Login="' . $params[0] . '"';
        $res = $this->arrayQuery($playerQuery);
        if (count($res)) {

            $insert = 'UPDATE war_team_players set level=1 where player_id =' . $res[0]['Id'] . '';
            mysql_query($insert);

            $teamQuery = 'SELECT war_teams.team_name as team_name, war_team_players.player_id as player_id from war_team_players join war_teams on war_team_players.team_id=war_teams.Id where war_team_players.player_id=' . $res[0]['Id'];
            $teamRes = $this->arrayQuery($teamQuery);
            if (count($teamRes)) {
                $msg = $this->chatPrefix . $res[0]['NickName'] . '$z$s$fff has been added as captain for ' . $teamRes[0]['team_name'];
                $this->aseco->client->query('ChatSendServerMessage', $this->aseco->formatColors($msg));
            } else {
                $msg = $this->chatPrefix . 'Player does not belong to a team';
                $this->aseco->client->query('ChatSendServerMessageToLogin', $this->aseco->formatColors($msg), $player->login);
            }
        } else {
            $msg = $this->chatPrefix . 'No player found with that login';
            $this->aseco->client->query('ChatSendServerMessageToLogin', $this->aseco->formatColors($msg), $player->login);
        }
    }

    public function showPlayerInfo($params, $command)
    {
        $player = $command['author'];
        if (!$this->isCaptainOrMasterAdmin($player)) {
            $this->aseco->client->query('ChatSendServerMessageToLogin', $this->aseco->formatColors($this->chatPrefix . 'You don’t have permission to use that command.'), $player->login);
            return;
        }
        $playerQuery = 'SELECT * from players where Login="' . $params[0] . '"';
        $res = $this->arrayQuery($playerQuery);
        if (count($res)) {

            $teamQuery = 'SELECT war_teams.team_name as team_name, war_team_players.player_id as player_id from war_team_players join war_teams on war_team_players.team_id=war_teams.Id where war_team_players.player_id=' . $res[0]['Id'];
            $teamRes = $this->arrayQuery($teamQuery);
            if (count($teamRes)) {
                $msg = $this->chatPrefix . $res[0]['NickName'] . '$z$s$fff with login: ' . $res[0]['Login'] . ' is in team: ' . $teamRes[0]['team_name'];
                $this->aseco->client->query('ChatSendServerMessage', $this->aseco->formatColors($msg));
            } else {
                $msg = $this->chatPrefix . 'Player does not belong to a team';
                $this->aseco->client->query('ChatSendServerMessageToLogin', $this->aseco->formatColors($msg), $player->login);
            }
        } else {
            $msg = $this->chatPrefix . 'No player found with that login';
            $this->aseco->client->query('ChatSendServerMessageToLogin', $this->aseco->formatColors($msg), $player->login);
        }
    }

    public function updateSettings($params, $command)
    {
        $player = $command['author'];
        if (!$this->isMasterAdmin($player)) {
            $this->aseco->client->query('ChatSendServerMessageToLogin', $this->aseco->formatColors($this->chatPrefix . 'You don’t have permission to use that command.'), $player->login);
            return;
        }
        $max = array_shift($params);
        $sql = 'DELETE from war_settings';
        mysql_query($sql);

        $sql1 = 'INSERT INTO war_settings (max_point_positions) VALUES (' . $max . ')';
        mysql_query($sql1);
        $msg = $this->chatPrefix . 'War settings updated, top ' . $max . ' players recieve points';
        $this->aseco->client->query('ChatSendServerMessage', $this->aseco->formatColors($msg));
    }

    public function addPlayerToTeam($player)
    {
        $query = 'SELECT war_teams.team_name as team_name, war_team_players.player_id as player_id from war_team_players join war_teams on war_team_players.team_id=war_teams.Id where war_team_players.player_id=' . $player->id;
        $res = $this->arrayQuery($query);
        if (count($res) > 0) {
            $msg = $this->chatPrefix . 'Welcome ' . $player->nickname . '$z$s$fff! You are not alone, you belong to ' . $res[0]['team_name'];
            $this->aseco->client->query('ChatSendServerMessageToLogin', $msg, $player->login);
        } else {
            $matchedTeam = null;
            $nickname = $player->nickname;
            if (count($this->warTeams)) {
                foreach ($this->warTeams as $team) {
                    $ids = explode(",", $team['team_identifiers']);
                    foreach ($ids as $id) {
                        if (strpos($nickname, $id) !== false) {
                            $matchedTeam = $team;
                        }
                    }
                }
            }

            if ($matchedTeam === null) {
                $msg = $this->chatPrefix . 'No team matches your nickname, ask an admin or team captain to manually add you or change nickname';
                $this->aseco->client->query('ChatSendServerMessageToLogin', $this->aseco->formatColors($msg), $player->login);
            } else {
                $query = 'INSERT INTO war_team_players (player_id, team_id) VALUES (' . $player->id . ',' . $matchedTeam['Id'] . ')';
                mysql_query($query);

                $msg = $this->chatPrefix . 'You were added to team: ' . $matchedTeam['team_name'];
                $this->aseco->client->query('ChatSendServerMessageToLogin', $this->aseco->formatColors($msg), $player->login);
            }
        }
    }

    public function setMode($params, $command)
    {
        $player = $command['author'];
        if (!$this->isMasterAdmin($player)) {
            $this->aseco->client->query('ChatSendServerMessageToLogin', $this->aseco->formatColors($this->chatPrefix . 'You don’t have permission to use that command.'), $player->login);
            return;
        }

        $mode = array_shift($params);

        if ($mode === 'team' || $mode === 'all') {
            $sql = 'UPDATE war_settings set war_mode="' . $mode . '"';
            mysql_query($sql);
            $this->warmode = $mode;
            $this->aseco->client->query('ChatSendServerMessage', $this->aseco->formatColors($this->chatPrefix . 'War mode updated'));

            if ($mode === 'all') {
                $xml = '';
                $xml .= '<manialink id="' . $this->manialinkPrefix . $this->manialinks['teamscore_widget'] . '"></manialink>';
                $xml .= '<manialink id="' . $this->manialinkPrefix . $this->manialinks['mapscore_widget'] . '"></manialink>';

                $this->aseco->client->query("SendDisplayManialinkPage", $xml, 0, false);
                $this->redrawWidgets(null);
            } else {
                $this->redrawWidgets(null);
            }
        } else {
            $this->aseco->client->query('ChatSendServerMessageToLogin', $this->aseco->formatColors($this->chatPrefix . 'That mode does not exist'), $player->login);
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

    public function drawSidebar($player, $playerRecs)
    {
        global $re_config;

        if ($this->xml->sidebar_widget->enabled == false || $this->xml->sidebar_widget->enabled == 'false' || $player->showWidgets === false) {
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
        $widget_height = ($re_config['LineHeight'] * (int) $this->xml->sidebar_widget->entries + 1.3);

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
                $this->manialinkPrefix . $this->manialinks['sidebar_widget'],
                $this->manialinkPrefix . $this->manialinks['sidebar_widget_window'],
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


        return $xml;
    }

    private function getPlayerRecs()
    {
        $playerList = $this->playerList;

        $pPoints = 0;
        $playerRecs = [];
        foreach ($playerList as $player) {
            $pPoints = 0;
            foreach ($this->tracklist as $map) {
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

    public function arrayQuery($query)
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
    $warPlugin->fetchInitialData();

    $warPlugin->redrawWidgets(null);
}

function war_playerConnected($aseco, $player)
{
    global $warPlugin;
    $warPlugin->addOnlinePlayer($player);
    $warPlugin->addPlayerToTeam($player);
    $time_start = microtime(true);
    $warPlugin->redrawWidgets($player, true);
    echo 'redrawWidgets: ' . (microtime(true) - $time_start);
}

function war_playerDisconnected($aseco, $player)
{
    global $warPlugin;
    $warPlugin->removeOnlinePlayer($player);
}
function war_updateWidgets($aseco, $record)
{
    global $warPlugin;
    $warPlugin->redrawWidgets(null);
}

function war_onNewChallenge($aseco, $tab)
{
    global $warPlugin;
    $warPlugin->setAseco($aseco);
    $warPlugin->fetchInitialData();
    $warPlugin->showWidgets(null);
    $time_start = microtime(true);
    $warPlugin->redrawWidgets(null, true);
    echo 'war_onNewChallenge_redrawWidgets: ' . (microtime(true) - $time_start);
}

function war_onEndRace($aseco, $race)
{
    global $warPlugin;
    $warPlugin->hideWidgets(null);
}

function war_onTracklistChanged($aseco, $command)
{
    global $warPlugin;
    $warPlugin->fetchInitialData();
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
    //$aseco->client->query('ChatSendServerMessageToLogin', 'test ' . $answer[2], $player->login);

    if ($answer[2] == 382009003) {

        $warPlugin->toggleWidgets($player);
    } else  if ($answer[2] == (int) $warPlugin->manialinkPrefix . $warPlugin->manialinks['sidebar_widget_window']) {
        // Show the All points window
        $widgets .= $warPlugin->drawPointsWindow();
        $warPlugin->sendManialinks($widgets, $player->login);
    }
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
        case 'addtags':
            $warPlugin->addTag($params, $command);
            break;
        case 'addshort':
            $warPlugin->addShort($params, $command);
            break;
        case 'list':
            $warPlugin->listTeams($command);
            break;
        case 'playerinfo':
            $warPlugin->showPlayerInfo($params, $command);
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
