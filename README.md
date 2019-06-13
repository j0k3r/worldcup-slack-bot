# Worldcup Slack Bot

WorldCupBot will notify a Slack channel/group for every matches during a FIFA World Cup.

The API haven't changed since the Russia World Cup 2018.
Which means you can use that bot for every FIFA World Cup, you just need to update `ID_COMPETITION` & `ID_SEASON`.

### Find the World Cup you are looking for

World Cup | `ID_COMPETITION` | `ID_SEASON`
------------ | ------------- | -------------
2018 FIFA World Cup Russia™ | 17 | 254645
FIFA U-20 World Cup Poland 2019 | 104 | 281971
FIFA Women's World Cup France 2019™ | 103 | 278513

If the World Cup you are looking for isn't defined below, here is how you can find these numbers:

- go the matches page on the FIFA website, for example `https://www.fifa.com/womensworldcup/matches/`
- edit the source code of the page
- check for these values:
  - `_cfg.competition.idSeason`
  - `_cfg.competition.idCup`

### What it does

That bot is using the "unofficial" FIFA json API (the one used for their mobile apps).

It will post a message :
  - when a match starts
  - for every red/yellow card
  - for the half time and end time
  - and of course, for every goal

### Preview

Here is a preview of the Colombia vs Japan match.

![worldcup-slack-bot sample](https://i.imgur.com/H5kUavh.png)

### Requirements

  - PHP >= 5.3
  - You need a token from Slack:
    - Jump at https://api.slack.com/custom-integrations/legacy-tokens (you have to login)
    - and you will find your token.

### Installation

  - Clone this repo
  - Set up a cron to run every minute:

  ````
  * * * * * cd /path/to/folder && php worldCupNotifier.php >> worldCupNotifier.log
  ````

### Side notes

The code is ugly but it works.

Everything is posted in french, but feel free to fork and use your own language. FYI, FIFA API can provide text in en/fr/de/es/pt.
