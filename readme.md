# TMNF War plugin
Plugin for team points system in Trackmania Nations Forever wars. 

### Disclaimer
This was built by drunk **Pensio** and **Oscar** in 2 nights, the code is horrible but it all should work and not slow down the servers. If any issues arise, post an issue here on github and we'll look at it.

## Installation
1. Download the [latest release](https://github.com/rosen91/tmnf-war-plugin/archive/refs/heads/master.zip)
2. Unzip and move `plugin.war.php` into /xaseco/plugins
3. Move war.xml into the root of xaseco
4. Edit `plugins.xml` and include `<plugins>plugin.war.php</plugins>`
5. Restart XAseco

## Usage
![Plugin](https://raw.githubusercontent.com/rosen91/tmnf-war-plugin/master/faintwar.png)

When a player connects to the server, this plugin will try to automatically identify the player and add them to the team that matches the nickname with the team identifier. If no match is found it will say so in chat and you may ask a captain or superadmin to manually add you to the team. 

This plugin adds 4 widgets, 3 at the top that shows the current score by team, and also one sidebar-widget which shows top players by points.

## Commands
```/war mode <mode>```  
Switch the mode between `team` and `all` depending if you want to run in team or all vs all mode. 
*Usable by MasterAdmin*

```/war list```  
Lists all teams in the war with ID, name and identifiers. Example: `1: Faint (Faint, FNT, F)`  
*Usable by everyone*

```/war addteam <teamname> <identifier>```  
Adds team to war. Commands accepts multiple idendifiers in the same line, separate with blank space.  
*Usable by MasterAdmin*

```/war addtags <team id> <identifier>```  
Adds tags to existing teams. Commands accepts multiple idendifiers in the same line, separate with blank space.  
*Usable by MasterAdmin and Captain*

```/war addcaptain <login>```  
Makes a player captain of the team he/she is currently in.  
*Usable by MasterAdmin and Captain*

```/war addplayer <login> <team id>```  
Forces a player into a team regardless of tags.  
*Usable by MasterAdmin and Captain*

```/war maxpoints <number>```  
Set number of players to get points. Default: 10.  
*Usable by MasterAdmin*

```/war resetwar```  
Removes all players and all teams from database.  
*Usable by MasterAdmin*

